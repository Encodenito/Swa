<?php

namespace Tests\Feature\Money;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\StateConflictException;
use App\Jobs\SendRefund;
use App\Models\Payment;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ReservationService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
    }

    private function downpayment(): Payment
    {
        $reservation = $this->confirmedReservation(); // downpayment 300.00, provider_ref pay_test{id}

        return $reservation->payments()->where('type', PaymentType::Downpayment)->sole();
    }

    public function test_a_refund_is_sent_once_after_commit_with_the_right_body(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_abc', 'attributes' => ['status' => 'pending']]])]);
        $downpayment = $this->downpayment();

        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '150.00', 'Test refund'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.paymongo.com/v1/refunds'
            && $r['data']['attributes']['amount'] === 15000
            && $r['data']['attributes']['payment_id'] === $downpayment->provider_ref
            && $r['data']['attributes']['reason'] === 'others'
            && mb_strlen($r['data']['attributes']['notes']) <= 255);

        $refund->refresh();
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertSame('ref_abc', $refund->provider_ref);
        $this->assertSame($downpayment->id, $refund->parent_id);
        $this->assertSame('150.00', $refund->amount);
    }

    public function test_no_http_call_happens_while_the_transaction_is_open(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_x']])]);
        $downpayment = $this->downpayment();

        DB::transaction(function () use ($downpayment) {
            app(RefundService::class)->queue($downpayment, '300.00', 'Inside transaction');
            Http::assertNothingSent();
        });

        Http::assertSentCount(1);
    }

    public function test_a_failed_refund_becomes_refund_failed_and_is_never_retried_automatically(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(
            ['errors' => [['code' => 'available_balance_insufficient', 'detail' => 'Refund amount is greater than the available payout amount.']]],
            400,
        )]);
        $downpayment = $this->downpayment();

        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));

        $refund->refresh();
        $this->assertSame(PaymentStatus::RefundFailed, $refund->status);
        $this->assertStringContainsString('available_balance_insufficient', $refund->note);
        $this->assertSame(1, (new SendRefund(0))->tries);

        // Time passes, the scheduler runs everything it has: still exactly one call.
        $this->travel(2)->hours();
        $this->artisan('reservations:expire');
        $this->artisan('reservations:forfeit');
        $this->artisan('reservations:remind');

        Http::assertSentCount(1);
        $this->assertSame(PaymentStatus::RefundFailed, $refund->fresh()->status);
    }

    public function test_manual_retry_claims_the_row_so_a_double_click_sends_one_call(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::sequence()
            ->push(['errors' => [['code' => 'provider_processing_error']]], 400)
            ->push(['data' => ['id' => 'ref_retry']])]);
        $downpayment = $this->downpayment();
        $staff = User::factory()->staff()->create();

        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));
        $this->assertSame(PaymentStatus::RefundFailed, $refund->fresh()->status);

        $this->actingAs($staff)->post(route('staff.payments.retry', $refund))->assertSessionHas('success');
        $this->actingAs($staff)->postJson(route('staff.payments.retry', $refund))->assertStatus(409);

        Http::assertSentCount(2); // the failed one + exactly one retry
        $this->assertSame(PaymentStatus::Refunded, $refund->fresh()->status);
        $this->assertSame('ref_retry', $refund->fresh()->provider_ref);
    }

    public function test_the_claim_itself_is_atomic(): void
    {
        Queue::fake();
        $downpayment = $this->downpayment();
        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));
        $refund->forceFill(['status' => PaymentStatus::RefundFailed])->save();
        $staff = User::factory()->staff()->create();

        app(RefundService::class)->retry($refund, $staff);

        $this->expectException(StateConflictException::class);
        app(RefundService::class)->retry($refund, $staff);
    }

    public function test_refunds_are_capped_at_what_is_left_and_skipped_below_one_peso(): void
    {
        Queue::fake();
        $downpayment = $this->downpayment(); // 300.00
        $refunds = app(RefundService::class);

        $first = DB::transaction(fn () => $refunds->queue($downpayment, '250.00', 'Part'));
        $second = DB::transaction(fn () => $refunds->queue($downpayment, '250.00', 'More than left'));
        $third = DB::transaction(fn () => $refunds->queue($downpayment, '0.99', 'Tiny'));

        $this->assertSame('250.00', $first->amount);
        $this->assertSame('50.00', $second->amount);
        $this->assertNull($third);
        $this->assertSame('0.00', $refunds->refundable($downpayment));
    }

    public function test_a_failed_refund_does_not_count_against_what_is_refundable(): void
    {
        Queue::fake();
        $downpayment = $this->downpayment();
        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));
        $refund->forceFill(['status' => PaymentStatus::RefundFailed])->save();

        $this->assertSame('300.00', app(RefundService::class)->refundable($downpayment));
    }

    public function test_the_refund_job_never_sends_a_refund_that_is_not_pending(): void
    {
        Http::fake();
        $downpayment = $this->downpayment();
        Queue::fake();
        $refund = DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));
        $refund->forceFill(['status' => PaymentStatus::Refunded, 'provider_ref' => 'ref_done'])->save();

        app()->call([new SendRefund($refund->id), 'handle']);

        Http::assertNothingSent();
    }

    public function test_cancel_and_forfeit_use_the_same_refund_path(): void
    {
        Queue::fake();
        $reservation = $this->confirmedReservation();

        app(ReservationService::class)->cancel($reservation, $reservation->user, 100, 'Changed my mind');

        Queue::assertPushed(SendRefund::class, 1);
    }
}
