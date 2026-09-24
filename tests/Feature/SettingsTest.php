<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_stores_all_spec_defaults(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame([
            'downpayment_percent' => '30',
            'forfeit_refund_percent' => '50',
            'max_qty_per_item' => '10',
            'max_unpaid_reservations' => '2',
            'pending_minutes' => '30',
            'pickup_days' => '3',
        ], Setting::query()->orderBy('key')->pluck('value', 'key')->all());
    }

    public function test_helper_returns_typed_values(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(30, setting(SettingKey::DownpaymentPercent));
        $this->assertSame(3, setting(SettingKey::PickupDays));
    }

    public function test_values_are_cached_and_set_invalidates_the_cache(): void
    {
        $this->seed(SettingsSeeder::class);
        $settings = app(SettingsService::class);

        $this->assertSame(3, $settings->int(SettingKey::PickupDays));

        // A write that bypasses the service is not seen: proof the read is cached.
        DB::table('settings')->where('key', 'pickup_days')->update(['value' => '9']);
        $this->assertSame(3, $settings->int(SettingKey::PickupDays));

        $settings->set(SettingKey::PickupDays, 5);
        $this->assertSame(5, setting(SettingKey::PickupDays));
    }

    public function test_reseeding_does_not_overwrite_an_admin_edit(): void
    {
        $this->seed(SettingsSeeder::class);
        app(SettingsService::class)->set(SettingKey::ForfeitRefundPercent, 40);

        $this->seed(SettingsSeeder::class);

        $this->assertSame(40, setting(SettingKey::ForfeitRefundPercent));
    }

    public function test_missing_rows_fall_back_to_code_defaults(): void
    {
        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(10, setting(SettingKey::MaxQtyPerItem));
    }
}
