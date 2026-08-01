<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * The number to write to, in the two shapes the public pages need it. Both of
 * them draw the same nav, and the nav is where the "Abrir WhatsApp" link lives.
 *
 * @return array{number: string, link: ?string}
 */
$whatsappLink = function (): array {
    $number = (string) preg_replace('/\D/', '', (string) config('services.whatsapp.number'));

    return [
        'number' => $number,
        'link' => $number === '' ? null : 'https://wa.me/'.$number,
    ];
};

/**
 * The landing page is the public web UI: everything else is a JSON API, a
 * WhatsApp bot and the panel behind /admin.
 */
Route::get('/', fn () => view('landing', $whatsappLink()));

/**
 * Required to publish the Meta app the bot talks WhatsApp through, and worth
 * having on its own: the bot keeps every message written to it.
 */
Route::get('/privacidad', fn () => view('privacidad', $whatsappLink()));

Route::get('/whatsapp/webhook', [WhatsappWebhookController::class, 'verify']);
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
