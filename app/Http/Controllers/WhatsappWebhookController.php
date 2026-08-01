<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WhatsappWebhookController extends Controller
{
    /**
     * GET /whatsapp/webhook
     *
     * Meta calls this once when the webhook is subscribed — and again whenever
     * the callback URL changes — and will only accept the endpoint if it echoes
     * the challenge back. The verify token is a string we invent and paste into
     * both sides; it proves the endpoint is ours, not that a request is Meta's,
     * which is what the signature below is for.
     */
    public function verify(Request $request): SymfonyResponse
    {
        $token = config('services.whatsapp.verify_token');

        if (blank($token)
            || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals((string) $token, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge'), Response::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST /whatsapp/webhook
     *
     * Meta hits this on every inbound WhatsApp message. We verify the request
     * really came from Meta, queue the (potentially slow, AI-backed) reply, and
     * acknowledge immediately — anything but a prompt 200 is read as failure
     * and retried.
     */
    public function handle(Request $request): SymfonyResponse
    {
        if (! $this->isFromMeta($request)) {
            abort(403);
        }

        foreach ($this->messages($request) as [$message, $contacts]) {
            $this->receive($message, $contacts);
        }

        return response('', Response::HTTP_OK);
    }

    /**
     * Every inbound message in the payload, each paired with the contact list
     * of the change it arrived in.
     *
     * One request can carry several: Meta batches by entry and by change, and
     * a webhook that only reads the first message drops the rest. Changes that
     * carry `statuses` instead are delivery receipts for messages we sent —
     * nothing here acts on those, and treating them as inbound would fill the
     * panel with rows nobody wrote.
     *
     * @return array<int, array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}>
     */
    protected function messages(Request $request): array
    {
        $found = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $contacts = (array) ($value['contacts'] ?? []);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $found[] = [(array) $message, $contacts];
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int, array<string, mixed>>  $contacts
     */
    protected function receive(array $message, array $contacts): void
    {
        // Meta gives the number in bare digits; the rest of the app — the log,
        // the subscriptions — stores it the way WhatsApp writes it.
        $from = isset($message['from']) ? 'whatsapp:+'.$message['from'] : '';

        // Set when the message is a tapped quick-reply button rather than typed
        // text: the id we put on the button ourselves comes back verbatim. The
        // caption rides along as the body, which is why the guard below still
        // holds for a tap.
        $buttonPayload = (string) ($message['interactive']['button_reply']['id'] ?? '');

        $body = (string) ($message['text']['body']
            ?? $message['interactive']['button_reply']['title']
            ?? '');

        // Meta anchors the typing indicator to the message being answered.
        $messageId = (string) ($message['id'] ?? '');

        // WhatsApp's display name for the sender. Absent when the user has none
        // set, and always self-declared — good enough to put a face on the
        // message log, never good enough to identify anyone by.
        $profileName = trim((string) ($contacts[0]['profile']['name'] ?? ''));

        if ($from === '' || ($body === '' && $buttonPayload === '')) {
            return;
        }

        $attributes = [
            'phone' => $from,
            'profile_name' => $profileName ?: null,
            'body' => $body,
            'button_payload' => $buttonPayload ?: null,
        ];

        // Logged here rather than inside the job so that a message which is
        // never answered still shows up in the panel.
        //
        // Keyed on the message id because Meta retries a webhook it considers
        // unacknowledged, and the same message arriving twice must not produce
        // two answers. A message without an id — none in practice — is always
        // treated as new rather than collapsing onto every other id-less one.
        $log = $messageId === ''
            ? WhatsappMessage::create($attributes)
            : WhatsappMessage::firstOrCreate(['message_sid' => $messageId], $attributes);

        if (! $log->wasRecentlyCreated) {
            return;
        }

        ProcessWhatsappMessage::dispatch($from, $body, $messageId ?: null, $buttonPayload ?: null, $log);
    }

    /**
     * Meta signs the raw request body with the app secret. It has to be the
     * body as it arrived — re-encoding the parsed array would change the bytes
     * and never match.
     */
    protected function isFromMeta(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');

        if (blank($secret)) {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);

        return hash_equals($expected, $signature);
    }
}
