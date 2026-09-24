<?php

namespace App\Services\Sms;

/**
 * SMS provider boundary. Phase 4 adds a real provider; until then LogSmsSender only logs.
 */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
