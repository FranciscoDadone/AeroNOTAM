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
     * @var array<int, array{to: string, contentSid: string, variables: array<int, string>, fallback: string}>
     */
    public array $sentWithButtons = [];

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

    /**
     * Mirrors the real sender's fallback: a blank content SID means no template
     * is registered, and the message goes out as plain text. Recording it in
     * $sent rather than $sentWithButtons is what lets a test assert that the
     * user still got the answer.
     */
    public function sendWithButtons(string $to, string $contentSid, array $variables, string $fallback): void
    {
        if ($contentSid === '') {
            $this->send($to, $fallback);

            return;
        }

        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('Twilio no disponible.');
        }

        $this->sentWithButtons[] = [
            'to' => $to,
            'contentSid' => $contentSid,
            'variables' => $variables,
            'fallback' => $fallback,
        ];
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

    /**
     * The rendered body of each templated message — what "{{1}}" was filled in
     * with, which is the part a reader actually sees.
     *
     * @return array<int, string>
     */
    public function templatedBodies(): array
    {
        return array_map(
            fn (array $message): string => $message['variables'][1] ?? '',
            $this->sentWithButtons,
        );
    }
}
