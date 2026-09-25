<?php

namespace Tests\Feature\Care;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\CustomerNote;
use App\Models\FollowUp;
use App\Models\Tag;
use App\Models\User;
use App\Services\CrmService;
use App\Services\CustomerSegments;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        $this->staff = User::factory()->staff()->create();
        $this->customer = User::factory()->create();
    }

    public function test_staff_add_a_note_and_card_numbers_are_refused(): void
    {
        $this->actingAs($this->staff)
            ->post(route('staff.customers.notes.store', $this->customer), ['body' => 'Paid with 4111 1111 1111 1111'])
            ->assertSessionHasErrors('body');

        $this->actingAs($this->staff)
            ->post(route('staff.customers.notes.store', $this->customer), ['body' => 'Prefers GCash, picks up after 5pm.'])
            ->assertSessionHas('success');

        $note = CustomerNote::sole();
        $this->assertSame($this->customer->id, $note->user_id);
        $this->assertSame($this->staff->id, $note->created_by);
    }

    public function test_authors_delete_their_own_note_only_briefly_and_admins_any_time(): void
    {
        $note = app(CrmService::class)->addNote($this->customer, 'Called about warranty.', $this->staff);
        $other = User::factory()->staff()->create();

        $this->actingAs($other)->delete(route('staff.notes.destroy', $note))->assertForbidden();

        $this->travel(11)->minutes();
        $this->actingAs($this->staff)->delete(route('staff.notes.destroy', $note))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->delete(route('staff.notes.destroy', $note))->assertSessionHas('success');
        $this->assertModelMissing($note);
        $this->assertSame('customer_note.deleted', AuditLog::sole()->action);
    }

    public function test_tags_are_attached_by_staff_and_created_by_admins_only(): void
    {
        $this->actingAs($this->staff)->post(route('admin.tags.store'), ['name' => 'VIP', 'colour' => 'violet'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.tags.store'), ['name' => 'VIP', 'colour' => 'violet'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.tags.store'), ['name' => 'vip!', 'colour' => 'violet'])->assertSessionHasErrors();
        $tag = Tag::sole();

        $this->actingAs($this->staff)->post(route('staff.customers.tags.attach', [$this->customer, $tag]));
        $this->actingAs($this->staff)->post(route('staff.customers.tags.attach', [$this->customer, $tag])); // idempotent
        $this->assertSame(1, $this->customer->tags()->count());

        $this->actingAs($this->staff)->delete(route('staff.customers.tags.detach', [$this->customer, $tag]));
        $this->assertSame(0, $this->customer->tags()->count());
        $this->assertSame(['tag.created', 'customer.tagged', 'customer.untagged'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_follow_ups_link_only_this_customers_reservations_and_complete_once(): void
    {
        $theirs = $this->pendingReservation($this->customer);
        $someoneElses = $this->pendingReservation();

        $this->actingAs($this->staff)->post(route('staff.customers.follow-ups.store', $this->customer), [
            'due_on' => today()->addDay()->toDateString(), 'note' => 'Ask about the printer', 'reservation_id' => $someoneElses->id,
        ])->assertSessionHasErrors('reservation_id');

        $this->actingAs($this->staff)->post(route('staff.customers.follow-ups.store', $this->customer), [
            'due_on' => today()->addDay()->toDateString(), 'note' => 'Ask about the printer', 'reservation_id' => $theirs->id,
        ])->assertSessionHas('success');

        $followUp = FollowUp::sole();
        $this->actingAs($this->staff)->post(route('staff.follow-ups.complete', $followUp))->assertSessionHas('success');
        $this->actingAs($this->staff)->postJson(route('staff.follow-ups.complete', $followUp))->assertStatus(409);
        $this->assertNotNull($followUp->fresh()->completed_at);
    }

    public function test_segments_select_the_right_customers(): void
    {
        $segments = app(CustomerSegments::class);
        $ids = fn (string $segment) => $segments->apply($segments->baseQuery(), $segment)->orderBy('id')->pluck('id')->all();

        $picked = User::factory()->create();
        $reservation = $this->confirmedReservation($picked);
        $reservation->forceFill(['status' => ReservationStatus::Completed, 'completed_at' => now()->subDays(10)])->save();

        $forfeiter = User::factory()->create();
        $f = $this->confirmedReservation($forfeiter);
        $f->forceFill(['status' => ReservationStatus::Forfeited])->save();

        $waiting = User::factory()->create();
        $this->confirmedReservation($waiting);

        $dormant = User::factory()->create();
        $old = $this->pendingReservation($dormant);
        DB::table('reservations')->where('id', $old->id)->update(['created_at' => now()->subDays(200)]);

        app(CrmService::class)->addFollowUp($this->customer, ['due_on' => today()->toDateString(), 'note' => 'Call back'], $this->staff);

        $this->assertSame([$picked->id], $ids('recent_pickup'));
        $this->assertSame([$forfeiter->id], $ids('forfeited'));
        $this->assertSame([$waiting->id], $ids('awaiting_pickup'));
        $this->assertSame([$this->customer->id], $ids('open_follow_up'));
        // Never reserved recently: the customer with only an old reservation, and the one with none.
        $this->assertSame([$this->customer->id, $dormant->id], $ids('dormant'));
        // Staff accounts are never part of a customer segment.
        $this->assertNotContains($this->staff->id, $ids('dormant'));
    }

    public function test_customers_cannot_reach_any_crm_route(): void
    {
        $note = app(CrmService::class)->addNote($this->customer, 'Internal remark', $this->staff);

        $this->actingAs($this->customer)->get(route('staff.customers.index'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('staff.customers.show', $this->customer))->assertForbidden();
        $this->actingAs($this->customer)->post(route('staff.customers.notes.store', $this->customer), ['body' => 'x x'])->assertForbidden();
        $this->actingAs($this->customer)->delete(route('staff.notes.destroy', $note))->assertForbidden();
        $this->actingAs($this->customer)->get(route('staff.follow-ups.index'))->assertForbidden();
    }

    public function test_staff_profiles_are_not_reachable_through_the_customer_pages(): void
    {
        $this->actingAs($this->staff)->get(route('staff.customers.show', User::factory()->admin()->create()))->assertForbidden();
    }

    public function test_the_customer_list_uses_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->staff)->get(route('staff.customers.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        $tag = Tag::query()->create(['name' => 'Repeat', 'slug' => 'repeat', 'colour' => 'blue']);
        foreach (User::factory()->count(2)->create() as $c) {
            $c->tags()->attach($tag->id);
        }
        $few = $count();

        foreach (User::factory()->count(10)->create() as $c) {
            $c->tags()->attach($tag->id);
            $this->pendingReservation($c);
        }
        $many = $count();

        $this->assertSame($few, $many, 'Customer list queries grow with the number of customers (N+1).');
    }
}
