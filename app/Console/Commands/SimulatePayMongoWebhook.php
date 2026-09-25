<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\PayMongo\PaidCheckoutEvent;
use App\Services\PayMongo\WebhookSignature;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Local development only: PayMongo cannot reach localhost, so this builds a correctly signed
 * checkout_session.payment.paid event and posts it to this app's own webhook route.
 */
class SimulatePayMongoWebhook extends Command
{
    protected $signature = 'paymongo:simulate-webhook
        {reference : Reservation reference code, e.g. SWA-ABCD2345}
        {--amount= : Amount paid in pesos (defaults to the downpayment)}
        {--payment-id= : Reuse a PayMongo payment id, to test duplicate delivery}
        {--reference-override= : Send a reference_number that matches no reservation, to test orphan payments}';

    protected $description = 'Send a signed test "payment paid" webhook for a reservation (local only)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('paymongo:simulate-webhook only runs in the local environment.');

            return self::FAILURE;
        }

        $reservation = Reservation::query()->where('reference_code', $this->argument('reference'))->first();

        if (! $reservation) {
            $this->error('No reservation with that reference code.');

            return self::FAILURE;
        }

        $amount = $this->option('amount') !== null ? Money::of($this->option('amount')) : $reservation->downpayment_amount;
        $paymentId = $this->option('payment-id') ?: 'pay_sim'.Str::lower(Str::random(20));

        $payload = self::payload($reservation, $paymentId, Money::toCentavos($amount));

        if ($override = $this->option('reference-override')) {
            $payload['data']['attributes']['data']['attributes']['reference_number'] = (string) $override;
        }

        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $url = route('webhooks.paymongo');

        $response = Http::withHeaders([
            'Paymongo-Signature' => WebhookSignature::fromConfig()->header($raw),
            'Content-Type' => 'application/json',
        ])->withBody($raw, 'application/json')->timeout(10)->post($url);

        $this->line("POST {$url} → {$response->status()} ".$response->body());
        $this->info("Payment id: {$paymentId}");

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Mirrors the documented event shape (data.attributes.type / data.attributes.data).
     *
     * @return array<string, mixed>
     */
    public static function payload(Reservation $reservation, string $paymentId, int $amountCentavos): array
    {
        return [
            'data' => [
                'id' => 'evt_sim'.Str::lower(Str::random(20)),
                'type' => 'event',
                'attributes' => [
                    'type' => PaidCheckoutEvent::TYPE,
                    'livemode' => false,
                    'data' => [
                        'id' => $reservation->paymongo_checkout_id ?? 'cs_sim'.Str::lower(Str::random(20)),
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => $reservation->reference_code,
                            'metadata' => ['reservation_id' => (string) $reservation->id],
                            'payments' => [[
                                'id' => $paymentId,
                                'type' => 'payment',
                                'attributes' => ['amount' => $amountCentavos, 'currency' => 'PHP', 'status' => 'paid'],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
