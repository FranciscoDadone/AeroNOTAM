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
     * @var array<int, array{to: string, body: string, buttons: array<int, array{id: string, title: string}>}>
     */
    public array $sentWithButtons = [];

    /**
     * @var array<int, array{to: string, body: string, label: string, rows: array<int, array{id: string, title: string, description?: string}>}>
     */
    public array $sentWithList = [];

    /**
     * @var array<int, array{to: string, url: string, caption: string, filename: string}>
     */
    public array $documents = [];

    /**
     * @var array<int, array{to: string, latitude: float, longitude: float, name: string, address: string}>
     */
    public array $locations = [];

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
            throw new RuntimeException('WhatsApp no disponible.');
        }

        $this->sent[] = ['to' => $to, 'body' => $body];
    }

    public function sendWithButtons(string $to, string $body, array $buttons): void
    {
        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('WhatsApp no disponible.');
        }

        $this->sentWithButtons[] = ['to' => $to, 'body' => $body, 'buttons' => $buttons];
    }

    public function sendWithList(string $to, string $body, string $label, array $rows): void
    {
        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('WhatsApp no disponible.');
        }

        $this->sentWithList[] = ['to' => $to, 'body' => $body, 'label' => $label, 'rows' => $rows];
    }

    public function sendDocument(string $to, string $url, string $caption, string $filename): void
    {
        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('WhatsApp no disponible.');
        }

        $this->documents[] = ['to' => $to, 'url' => $url, 'caption' => $caption, 'filename' => $filename];
    }

    public function sendLocation(string $to, float $latitude, float $longitude, string $name, string $address): void
    {
        $this->attempts++;

        if ($this->shouldFail) {
            throw new RuntimeException('WhatsApp no disponible.');
        }

        $this->locations[] = [
            'to' => $to,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ];
    }

    public function indicateTyping(string $inboundMessageId): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('WhatsApp no disponible.');
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
     * The body of each message that carried buttons — the part a reader
     * actually sees.
     *
     * @return array<int, string>
     */
    public function buttonedBodies(): array
    {
        return array_column($this->sentWithButtons, 'body');
    }

    /**
     * The ids of every button offered, flattened across messages — what a tap
     * would send back to us.
     *
     * @return array<int, string>
     */
    public function buttonIds(): array
    {
        return array_merge(...array_map(
            fn (array $message) => array_column($message['buttons'], 'id'),
            $this->sentWithButtons,
        ) ?: [[]]);
    }

    /**
     * The same for the rows of every list sheet offered.
     *
     * @return array<int, string>
     */
    public function listRowIds(): array
    {
        return array_merge(...array_map(
            fn (array $message) => array_column($message['rows'], 'id'),
            $this->sentWithList,
        ) ?: [[]]);
    }
}
