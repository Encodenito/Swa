<?php

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    /**
     * Inserts missing defaults only; values an admin has changed are never overwritten.
     */
    public function run(SettingsService $settings): void
    {
        $now = now();

        $rows = collect(SettingKey::defaults())
            ->map(fn (int $value, string $key) => [
                'key' => $key,
                'value' => (string) $value,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::table('settings')->insertOrIgnore($rows);

        $settings->flush();
    }
}
