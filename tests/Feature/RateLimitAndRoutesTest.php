<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_middleware_parameter_is_a_valid_role(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! Str::startsWith($middleware, 'role:')) {
                    continue;
                }

                foreach (explode(',', Str::after($middleware, 'role:')) as $role) {
                    $this->assertNotNull(Role::tryFrom($role), "Route [{$route->uri()}] uses unknown role [{$role}].");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', [])->assertStatus(302);
        }

        $this->post('/register', [])->assertTooManyRequests();
    }

    public function test_login_is_throttled_per_ip_across_different_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertTooManyRequests();
    }
}
