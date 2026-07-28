<?php

namespace App\Jobs;

use App\Contracts\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One plain WhatsApp message, sent on its own.
 *
 * For the notices the application originates rather than replies with — the
 * "your alert expired" line, and anything like it. It exists so that a command
 * doing a round of work never has to hold a Twilio call open in the middle of
 * it, or lose the round because one recipient's delivery failed.
 */
class SendWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        protected string $phone,
        protected string $body,
    ) {}

    public function handle(WhatsappSender $sender): void
    {
        $sender->send($this->phone, $this->body);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('No se pudo enviar un mensaje de WhatsApp.', [
            'phone' => $this->phone,
            'exception' => $e?->getMessage(),
        ]);
    }
}
