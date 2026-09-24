<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status, money and ownership are set only by ReservationService / ConfirmPaymentService
 * (via forceFill or conditional updates), never from request input.
 *
 * @property ReservationStatus $status
 * @property string $total
 * @property string $downpayment_amount
 * @property string $balance
 */
class Reservation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'type' => ReservationType::class,
            'status' => ReservationStatus::class,
            'total' => 'decimal:2',
            'downpayment_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'expires_at' => 'datetime',
            'pickup_by' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'forfeited_at' => 'datetime',
        ];
    }

    /**
     * URLs use the reference code, not the sequential id.
     */
    public function getRouteKeyName(): string
    {
        return 'reference_code';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isPendingPayment(): bool
    {
        return $this->status === ReservationStatus::PendingPayment;
    }
}
