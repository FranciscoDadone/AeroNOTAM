<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
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

        // Twilio anchors the typing indicator to the message being answered.
        $messageSid = (string) $request->input('MessageSid');

        // Set when the message is a tapped quick-reply button rather than typed
        // text: it carries the action id we put on the button ourselves. Body
        // arrives too, holding the button's visible caption — which is why the
        // dispatch guard below still holds for a tap.
        $buttonPayload = (string) $request->input('ButtonPayload');

        // WhatsApp's display name for the sender. Absent when the user has none
        // set, and always self-declared — good enough to put a face on the
        // message log, never good enough to identify anyone by.
        $profileName = trim((string) $request->input('ProfileName'));

        if ($from !== '' && ($body !== '' || $buttonPayload !== '')) {
            // Logged here rather than inside the job so that a message which is
            // never answered still shows up in the panel.
            $log = WhatsappMessage::create([
                'phone' => $from,
                'profile_name' => $profileName ?: null,
                'message_sid' => $messageSid ?: null,
                'body' => $body,
                'button_payload' => $buttonPayload ?: null,
            ]);

            ProcessWhatsappMessage::dispatch($from, $body, $messageSid ?: null, $buttonPayload ?: null, $log);
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
