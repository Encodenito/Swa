<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_customers_by_default(): void
    {
        $user = User::create([
            'name' => 'Juan dela Cruz',
            'email' => 'juan@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(Role::Customer, $user->role);
        $this->assertSame(Role::Customer, $user->fresh()->role);
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(Role::Customer, $user->fresh()->role);

        $user->update(['role' => 'admin']);

        $this->assertSame(Role::Customer, $user->fresh()->role);
    }

    public function test_role_helpers(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse($customer->isStaff());
        $this->assertFalse($customer->isAdmin());
        $this->assertTrue($staff->isStaff());
        $this->assertFalse($staff->isAdmin());
        $this->assertTrue($admin->isStaff());
        $this->assertTrue($admin->isAdmin());
    }
}
