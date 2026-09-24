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
use App\Services\PayMongo\PayMongoException;
use App\Services\PayMongo\PayMongoService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReservationService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly PayMongoService $payMongo,
    ) {}

    /**
     * Create a pending_payment reservation with stock allocated. Prices and totals are computed here only.
     *
     * @param  array<int, int>  $quantities  item_id => quantity
     *
     * @throws ValidationException
     */
    public function create(User $customer, array $quantities, bool $termsAccepted): Reservation
    {
        $quantities = array_filter($quantities, fn ($qty) => $qty > 0);
        $maxQty = $this->settings->int(SettingKey::MaxQtyPerItem);

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
            return DB::transaction(function () use ($customer, $quantities) {
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
                    'reference_code' => $this->newReferenceCode(),
                    'status' => ReservationStatus::PendingPayment,
                    'total' => Money::zero(),
                    'downpayment_amount' => Money::zero(),
                    'balance' => Money::zero(),
                    'expires_at' => now()->addMinutes($this->settings->int(SettingKey::PendingMinutes)),
                    'terms_accepted_at' => now(),
                    'created_by' => $customer->id,
                ])->save();

                $this->stock->allocate($reservation, $quantities, $customer);

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

            // Phase 2 moves this into the audit log.
            Log::info('Pickup deadline extended.', [
                'reservation' => $locked->reference_code,
                'from' => $locked->pickup_by?->toIso8601String(),
                'to' => $deadline->toIso8601String(),
                'reason' => $reason,
                'by' => $staff->id,
            ]);

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

    private function newReferenceCode(): string
    {
        do {
            $code = 'SWA-';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Reservation::query()->where('reference_code', $code)->exists());

        return $code;
    }
}
