<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public const CACHE_KEY = 'settings.all';

    /**
     * All settings, with code defaults filling any key missing from the table.
     *
     * @return array<string, string|int>
     */
    public function all(): array
    {
        $stored = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );

        return array_merge(SettingKey::defaults(), $stored);
    }

    public function get(SettingKey|string $key, mixed $default = null): mixed
    {
        $key = $key instanceof SettingKey ? $key->value : $key;

        return $this->all()[$key] ?? $default;
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key, $key->default());
    }

    public function set(SettingKey $key, string|int $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key->value], ['value' => (string) $value]);

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
