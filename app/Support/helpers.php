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

if (! function_exists('like_pattern')) {
    /**
     * "%term%" for a LIKE search, with the user's own % _ \ treated as literal characters.
     */
    function like_pattern(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
