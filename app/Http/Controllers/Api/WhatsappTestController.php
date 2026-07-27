<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsappBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WhatsappTestController extends Controller
{
    public function __construct(protected WhatsappBotService $bot) {}

    /**
     * GET /api/whatsapp/test?mensaje=hay+notams+en+ezeiza
     *
     * Runs a message through the exact same pipeline the WhatsApp webhook
     * uses (airport matching + AI decoding + reply formatting) and returns
     * the resulting message(s) as JSON — lets you test the AI/bot behavior
     * without needing a working Twilio integration.
     */
    public function index(Request $request): JsonResponse
    {
        $mensaje = trim((string) $request->query('mensaje', ''));

        try {
            $respuestas = $this->bot->reply($mensaje);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo generar una respuesta en este momento.',
            ], 502);
        }

        return response()->json([
            'mensaje' => $mensaje,
            'cantidad_mensajes' => count($respuestas),
            'respuestas' => $respuestas,
        ]);
    }
}
