<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Twilio\Security\RequestValidator;

class WhatsappWebhookController extends Controller
{
    /**
     * POST /whatsapp/webhook
     *
     * Twilio hits this on every inbound WhatsApp message. We verify the
     * request really came from Twilio, queue the (potentially slow, AI-backed)
     * reply, and acknowledge immediately so Twilio doesn't time out or retry.
     */
    public function handle(Request $request): SymfonyResponse
    {
        if (! $this->isFromTwilio($request)) {
            abort(403);
        }

        $from = (string) $request->input('From');
        $body = (string) $request->input('Body');

        if ($from !== '' && $body !== '') {
            ProcessWhatsappMessage::dispatch($from, $body);
        }

        return response('<Response></Response>', Response::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }

    protected function isFromTwilio(Request $request): bool
    {
        $token = config('services.twilio.token');

        if (blank($token)) {
            return false;
        }

        $signature = $request->header('X-Twilio-Signature', '');

        return (new RequestValidator($token))->validate(
            $signature,
            $request->fullUrl(),
            $request->post()
        );
    }
}
