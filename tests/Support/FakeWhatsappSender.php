<?php

namespace Tests\Support;

use App\Contracts\WhatsappSender;
use RuntimeException;

class FakeWhatsappSender implements WhatsappSender
{
    /**
     * @var array<int, array{to: string, body: string}>
     */
    public array $sent = [];

    /**
     * @var array<int, string>
     */
    public array $typing = [];

    public int $attempts = 0;

    public function __construct(protected bool $shouldFail = false) {}

    public function send(string $to, string $body): void
    {
        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('Twilio no disponible.');
        }

        $this->sent[] = ['to' => $to, 'body' => $body];
    }

    public function indicateTyping(string $inboundMessageId): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Twilio no disponible.');
        }

        $this->typing[] = $inboundMessageId;
    }

    /**
     * @return array<int, string>
     */
    public function bodies(): array
    {
        return array_column($this->sent, 'body');
    }
}
