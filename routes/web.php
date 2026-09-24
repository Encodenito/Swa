<?php

use App\Enums\SettingKey;
use App\Http\Controllers\Account\CartController;
use App\Http\Controllers\Account\ReservationController as AccountReservationController;
use App\Http\Controllers\Admin\BatchPriceController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Staff\DeliveryController;
use App\Http\Controllers\Staff\PickupController;
use App\Http\Controllers\Staff\ReservationController as StaffReservationController;
use App\Http\Controllers\Staff\StockController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'downpaymentPercent' => setting(SettingKey::DownpaymentPercent),
        'pickupDays' => setting(SettingKey::PickupDays),
    ]);
})->name('home');

Route::middleware('throttle:browse')->group(function () {
    Route::get('items', [CatalogController::class, 'index'])->name('items.index');
    Route::get('items/{item:slug}', [CatalogController::class, 'show'])->name('items.show');
});

// Post-login landing: send each role to its own area.
Route::get('dashboard', function (Request $request) {
    return redirect()->route($request->user()->role->homeRoute());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified', 'role:customer', 'throttle:browse'])
    ->prefix('account')->name('account.')
    ->group(function () {
        Route::get('/', [AccountReservationController::class, 'index'])->name('home');

        Route::get('cart', [CartController::class, 'show'])->name('cart');
        Route::post('cart', [CartController::class, 'add'])->name('cart.add');
        Route::patch('cart', [CartController::class, 'update'])->name('cart.update');

        Route::post('reservations', [AccountReservationController::class, 'store'])
            ->middleware('throttle:reservations')
            ->name('reservations.store');
        Route::get('reservations/{reservation}', [AccountReservationController::class, 'show'])->name('reservations.show');
        Route::post('reservations/{reservation}/pay', [AccountReservationController::class, 'pay'])->name('reservations.pay');
        Route::get('reservations/{reservation}/return', [AccountReservationController::class, 'return'])->name('reservations.return');
        Route::get('reservations/{reservation}/status', [AccountReservationController::class, 'status'])->name('reservations.status');
    });

Route::middleware(['auth', 'verified', 'role:staff,admin'])
    ->prefix('staff')->name('staff.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Staff/Home'))->name('home');

        Route::get('reservations', [StaffReservationController::class, 'index'])->name('reservations.index');
        Route::get('reservations/{reservation}', [StaffReservationController::class, 'show'])->name('reservations.show');
        Route::post('reservations/{reservation}/process', [StaffReservationController::class, 'process'])->name('reservations.process');
        Route::post('reservations/{reservation}/extend', [StaffReservationController::class, 'extend'])->name('reservations.extend');

        Route::get('pickup', [PickupController::class, 'index'])->name('pickup');
        Route::post('pickup/{reservation}', [PickupController::class, 'complete'])->name('pickup.complete');

        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('deliveries/create', [DeliveryController::class, 'create'])->name('deliveries.create');
        Route::post('deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
        Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::post('deliveries/{delivery}/receive', [DeliveryController::class, 'receive'])->name('deliveries.receive');

        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/{item:slug}', [StockController::class, 'show'])->withTrashed()->name('stock.show');
        Route::post('stock/batches/{batch}/adjust', [StockController::class, 'adjust'])->name('stock.adjust');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    });

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Admin/Home'))->name('home');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('items', [ItemController::class, 'index'])->name('items.index');
        Route::get('items/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('items', [ItemController::class, 'store'])->name('items.store');
        Route::get('items/{item:slug}/edit', [ItemController::class, 'edit'])->name('items.edit');
        // POST (not PUT) so multipart image uploads work from the browser.
        Route::post('items/{item:slug}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item:slug}', [ItemController::class, 'destroy'])->name('items.destroy');

        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        Route::put('batches/{batch}/price', [BatchPriceController::class, 'update'])->name('batches.price');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
