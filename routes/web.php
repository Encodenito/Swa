<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Post-login landing: send each role to its own area.
Route::get('dashboard', function (Request $request) {
    return redirect()->route($request->user()->role->homeRoute());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified', 'role:customer'])
    ->prefix('account')->name('account.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Account/Home'))->name('home');
    });

Route::middleware(['auth', 'verified', 'role:staff,admin'])
    ->prefix('staff')->name('staff.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Staff/Home'))->name('home');
    });

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Admin/Home'))->name('home');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
