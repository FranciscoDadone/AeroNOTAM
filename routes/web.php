<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * The landing page is the public web UI: everything else is a JSON API, a
 * WhatsApp bot and the panel behind /admin. All it needs from the app is the
 * number to write to, which lives in the Twilio config.
 */
Route::get('/', function () {
    $number = (string) preg_replace('/\D/', '', (string) config('services.twilio.whatsapp_from'));

    return view('landing', [
        'number' => $number,
        'link' => $number === '' ? null : 'https://wa.me/'.$number,
    ]);
});

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/mensajes', [MessageController::class, 'index'])->name('messages.index');
        Route::get('/mensajes/{message}', [MessageController::class, 'show'])->name('messages.show');
    });
});
