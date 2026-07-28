<?php

use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * The landing page is the whole web UI: everything else is a JSON API plus a
 * WhatsApp bot. All it needs from the app is the number to write to, which
 * lives in the Twilio config.
 */
Route::get('/', function () {
    $number = (string) preg_replace('/\D/', '', (string) config('services.twilio.whatsapp_from'));

    return view('landing', [
        'number' => $number,
        'link' => $number === '' ? null : 'https://wa.me/'.$number,
    ]);
});

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);
