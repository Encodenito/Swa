<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StateConflictException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use App\Support\ReferenceCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Counter sales: paid in full in cash, completed at once, no customer account, no email (spec D).
 */
class WalkInService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int, int>  $quantities  item_id => quantity
     *
     * @throws ValidationException
     */
    public function sell(array $quantities, ?string $name, ?string $phone, User $staff): Reservation
    {
        $quantities = array_filter($quantities, fn ($qty) => $qty > 0);

        if ($quantities === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item.']);
        }

        try {
            return DB::transaction(function () use ($quantities, $name, $phone, $staff) {
                $sale = new Reservation;
                $sale->forceFill([
                    'user_id' => null,
                    'type' => ReservationType::WalkIn,
                    'reference_code' => ReferenceCode::generate(),
                    'status' => ReservationStatus::Completed,
                    'total' => Money::zero(),
                    'downpayment_amount' => Money::zero(),
                    'balance' => Money::zero(),
                    'completed_at' => now(),
                    'walk_in_name' => $name,
                    'walk_in_phone' => $phone,
                    'created_by' => $staff->id,
                ])->save();

                $this->stock->allocate($sale, $quantities, $staff, MovementType::Sold);

                $total = Money::add(...$sale->lines()->get()->map->lineTotal()->all());
                $sale->forceFill(['total' => $total])->save();

                $payment = new Payment;
                $payment->forceFill([
                    'reservation_id' => $sale->id,
                    'type' => PaymentType::Full,
                    'amount' => $total,
                    'method' => PaymentMethod::Cash,
                    'status' => PaymentStatus::Paid,
                    'created_by' => $staff->id,
                ])->save();

                return $sale;
            }, 3);
        } catch (InsufficientStockException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }

    /**
     * Admin void of a completed walk-in sale: stock returns through `adjusted` movements and the
     * cash is recorded as refunded over the counter.
     *
     * @throws StateConflictException
     */
    public function void(Reservation $sale, string $reason, User $admin): void
    {
        DB::transaction(function () use ($sale, $reason, $admin) {
            $locked = Reservation::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== ReservationType::WalkIn || $locked->status !== ReservationStatus::Completed) {
                throw new StateConflictException('Only a completed walk-in sale can be voided.');
            }

            $locked->forceFill([
                'status' => ReservationStatus::Voided,
                'cancelled_at' => now(),
                'cancelled_by' => $admin->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->stock->returnVoided($locked, "Void: {$reason}", $admin);

            $payment = Payment::query()
                ->where('reservation_id', $locked->id)
                ->where('type', PaymentType::Full)
                ->where('status', PaymentStatus::Paid)
                ->first();

            if ($payment) {
                $this->refunds->queue($payment, $payment->amount, "Walk-in sale voided: {$reason}", $admin);
            }

            $this->audit->record('walk_in.voided', $locked, ['status' => 'completed'], [
                'status' => 'voided',
                'total' => $locked->total,
                'reason' => $reason,
            ], $admin);
        }, 3);

        $sale->refresh();
    }
}
