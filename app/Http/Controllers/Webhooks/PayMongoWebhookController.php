<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\ConfirmPaymentService;
use App\Services\PayMongo\PaidCheckoutEvent;
use App\Services\PayMongo\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, ConfirmPaymentService $confirm): JsonResponse
    {
        // Verify the signature on the raw bytes before anything is parsed.
        $raw = $request->getContent();
        $signature = WebhookSignature::fromConfig();

        if (! $signature->hasUsableSecret()) {
            Log::critical('PayMongo webhook rejected: PAYMONGO_WEBHOOK_SECRET is missing or still the placeholder.');

            return response()->json(['message' => 'Webhook not configured.'], 400);
        }

        if (! $signature->isValid($request->header('Paymongo-Signature'), $raw)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        if (app()->environment('local') && Cache::add('paymongo.first_payload_logged', true, now()->addDay())) {
            Log::info('First PayMongo webhook payload (local only).', $payload);
        }

        $type = PaidCheckoutEvent::eventType($payload);

        // Any other event is acknowledged so PayMongo does not retry it.
        if ($type !== PaidCheckoutEvent::TYPE) {
            return response()->json(['result' => 'ignored']);
        }

        $event = PaidCheckoutEvent::fromPayload($payload);

        if (! $event) {
            // A paid event we cannot read must not be silently dropped: fail so it shows in the
            // PayMongo dashboard and is retried while the parser is fixed.
            // Log identifiers only: the session can carry billing name, email, phone and address.
            $session = data_get($payload, 'data.attributes.data') ?? data_get($payload, 'data.data');
            Log::error('Unreadable PayMongo paid event.', [
                'event_id' => data_get($payload, 'data.id'),
                'session_id' => data_get($session, 'id'),
                'reference' => data_get($session, 'attributes.reference_number'),
                'session_keys' => is_array($session) ? array_keys((array) data_get($session, 'attributes', [])) : null,
            ]);

            return response()->json(['message' => 'Unreadable paid event.'], 422);
        }

        return response()->json(['result' => $confirm->handlePaid($event)]);
    }
}
