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
     * The number every request from this endpoint is attributed to.
     *
     * Subscriptions are keyed by phone number, so exercising them off-channel
     * needs one. It is deliberately not a real E.164 number: anything created
     * here is visibly test data, and can never collide with a live subscriber.
     */
    protected const TEST_PHONE = 'whatsapp:+000000000000';

    /**
     * GET /api/whatsapp/test?mensaje=hay+notams+en+ezeiza[&boton=sub:SAEZ:12]
     *
     * Runs a message through the exact same pipeline the WhatsApp webhook
     * uses (airport matching + AI decoding + reply formatting) and returns
     * the resulting message(s) as JSON — lets you test the AI/bot behavior
     * without needing a working Twilio integration.
     *
     * "boton" stands in for the ButtonPayload Twilio sends when a user taps a
     * quick reply, which is otherwise unreachable without a real WhatsApp
     * conversation.
     */
    public function index(Request $request): JsonResponse
    {
        $mensaje = trim((string) $request->query('mensaje', ''));
        $boton = trim((string) $request->query('boton', ''));

        try {
            $respuesta = $this->bot->reply($mensaje, self::TEST_PHONE, $boton ?: null);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo generar una respuesta en este momento.',
            ], 502);
        }

        return response()->json([
            'mensaje' => $mensaje,
            'boton' => $boton ?: null,
            'cantidad_mensajes' => count($respuesta->messages),
            'respuestas' => $respuesta->messages,
            // Null when the reply carries no offer; otherwise the aerodrome the
            // button would act on, so the JSON shows what a tap would do.
            'boton_ofrecido' => $respuesta->button?->payloadValue,
        ]);
    }
}
