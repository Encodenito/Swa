<?php

namespace App\Services\PayMongo;

use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client for PayMongo (docs.paymongo.com). Never call these methods inside a DB transaction.
 * Amounts cross this boundary as integer centavos; everything else in the app uses Money strings.
 */
class PayMongoService
{
    /**
     * @return array{id: string, checkout_url: string}
     *
     * @throws PayMongoException
     */
    public function createCheckout(Reservation $reservation): array
    {
        $response = $this->send(fn () => $this->client()->post(config('paymongo.checkout_path'), [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => "Downpayment - {$reservation->reference_code}",
                        'amount' => Money::toCentavos($reservation->downpayment_amount),
                        'currency' => 'PHP',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => config('paymongo.payment_method_types'),
                    'reference_number' => $reservation->reference_code,
                    'description' => "SWA I.T. Supplies reservation {$reservation->reference_code}",
                    'send_email_receipt' => false,
                    'show_line_items' => true,
                    'success_url' => route('account.reservations.return', $reservation),
                    'cancel_url' => route('account.reservations.show', $reservation),
                    'metadata' => ['reservation_id' => (string) $reservation->id],
                ],
            ],
        ]));

        $id = data_get($response, 'data.id');
        $url = data_get($response, 'data.attributes.checkout_url');

        if (! is_string($id) || ! is_string($url)) {
            throw new PayMongoException('PayMongo returned a checkout session without an id or URL.');
        }

        // Customers are redirected here, so only PayMongo's own hosted checkout is accepted.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, config('paymongo.checkout_hosts'), true)) {
            throw new PayMongoException('PayMongo returned an unexpected checkout URL.', 'unexpected_checkout_url');
        }

        return ['id' => $id, 'checkout_url' => $url];
    }

    /**
     * Best effort: close an unpaid session so the customer cannot pay after the reservation expired.
     */
    public function expireCheckout(string $checkoutId): void
    {
        try {
            $this->send(fn () => $this->client()->post(config('paymongo.checkout_path')."/{$checkoutId}/expire"));
        } catch (PayMongoException $e) {
            Log::warning('Could not expire PayMongo checkout session.', ['checkout_id' => $checkoutId, 'error' => $e->getMessage()]);
        }
    }

    private function client(): PendingRequest
    {
        $secret = (string) config('paymongo.secret_key');

        if (! config('paymongo.livemode') && ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('PayMongo test mode requires an sk_test_ secret key.');
        }

        return Http::baseUrl(config('paymongo.base_url'))
            ->withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout(config('paymongo.timeout'));
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     *
     * @throws PayMongoException
     */
    private function send(callable $request): array
    {
        try {
            return $request()->throw()->json() ?? [];
        } catch (RequestException $e) {
            $code = data_get($e->response->json(), 'errors.0.code', 'http_'.$e->response->status());
            throw new PayMongoException("PayMongo request failed: {$code}", (string) $code, $e);
        } catch (ConnectionException $e) {
            throw new PayMongoException('Could not reach PayMongo.', 'connection_error', $e);
        }
    }
}
