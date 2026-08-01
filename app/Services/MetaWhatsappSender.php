<?php

namespace App\Services;

use App\Contracts\WhatsappSender;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * WhatsApp through Meta's own Cloud API, which is one POST per message to the
 * number's endpoint — no SDK, and no templates to register beforehand: the
 * buttons travel inside the same request that carries the text.
 */
class MetaWhatsappSender implements WhatsappSender
{
    public function send(string $to, string $body): void
    {
        $this->post([
            'to' => $this->recipient($to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ])->throw();
    }

    public function sendWithButtons(string $to, string $body, array $buttons): void
    {
        $this->post([
            'to' => $this->recipient($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => array_map(fn (array $button) => [
                        'type' => 'reply',
                        'reply' => ['id' => $button['id'], 'title' => $button['title']],
                    ], array_values($buttons)),
                ],
            ],
        ])->throw();
    }

    /**
     * The same endpoint as a message: marking the inbound message read is what
     * puts the dots up, and the two are one request.
     *
     * Failures are swallowed rather than retried — the indicator is a courtesy
     * while the answer is being built, and an answer that arrives without it is
     * still the answer.
     */
    public function indicateTyping(string $inboundMessageId): void
    {
        try {
            $this->post([
                'status' => 'read',
                'message_id' => $inboundMessageId,
                'typing_indicator' => ['type' => 'text'],
            ])->throw();
        } catch (Throwable $e) {
            Log::debug('No se pudo mostrar el indicador de escritura.', [
                'message_id' => $inboundMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(array $payload): Response
    {
        return $this->client()->post('messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            ...$payload,
        ]);
    }

    /**
     * Meta wants bare digits; everything upstream of here — the message log,
     * the subscriptions — stores the address as WhatsApp writes it.
     */
    protected function recipient(string $to): string
    {
        return ltrim(Str::after($to, 'whatsapp:'), '+');
    }

    /**
     * Built per call rather than in the constructor so that merely resolving
     * this class — which the container does for anything type-hinting
     * WhatsappSender — doesn't require credentials to be present.
     */
    protected function client(): PendingRequest
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');

        if (blank($phoneNumberId) || blank($token)) {
            throw new RuntimeException('WhatsApp Cloud API credentials are not configured (WHATSAPP_PHONE_NUMBER_ID / WHATSAPP_ACCESS_TOKEN).');
        }

        $version = config('services.whatsapp.graph_version');

        return Http::withToken($token)
            ->baseUrl("https://graph.facebook.com/{$version}/{$phoneNumberId}/")
            ->asJson()
            ->timeout(30);
    }
}
