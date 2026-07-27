<?php

use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * This project has no web UI — it's a JSON API plus a WhatsApp bot — so the
 * root simply describes what's available instead of serving a built frontend.
 */
Route::get('/', fn () => response()->json([
    'servicio' => 'NOTAMs Argentina (fuente: ANAC)',
    'endpoints' => [
        'GET /api/notams?aerodromo=EZE' => 'NOTAM activos de un aeródromo (agregar &decode=false para omitir la decodificación por IA).',
        'GET /api/notams/aerodromos' => 'Aeródromos que ANAC reporta con NOTAM activos.',
    ],
]));

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);
