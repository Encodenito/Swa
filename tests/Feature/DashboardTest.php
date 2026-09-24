<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/account')->assertRedirect('/login');
        $this->get('/staff')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roleHomes(): array
    {
        return [
            'customer' => ['customer', '/account'],
            'staff' => ['staff', '/staff'],
            'admin' => ['admin', '/admin'],
        ];
    }

    #[DataProvider('roleHomes')]
    public function test_dashboard_sends_each_role_to_its_area(string $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect($home);
        $this->actingAs($user)->get($home)->assertOk();
    }

    public function test_unverified_users_must_verify_before_entering_an_area(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/account')->assertRedirect('/verify-email');
    }

    public function test_customers_cannot_open_staff_or_admin_areas(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/staff')->assertForbidden();
        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_staff_cannot_open_admin_or_customer_areas(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->actingAs($staff)->get('/account')->assertForbidden();
    }

    public function test_admin_can_open_the_staff_area(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/staff')->assertOk();
    }

    public function test_shared_user_props_expose_only_safe_fields(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)->get('/staff')->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Home')
            ->where('auth.user.role', 'staff')
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.phone')
        );
    }
}
