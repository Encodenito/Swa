<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Services\LowStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class LowStockAlertCommand extends Command
{
    protected $signature = 'stock:low-alert';

    protected $description = 'Email active admins the items at or below their low-stock threshold';

    public function handle(LowStockService $lowStock): int
    {
        $items = $lowStock->items();

        if ($items === []) {
            $this->info('No items are low on stock.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', Role::Admin)->whereNull('deactivated_at')->get();
        Notification::send($admins, new LowStockAlert($items));

        $this->info('Low-stock alert for '.count($items).' item(s) sent to '.$admins->count().' admin(s).');

        return self::SUCCESS;
    }
}
