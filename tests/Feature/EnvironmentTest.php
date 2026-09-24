<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_runs_in_manila_time_with_peso_currency(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get());
        $this->assertSame('PHP', config('app.currency'));
    }

    public function test_tests_use_the_dedicated_mariadb_test_database(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertSame('swa_reservations_test', DB::connection()->getDatabaseName());
    }

    public function test_paymongo_is_configured_for_test_mode_v1_endpoints(): void
    {
        $this->assertFalse(config('paymongo.livemode'));
        $this->assertSame('/v1/checkout_sessions', config('paymongo.checkout_path'));
        $this->assertSame('/v1/refunds', config('paymongo.refunds_path'));
    }

    public function test_database_seeder_creates_one_dev_account_per_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(Role::Admin, User::where('email', 'admin@swa.test')->value('role'));
        $this->assertSame(Role::Staff, User::where('email', 'staff@swa.test')->value('role'));
        $this->assertSame(Role::Customer, User::where('email', 'customer@swa.test')->value('role'));
    }
}
