<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Daily email to admins listing items at or below their low-stock threshold.
 */
class LowStockAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<array{name: string, sku: string|null, available: int, threshold: int, slug: string}>  $items
     */
    public function __construct(public array $items)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $out = count(array_filter($this->items, fn ($i) => $i['available'] === 0));
        $mail = (new MailMessage)
            ->subject('Low stock: '.count($this->items).' item(s) need reordering')
            ->greeting("Hi {$notifiable->name},")
            ->line(count($this->items)." item(s) are at or below their low-stock level, {$out} of them sold out:");

        foreach (array_slice($this->items, 0, 50) as $item) {
            $status = $item['available'] === 0 ? 'sold out' : "{$item['available']} left (alert at {$item['threshold']})";
            $mail->line("• {$item['name']}".($item['sku'] ? " ({$item['sku']})" : '').": {$status}");
        }

        if (count($this->items) > 50) {
            $mail->line('…and '.(count($this->items) - 50).' more. See the Stock page.');
        }

        return $mail->action('Open Stock', route('staff.stock.index'));
    }
}
