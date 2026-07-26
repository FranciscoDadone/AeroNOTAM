<?php

use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);
