<?php

namespace App\Services\PayMongo;

/**
 * Paymongo-Signature: t=<unix time>,te=<test HMAC>,li=<live HMAC>
 * HMAC-SHA256 over "{t}.{raw body}" with the webhook secret. Compared with hash_equals.
 */
class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly bool $livemode,
        private readonly int $toleranceSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('paymongo.webhook_secret'),
            (bool) config('paymongo.livemode'),
            (int) config('paymongo.webhook_tolerance'),
        );
    }

    public function isValid(?string $header, string $rawBody, ?int $now = null): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = $this->parse($header);
        $timestamp = $parts['t'] ?? '';
        $signature = $parts[$this->livemode ? 'li' : 'te'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($timestamp, $rawBody), $signature);
    }

    public function sign(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    /**
     * Header value for a locally built payload (used by paymongo:simulate-webhook and tests).
     */
    public function header(string $rawBody, ?int $timestamp = null): string
    {
        $t = (string) ($timestamp ?? time());
        $sig = $this->sign($t, $rawBody);

        return $this->livemode ? "t={$t},te=,li={$sig}" : "t={$t},te={$sig},li=";
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        return $parts;
    }
}
