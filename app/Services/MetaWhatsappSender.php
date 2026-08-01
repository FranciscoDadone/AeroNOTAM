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
     * One section, because there is only ever one thing being listed. Its title
     * is required as soon as there is more than one section and harmless when
     * there is not, so it reuses the button's own label rather than inventing a
     * second caption that would have to agree with it.
     */
    public function sendWithList(string $to, string $body, string $label, array $rows): void
    {
        $this->post([
            'to' => $this->recipient($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => $label,
                    'sections' => [[
                        'title' => $label,
                        'rows' => array_map($this->row(...), array_values($rows)),
                    ]],
                ],
            ],
        ])->throw();
    }

    public function sendDocument(string $to, string $url, string $caption, string $filename): void
    {
        $this->post([
            'to' => $this->recipient($to),
            'type' => 'document',
            'document' => ['link' => $url, 'caption' => $caption, 'filename' => $filename],
        ])->throw();
    }

    public function sendLocation(string $to, float $latitude, float $longitude, string $name, string $address): void
    {
        $this->post([
            'to' => $this->recipient($to),
            'type' => 'location',
            'location' => array_filter([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address,
            ], fn (mixed $value) => $value !== ''),
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
     * @param  array{id: string, title: string, description?: string}  $row
     * @return array<string, string>
     */
    protected function row(array $row): array
    {
        $description = trim($row['description'] ?? '');

        return array_filter([
            'id' => $row['id'],
            'title' => $row['title'],
            'description' => $description,
        ]);
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
     *
     * The trailing branch exists for test numbers only. Their allow-list is
     * matched against the string as sent, before Meta normalises it, and an
     * Argentine mobile written the way WhatsApp reports it -- 549 + area +
     * line -- never matches an entry the panel stored, because the panel
     * stores the same line as 54 + area + 15 + line. Dropping the 9 reaches
     * the same wa_id: Meta resolves 542954465433 and 5492954465433 to one
     * another. Off unless WHATSAPP_STRIP_AR_MOBILE_NINE says otherwise, since
     * a live number has no allow-list and needs no such surgery.
     */
    protected function recipient(string $to): string
    {
        $number = ltrim(Str::after($to, 'whatsapp:'), '+');

        if (config('services.whatsapp.strip_ar_mobile_nine') && str_starts_with($number, '549')) {
            return '54'.substr($number, 3);
        }

        return $number;
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
