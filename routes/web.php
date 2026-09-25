<?php

use App\Enums\SettingKey;
use App\Http\Controllers\Account\CartController;
use App\Http\Controllers\Account\ReservationController as AccountReservationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BatchPriceController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Staff\CustomerReservationController;
use App\Http\Controllers\Staff\DeliveryController;
use App\Http\Controllers\Staff\PaymentReviewController;
use App\Http\Controllers\Staff\PickupController;
use App\Http\Controllers\Staff\ReservationController as StaffReservationController;
use App\Http\Controllers\Staff\StockController;
use App\Http\Controllers\Staff\WalkInController;
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
        Route::post('reservations/{reservation}/cancel', [AccountReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::get('reservations/{reservation}/return', [AccountReservationController::class, 'return'])->name('reservations.return');
        Route::get('reservations/{reservation}/status', [AccountReservationController::class, 'status'])->name('reservations.status');
    });

Route::middleware(['auth', 'verified', 'role:staff,admin'])
    ->prefix('staff')->name('staff.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Staff/Home'))->name('home');

        Route::get('reservations', [StaffReservationController::class, 'index'])->name('reservations.index');
        Route::get('reservations/{reservation}', [StaffReservationController::class, 'show'])->name('reservations.show');
        Route::post('reservations/{reservation}/process', [StaffReservationController::class, 'process'])->middleware('throttle:staff-writes')->name('reservations.process');
        Route::post('reservations/{reservation}/extend', [StaffReservationController::class, 'extend'])->middleware('throttle:staff-writes')->name('reservations.extend');
        Route::post('reservations/{reservation}/cancel', [StaffReservationController::class, 'cancel'])->middleware('throttle:staff-writes')->name('reservations.cancel');

        Route::get('customer-reservations/create', [CustomerReservationController::class, 'create'])->name('customer-reservations.create');
        Route::post('customer-reservations', [CustomerReservationController::class, 'store'])
            ->middleware('throttle:reservations')
            ->name('customer-reservations.store');

        Route::get('walk-ins/create', [WalkInController::class, 'create'])->name('walk-ins.create');
        Route::post('walk-ins', [WalkInController::class, 'store'])->middleware('throttle:staff-writes')->name('walk-ins.store');
        Route::get('walk-ins/{reservation}', [WalkInController::class, 'show'])->name('walk-ins.show');

        Route::get('payments/review', [PaymentReviewController::class, 'index'])->name('payments.review');
        Route::post('payments/{payment}/refund', [PaymentReviewController::class, 'refund'])->middleware('throttle:staff-writes')->name('payments.refund');
        Route::post('payments/{payment}/retry', [PaymentReviewController::class, 'retry'])->middleware('throttle:staff-writes')->name('payments.retry');

        Route::get('pickup', [PickupController::class, 'index'])->name('pickup');
        Route::post('pickup/{reservation}', [PickupController::class, 'complete'])->middleware('throttle:staff-writes')->name('pickup.complete');

        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('deliveries/create', [DeliveryController::class, 'create'])->name('deliveries.create');
        Route::post('deliveries', [DeliveryController::class, 'store'])->middleware('throttle:staff-writes')->name('deliveries.store');
        Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::post('deliveries/{delivery}/receive', [DeliveryController::class, 'receive'])->middleware('throttle:staff-writes')->name('deliveries.receive');

        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/{item:slug}', [StockController::class, 'show'])->withTrashed()->name('stock.show');
        Route::post('stock/batches/{batch}/adjust', [StockController::class, 'adjust'])->middleware('throttle:staff-writes')->name('stock.adjust');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    });

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Admin/Home'))->name('home');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->middleware('throttle:staff-writes')->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('throttle:staff-writes')->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('throttle:staff-writes')->name('categories.destroy');

        Route::get('items', [ItemController::class, 'index'])->name('items.index');
        Route::get('items/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('items', [ItemController::class, 'store'])->middleware('throttle:staff-writes')->name('items.store');
        Route::get('items/{item:slug}/edit', [ItemController::class, 'edit'])->name('items.edit');
        // POST (not PUT) so multipart image uploads work from the browser.
        Route::post('items/{item:slug}', [ItemController::class, 'update'])->middleware('throttle:staff-writes')->name('items.update');
        Route::delete('items/{item:slug}', [ItemController::class, 'destroy'])->middleware('throttle:staff-writes')->name('items.destroy');

        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('throttle:staff-writes')->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('throttle:staff-writes')->name('suppliers.update');

        Route::put('batches/{batch}/price', [BatchPriceController::class, 'update'])->middleware('throttle:staff-writes')->name('batches.price');

        Route::post('walk-ins/{reservation}/void', [WalkInController::class, 'void'])->middleware('throttle:staff-writes')->name('walk-ins.void');
        Route::post('payments/{payment}/reviewed', [PaymentReviewController::class, 'markReviewed'])->middleware('throttle:staff-writes')->name('payments.reviewed');
        Route::post('payments/{payment}/reconcile', [PaymentReviewController::class, 'reconcile'])->middleware('throttle:staff-writes')->name('payments.reconcile');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('throttle:account-admin')->name('users.store');
        Route::put('users/{user}/role', [UserController::class, 'role'])->middleware('throttle:account-admin')->name('users.role');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('throttle:account-admin')->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->middleware('throttle:account-admin')->name('users.reactivate');

        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('throttle:staff-writes')->name('settings.update');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
