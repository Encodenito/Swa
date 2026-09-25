<?php

namespace App\Console\Commands;

use App\Models\ChatHandoff;
use App\Services\ChatHandoffService;
use Illuminate\Console\Command;

/**
 * Spec K.14: keep visitors' contact details only as long as needed. Closed chat requests are
 * deleted after RETENTION_DAYS.
 */
class PurgeChatHandoffs extends Command
{
    public const RETENTION_DAYS = 90;

    protected $signature = 'chat:purge-handoffs';

    protected $description = 'Delete closed chat handoffs older than '.self::RETENTION_DAYS.' days';

    public function handle(): int
    {
        $deleted = ChatHandoff::query()
            ->where('status', ChatHandoffService::CLOSED)
            ->where('closed_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $this->info("Deleted {$deleted} closed chat request(s).");

        return self::SUCCESS;
    }
}
