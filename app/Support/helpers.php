<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * Cached, typed read of an admin setting, e.g. setting(SettingKey::PickupDays).
     */
    function setting(SettingKey $key): int
    {
        return app(SettingsService::class)->int($key);
    }
}
