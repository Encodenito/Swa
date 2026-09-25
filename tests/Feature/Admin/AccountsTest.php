<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_creates_a_verified_staff_account(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Maria Santos',
            'email' => 'maria@swa.test',
            'phone' => '09171234567',
            'role' => 'staff',
            'password' => 'TempPass123!',
        ])->assertSessionHas('success');

        $user = User::where('email', 'maria@swa.test')->sole();
        $this->assertSame(Role::Staff, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('TempPass123!', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => (string) $user->id]);
    }

    public function test_the_create_form_cannot_make_customers(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'X', 'email' => 'x@swa.test', 'role' => 'customer', 'password' => 'TempPass123!',
        ])->assertSessionHasErrors('role');
    }

    public function test_role_changes_need_a_reason_and_are_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.users.role', $user), ['role' => 'staff'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->put(route('admin.users.role', $user), ['role' => 'staff', 'reason' => 'Hired at the counter'])->assertSessionHas('success');

        $this->assertSame(Role::Staff, $user->fresh()->role);
        $log = AuditLog::where('action', 'user.role_changed')->sole();
        $this->assertSame(['role' => 'customer'], $log->before);
        $this->assertSame('staff', $log->after['role']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_an_admin_cannot_change_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)->putJson(route('admin.users.role', $this->admin), ['role' => 'staff', 'reason' => 'Oops'])->assertStatus(409);
        $this->actingAs($this->admin)->postJson(route('admin.users.deactivate', $this->admin), ['reason' => 'Oops'])->assertStatus(409);

        $this->assertSame(Role::Admin, $this->admin->fresh()->role);
        $this->assertNull($this->admin->fresh()->deactivated_at);
    }

    public function test_the_last_active_admin_cannot_be_removed(): void
    {
        $other = User::factory()->admin()->create();
        $this->actingAs($this->admin)->put(route('admin.users.role', $other), ['role' => 'staff', 'reason' => 'Moved'])->assertSessionHas('success');

        // Now $this->admin is the only admin; a second admin tries to demote... there is none, so use a fresh one and deactivate the first.
        $second = User::factory()->admin()->create();
        $this->actingAs($second)->post(route('admin.users.deactivate', $this->admin), ['reason' => 'Left'])->assertSessionHas('success');
        $this->actingAs($this->admin->fresh())->get('/admin')->assertRedirect(route('login'));

        // $second is now the only active admin: nobody else can remove them, and they cannot remove themselves.
        $this->actingAs($second)->postJson(route('admin.users.deactivate', $second), ['reason' => 'x x x'])->assertStatus(409);
        $this->assertSame(1, User::where('role', 'admin')->whereNull('deactivated_at')->count());
    }

    public function test_deactivation_signs_the_user_out_and_reactivation_restores_access(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($this->admin)->post(route('admin.users.deactivate', $staff), ['reason' => 'Left the shop'])->assertSessionHas('success');
        $this->actingAs($staff->fresh())->get('/staff')->assertRedirect(route('login'));

        $this->actingAs($this->admin)->post(route('admin.users.reactivate', $staff), ['reason' => 'Back from leave'])->assertSessionHas('success');
        $this->actingAs($staff->fresh())->get('/staff')->assertOk();

        $this->assertSame(['user.deactivated', 'user.reactivated'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_staff_cannot_manage_accounts(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.users.role', $customer), ['role' => 'admin', 'reason' => 'Promote me'])->assertForbidden();
    }
}
