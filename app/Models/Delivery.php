<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory;

    /**
     * `status`, `received_at` and `received_by` change only through DeliveryService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'reference',
        'expected_at',
    ];

    protected $attributes = [
        'status' => 'ordered',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'expected_at' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
