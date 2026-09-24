<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Allowed range per setting.
     *
     * @var array<string, array{int, int}>
     */
    private const RANGES = [
        'downpayment_percent' => [1, 100],
        'pickup_days' => [1, 30],
        'forfeit_refund_percent' => [0, 100],
        'pending_minutes' => [10, 240],
        'max_unpaid_reservations' => [1, 10],
        'max_qty_per_item' => [1, 100],
    ];

    public function edit(SettingsService $settings): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('Admin/Settings', [
            'settings' => collect(SettingKey::cases())->mapWithKeys(fn (SettingKey $key) => [$key->value => $settings->int($key)]),
            'ranges' => self::RANGES,
        ]);
    }

    public function update(Request $request, SettingsService $settings): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $rules = [];

        foreach (self::RANGES as $key => [$min, $max]) {
            $rules[$key] = ['required', 'integer', "between:{$min},{$max}"];
        }

        foreach ($request->validate($rules) as $key => $value) {
            $settings->set(SettingKey::from($key), (int) $value);
        }

        return back(303)->with('success', 'Settings saved. New values apply to new reservations.');
    }
}
