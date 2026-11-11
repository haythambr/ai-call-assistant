<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('contacts')->name('contacts.')->controller(ContactController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/import', 'importForm')->name('import');
        Route::post('/import', 'import')->middleware('throttle:20,1')->name('import.store');
        Route::get('/export', 'export')->name('export');
        Route::delete('/', 'bulkDestroy')->name('bulk-destroy');
        Route::get('/{contact}', 'show')->name('show');
        Route::get('/{contact}/edit', 'edit')->name('edit');
        Route::put('/{contact}', 'update')->name('update');
        Route::delete('/{contact}', 'destroy')->name('destroy');
        Route::post('/{contact}/call', 'call')->middleware('throttle:30,1')->name('call');
        Route::post('/{contact}/opt-out', 'optOut')->name('opt-out');
    });

    Route::prefix('calls')->name('calls.')->controller(CallController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/feed', 'feed')->name('feed');
        Route::get('/settings', 'settings')->name('settings');
        Route::post('/simulate', 'simulate')->middleware('throttle:20,1')->name('simulate');
        Route::post('/call-number', 'callNumber')->middleware('throttle:30,1')->name('call-number');
        Route::get('/{call}', 'show')->name('show');
        Route::post('/{call}/retry', 'retry')->name('retry');
    });

    Route::prefix('campaigns')->name('campaigns.')->controller(CampaignController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
        Route::get('/{campaign}', 'show')->name('show');
        Route::post('/{campaign}/pause', 'pause')->name('pause');
        Route::post('/{campaign}/resume', 'resume')->name('resume');
        Route::post('/{campaign}/start-now', 'startNow')->name('start-now');
        Route::post('/{campaign}/cancel', 'cancel')->name('cancel');
        Route::delete('/{campaign}', 'destroy')->name('destroy');
    });

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/webhook-token', [SettingsController::class, 'rotateWebhookToken'])->name('settings.webhook-token');
});
