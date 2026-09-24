<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(SettingsService $settings): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('Admin/Settings', [
            'settings' => collect(SettingKey::cases())->mapWithKeys(fn (SettingKey $key) => [$key->value => $settings->int($key)]),
            'ranges' => UpdateSettingsRequest::RANGES,
        ]);
    }

    public function update(UpdateSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        Gate::authorize('manage-settings');

        foreach ($request->validated() as $key => $value) {
            $settings->set(SettingKey::from($key), (int) $value);
        }

        return back(303)->with('success', 'Settings saved. New values apply to new reservations.');
    }
}
