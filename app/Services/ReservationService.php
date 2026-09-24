<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Enums\SettingKey;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StateConflictException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\PickupReminder;
use App\Notifications\ReservationCancelled;
use App\Notifications\ReservationForfeited;
use App\Notifications\ReservationProcessing;
use App\Services\PayMongo\PayMongoException;
use App\Services\PayMongo\PayMongoService;
use App\Support\Money;
use App\Support\ReferenceCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReservationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly PayMongoService $payMongo,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a pending_payment reservation with stock allocated. Prices and totals are computed here only.
     *
     * @param  array<int, int>  $quantities  item_id => quantity
     *
     * @throws ValidationException
     */
    public function create(User $customer, array $quantities, bool $termsAccepted, ?User $createdBy = null): Reservation
    {
        $quantities = array_filter($quantities, fn ($qty) => $qty > 0);
        $maxQty = $this->settings->int(SettingKey::MaxQtyPerItem);

        if (! $customer->isCustomer()) {
            throw ValidationException::withMessages(['customer' => 'Reservations belong to a customer account.']);
        }

        if (! $termsAccepted) {
            throw ValidationException::withMessages(['terms' => 'Please accept the reservation terms.']);
        }

        if ($quantities === []) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        foreach ($quantities as $qty) {
            if ($qty > $maxQty) {
                throw ValidationException::withMessages(['cart' => "You can reserve at most {$maxQty} of each item."]);
            }
        }

        try {
            return DB::transaction(function () use ($customer, $quantities, $createdBy) {
                // Serialises this customer's reservation creation so the unpaid limit cannot be raced.
                User::query()->whereKey($customer->id)->lockForUpdate()->first();

                $maxUnpaid = $this->settings->int(SettingKey::MaxUnpaidReservations);
                $unpaid = Reservation::query()
                    ->where('user_id', $customer->id)
                    ->where('status', ReservationStatus::PendingPayment)
                    ->count();

                if ($unpaid >= $maxUnpaid) {
                    throw ValidationException::withMessages([
                        'cart' => "You already have {$unpaid} unpaid reservations. Pay or wait for them to expire first.",
                    ]);
                }

                $reservation = new Reservation;
                $reservation->forceFill([
                    'user_id' => $customer->id,
                    'type' => ReservationType::Reservation,
                    'reference_code' => ReferenceCode::generate(),
                    'status' => ReservationStatus::PendingPayment,
                    'total' => Money::zero(),
                    'downpayment_amount' => Money::zero(),
                    'balance' => Money::zero(),
                    'expires_at' => now()->addMinutes($this->settings->int(SettingKey::PendingMinutes)),
                    'terms_accepted_at' => now(),
                    // Staff may reserve on a customer's behalf; the reservation still belongs to the customer.
                    'created_by' => ($createdBy ?? $customer)->id,
                ])->save();

                $this->stock->allocate($reservation, $quantities, $createdBy ?? $customer);

                $total = Money::add(...$reservation->lines()->get()->map->lineTotal()->all());
                $downpayment = Money::percent($total, $this->settings->int(SettingKey::DownpaymentPercent));

                $reservation->forceFill([
                    'total' => $total,
                    'downpayment_amount' => $downpayment,
                    'balance' => Money::sub($total, $downpayment),
                ])->save();

                return $reservation;
            }, 3);
        } catch (InsufficientStockException $e) {
            throw ValidationException::withMessages(['cart' => $e->getMessage()]);
        }
    }

    /**
     * Create the PayMongo checkout for a new reservation. Runs after the reservation is committed,
     * outside any transaction. On failure the reservation is cancelled and its stock returned.
     *
     * @throws PayMongoException
     */
    public function startCheckout(Reservation $reservation): string
    {
        try {
            $session = $this->payMongo->createCheckout($reservation);
        } catch (Throwable $e) {
            // Any failure (API error, bad config) must not leave stock held by an unpayable reservation.
            Log::error('PayMongo checkout creation failed.', [
                'reservation' => $reservation->reference_code,
                'code' => $e instanceof PayMongoException ? $e->errorCode : $e::class,
            ]);
            $this->cancelUnpaid($reservation, 'Payment page could not be created.');

            throw $e instanceof PayMongoException ? $e : new PayMongoException('Checkout could not be created.', 'config_error', $e);
        }

        $reservation->forceFill([
            'paymongo_checkout_id' => $session['id'],
            'checkout_url' => $session['checkout_url'],
        ])->save();

        return $session['checkout_url'];
    }

    /**
     * pending_payment -> cancelled (checkout creation failed). Nothing was paid, so nothing to refund.
     */
    public function cancelUnpaid(Reservation $reservation, string $reason, ?User $by = null): bool
    {
        return DB::transaction(function () use ($reservation, $reason, $by) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::PendingPayment) {
                return false;
            }

            $locked->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by?->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->stock->release($locked, $by, $reason);

            return true;
        }, 3);
    }

    /**
     * pending_payment -> expired for every overdue reservation. Returns how many expired.
     */
    public function expireDue(): int
    {
        $ids = Reservation::query()
            ->where('status', ReservationStatus::PendingPayment)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            $checkoutId = DB::transaction(function () use ($id) {
                $reservation = Reservation::query()->whereKey($id)->lockForUpdate()->first();

                // Re-check under the lock: a webhook may have confirmed it since we listed it.
                if (! $reservation || $reservation->status !== ReservationStatus::PendingPayment || $reservation->expires_at->isFuture()) {
                    return false;
                }

                $reservation->forceFill(['status' => ReservationStatus::Expired])->save();
                $this->stock->release($reservation, null, 'Not paid in time');

                return $reservation->paymongo_checkout_id ?? true;
            }, 3);

            if ($checkoutId !== false) {
                $expired++;

                if (is_string($checkoutId)) {
                    $this->payMongo->expireCheckout($checkoutId);
                }
            }
        }

        return $expired;
    }

    /**
     * confirmed -> processing. A single conditional UPDATE, so exactly one of two racing requests wins.
     *
     * @throws StateConflictException
     */
    public function startProcessing(Reservation $reservation): void
    {
        $changed = Reservation::query()
            ->whereKey($reservation->id)
            ->where('status', ReservationStatus::Confirmed)
            ->update(['status' => ReservationStatus::Processing, 'processing_at' => now(), 'updated_at' => now()]);

        if ($changed !== 1) {
            throw new StateConflictException('This reservation is no longer confirmed, so it cannot be moved to processing.');
        }

        $reservation->refresh();

        // Only after the update really happened (spec C).
        $reservation->user?->notify(new ReservationProcessing($reservation));
    }

    /**
     * Statuses each role may cancel from, and the refund percentages it may choose (spec B, C, D).
     *
     * @return array{statuses: list<ReservationStatus>, percents: list<int>}
     */
    public static function cancelRules(User $by, Reservation $reservation): array
    {
        if ($by->isAdmin()) {
            return ['statuses' => [ReservationStatus::PendingPayment, ReservationStatus::Confirmed, ReservationStatus::Processing], 'percents' => [0, 50, 100]];
        }

        if ($by->isStaff()) {
            return ['statuses' => [ReservationStatus::PendingPayment, ReservationStatus::Confirmed, ReservationStatus::Processing], 'percents' => [0]];
        }

        // Customers: their own reservation, only before Processing, always a full refund.
        return $reservation->user_id === $by->id
            ? ['statuses' => [ReservationStatus::PendingPayment, ReservationStatus::Confirmed], 'percents' => [100]]
            : ['statuses' => [], 'percents' => []];
    }

    /**
     * Cancel a reservation: lock, re-check the status for this actor, return the stock to its
     * batches and queue the refund, all in one transaction. The refund HTTP call and the email
     * happen after commit.
     *
     * @throws StateConflictException|ValidationException
     */
    public function cancel(Reservation $reservation, User $by, int $refundPercent, ?string $reason): ?Payment
    {
        [$locked, $refund] = DB::transaction(function () use ($reservation, $by, $refundPercent, $reason) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $rules = self::cancelRules($by, $locked);

            if ($locked->type !== ReservationType::Reservation || ! in_array($locked->status, $rules['statuses'], true)) {
                throw new StateConflictException($locked->status === ReservationStatus::Processing && $by->isCustomer()
                    ? 'We have already started preparing this order, so it can no longer be cancelled online.'
                    : 'This reservation can no longer be cancelled (status: '.$locked->status->label().').');
            }

            if (! in_array($refundPercent, $rules['percents'], true)) {
                throw ValidationException::withMessages(['refund_percent' => 'You cannot choose that refund percentage.']);
            }

            $before = ['status' => $locked->status->value];
            $locked->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->stock->release($locked, $by, 'Cancelled');
            $refund = $this->refundDownpayment($locked, $refundPercent, "Cancelled ({$refundPercent}% refund)", $by);

            $this->audit->record('reservation.cancelled', $locked, $before, [
                'status' => 'cancelled',
                'refund_percent' => $refundPercent,
                'refund_amount' => $refund?->amount,
                'reason' => $reason,
            ], $by);

            return [$locked, $refund];
        }, 3);

        $reservation->refresh();
        $locked->user?->notify(new ReservationCancelled($locked, $refund));

        return $refund;
    }

    /**
     * confirmed/processing -> forfeited for every reservation past its pickup deadline.
     * Refunds forfeit_refund_percent of the downpayment; the shop keeps the rest.
     */
    public function forfeitDue(): int
    {
        $ids = Reservation::query()
            ->whereIn('status', ReservationStatus::awaitingPickup())
            ->where('pickup_by', '<', now())
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            $percent = $this->settings->int(SettingKey::ForfeitRefundPercent);

            $result = DB::transaction(function () use ($id, $percent) {
                $locked = Reservation::query()->whereKey($id)->lockForUpdate()->first();

                if (! $locked || ! in_array($locked->status, ReservationStatus::awaitingPickup(), true) || $locked->pickup_by?->isFuture()) {
                    return null;
                }

                $before = ['status' => $locked->status->value];
                $locked->forceFill(['status' => ReservationStatus::Forfeited, 'forfeited_at' => now()])->save();
                $this->stock->release($locked, null, 'Pickup deadline missed');
                $refund = $this->refundDownpayment($locked, $percent, "Pickup deadline missed ({$percent}% refund)", null);

                $this->audit->record('reservation.forfeited', $locked, $before, [
                    'status' => 'forfeited',
                    'refund_percent' => $percent,
                    'refund_amount' => $refund?->amount,
                ]);

                return [$locked, $refund];
            }, 3);

            if ($result) {
                $count++;
                [$locked, $refund] = $result;
                $locked->user?->notify(new ReservationForfeited($locked, $percent, $refund));
            }
        }

        return $count;
    }

    /**
     * Email (and SMS) a reminder once for pickups due within the next 24 hours.
     */
    public function remindDue(): int
    {
        $due = Reservation::query()
            ->with('user')
            ->whereIn('status', ReservationStatus::awaitingPickup())
            ->whereNotNull('user_id')
            ->whereNull('reminded_at')
            ->whereBetween('pickup_by', [now(), now()->addDay()])
            ->get();

        $sent = 0;

        foreach ($due as $reservation) {
            // Claim first so two runs cannot both send.
            $claimed = Reservation::query()->whereKey($reservation->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);

            if ($claimed === 1) {
                $reservation->user->notify(new PickupReminder($reservation));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Queue a refund of $percent of every paid downpayment. Runs inside the caller's transaction.
     */
    private function refundDownpayment(Reservation $reservation, int $percent, string $reason, ?User $by): ?Payment
    {
        if ($percent <= 0) {
            return null;
        }

        $refund = null;

        $paid = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', PaymentType::Downpayment)
            ->where('status', PaymentStatus::Paid)
            ->get();

        foreach ($paid as $payment) {
            $refund = $this->refunds->queue($payment, Money::percent($payment->amount, $percent), $reason, $by) ?? $refund;
        }

        return $refund;
    }

    /**
     * @throws StateConflictException|ValidationException
     */
    public function extendDeadline(Reservation $reservation, CarbonInterface $newDeadline, string $reason, User $staff): void
    {
        DB::transaction(function () use ($reservation, $newDeadline, $reason, $staff) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ReservationStatus::awaitingPickup(), true)) {
                throw new StateConflictException('Only confirmed or processing reservations have a pickup deadline.');
            }

            $deadline = $newDeadline->copy()->endOfDay();

            if ($deadline->lessThanOrEqualTo($locked->pickup_by)) {
                throw ValidationException::withMessages(['pickup_by' => 'The new deadline must be later than the current one.']);
            }

            app(AuditLogger::class)->record('reservation.deadline_extended', $locked,
                ['pickup_by' => $locked->pickup_by?->toIso8601String()],
                ['pickup_by' => $deadline->toIso8601String(), 'reason' => $reason],
                $staff,
            );

            $locked->forceFill(['pickup_by' => $deadline])->save();
        }, 3);

        $reservation->refresh();
    }

    /**
     * Pickup counter: record the exact balance in cash and complete the reservation.
     *
     * @throws StateConflictException|ValidationException
     */
    public function complete(Reservation $reservation, string $amountReceived, User $staff): Payment
    {
        $payment = DB::transaction(function () use ($reservation, $amountReceived, $staff) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ReservationStatus::awaitingPickup(), true)) {
                throw new StateConflictException('This reservation cannot be picked up (status: '.$locked->status->label().').');
            }

            if (! Money::equals($amountReceived, $locked->balance)) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter the exact balance of '.Money::format($locked->balance).'.',
                ]);
            }

            $payment = new Payment;
            $payment->forceFill([
                'reservation_id' => $locked->id,
                'type' => PaymentType::Balance,
                'amount' => $locked->balance,
                'method' => PaymentMethod::Cash,
                'status' => PaymentStatus::Paid,
                'created_by' => $staff->id,
            ])->save();

            $locked->forceFill([
                'status' => ReservationStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return $payment;
        }, 3);

        $reservation->refresh();

        return $payment;
    }
}
