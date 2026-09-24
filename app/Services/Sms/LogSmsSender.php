<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        // Mask the number: logs are not the place for full personal data.
        Log::info('SMS (simulated)', ['to' => substr($to, 0, 4).'*****'.substr($to, -2), 'message' => $message]);
    }
}
