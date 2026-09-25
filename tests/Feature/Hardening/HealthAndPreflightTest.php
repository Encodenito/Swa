<?php

namespace Tests\Feature\Hardening;

use App\Models\User;
use App\Services\HealthCheckService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthAndPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    /**
     * A correct live configuration, without real secrets.
     */
    private function productionConfig(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://shop.example.ph',
            'session.secure' => true,
            'paymongo.secret_key' => 'sk_live_REALSECRETVALUE123',
            'paymongo.public_key' => 'pk_live_publicvalue',
            'paymongo.webhook_secret' => 'whsk_REALWEBHOOKSECRET',
            'paymongo.livemode' => true,
            'mail.default' => 'resend',
            'services.resend.key' => 're_REALRESENDKEY',
            'mail.from.address' => 'reservations@swa-shop.ph',
            'queue.default' => 'database',
        ]);
        Cache::put(HealthCheckService::HEARTBEAT_KEY, now()->timestamp);
    }

    private function checkStatus(string $key): ?string
    {
        return collect(app(HealthCheckService::class)->all())->firstWhere('key', $key)['status'] ?? null;
    }

    public function test_a_correct_production_setup_has_no_failures(): void
    {
        $this->productionConfig();
        $checks = app(HealthCheckService::class)->all();

        $failures = collect($checks)->where('status', 'fail')->pluck('label')->all();
        // The storage link may be missing on a test machine; everything else must pass.
        $this->assertSame([], array_values(array_diff($failures, ['Image storage link'])));
    }

    public function test_debug_mode_placeholder_keys_and_log_mail_fail_in_production(): void
    {
        $this->productionConfig();
        config(['app.debug' => true, 'paymongo.secret_key' => 'sk_test_xxx', 'mail.default' => 'log']);

        $this->assertSame('fail', $this->checkStatus('debug'));
        $this->assertSame('fail', $this->checkStatus('paymongo_keys'));
        $this->assertSame('fail', $this->checkStatus('mail'));
    }

    public function test_mismatched_pay_mongo_modes_fail(): void
    {
        $this->productionConfig();

        config(['paymongo.livemode' => false]);
        $this->assertSame('fail', $this->checkStatus('paymongo_keys'));

        config(['paymongo.livemode' => true, 'paymongo.public_key' => 'pk_test_x']);
        $this->assertSame('fail', $this->checkStatus('paymongo_keys'));
    }

    public function test_dev_accounts_fail_only_in_production(): void
    {
        User::factory()->create(['email' => 'admin@swa.test']);
        $this->assertSame('warn', $this->checkStatus('dev_accounts'));

        $this->productionConfig();
        $this->assertSame('fail', $this->checkStatus('dev_accounts'));
    }

    public function test_a_stopped_scheduler_and_worker_are_reported(): void
    {
        $this->assertSame('fail', $this->checkStatus('scheduler'));

        Cache::put(HealthCheckService::HEARTBEAT_KEY, now()->subMinutes(10)->timestamp);
        $this->assertSame('fail', $this->checkStatus('scheduler'));

        Cache::put(HealthCheckService::HEARTBEAT_KEY, now()->timestamp);
        $this->assertSame('ok', $this->checkStatus('scheduler'));

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(20)->timestamp]);
        $this->assertSame('fail', $this->checkStatus('queue'));
    }

    public function test_the_scheduler_writes_a_heartbeat(): void
    {
        Cache::forget(HealthCheckService::HEARTBEAT_KEY);

        Artisan::call('schedule:run');

        $this->assertNotNull(Cache::get(HealthCheckService::HEARTBEAT_KEY));
    }

    public function test_preflight_exits_non_zero_on_failures_and_never_prints_secrets(): void
    {
        $this->productionConfig();
        config(['app.debug' => true]);

        $this->artisan('swa:preflight')
            ->expectsOutputToContain('FAIL')
            ->doesntExpectOutputToContain('REALSECRETVALUE')
            ->doesntExpectOutputToContain('REALWEBHOOKSECRET')
            ->doesntExpectOutputToContain('REALRESENDKEY')
            ->assertFailed();
    }

    public function test_the_dashboard_warns_staff_only_when_something_is_wrong(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('staff.home'))
            ->assertInertia(fn ($page) => $page->where('health.0.key', 'scheduler'));

        Cache::put(HealthCheckService::HEARTBEAT_KEY, now()->timestamp);

        $this->actingAs($staff)->get(route('staff.home'))
            ->assertInertia(fn ($page) => $page->has('health', 0));
    }

    public function test_system_health_page_is_admin_only_and_holds_no_secrets(): void
    {
        $this->productionConfig();

        $this->actingAs(User::factory()->staff()->create())->get(route('admin.system-health'))->assertForbidden();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.system-health'))->assertOk();
        $this->assertStringNotContainsString('REALSECRETVALUE', $response->getContent());
        $this->assertStringNotContainsString('REALRESENDKEY', $response->getContent());
    }
}
