<?php

namespace App\Jobs;

use App\Contracts\WhatsappSender;
use App\DataObjects\WhatsappReply;
use App\Services\WhatsappBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /**
     * ANAC, the SMN and Twilio all have transient failures, and the user is
     * sitting in a chat waiting for an answer — worth retrying before giving up.
     */
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $from,
        protected string $body,
        protected ?string $messageSid = null,
        protected ?string $buttonPayload = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsappBotService $bot, WhatsappSender $sender): void
    {
        $this->indicateTyping($sender);

        try {
            $reply = $bot->reply($this->body, $this->from, $this->buttonPayload);
        } catch (Throwable $e) {
            report($e);

            // Deliberately not rethrown: if building the reply failed, a retry
            // will most likely fail the same way, and the user is better served
            // by an apology now than by silence while the backoff runs.
            $reply = WhatsappReply::of('Tuve un problema procesando tu consulta. Probá de nuevo en unos minutos.');
        }

        $this->deliver($sender, $reply);
    }

    /**
     * Send failures *do* propagate, so the queue retries delivery.
     *
     * The button, when there is one, rides on the last message only: a long
     * answer arrives as a numbered run, and repeating the same offer under each
     * part would read as several offers instead of one.
     */
    protected function deliver(WhatsappSender $sender, WhatsappReply $reply): void
    {
        $last = count($reply->messages) - 1;

        foreach ($reply->messages as $i => $message) {
            if ($i === $last && $reply->button !== null) {
                $sender->sendWithButtons(
                    $this->from,
                    $reply->button->contentSid,
                    $reply->button->variables($message),
                    $message."\n\n".$reply->button->fallbackHint,
                );

                continue;
            }

            $sender->send($this->from, $message);
        }
    }

    /**
     * Cosmetic only — building the answer takes seconds (ANAC, the SMN and the
     * AI decoder), and the dots tell the user we're on it. A failure here must
     * never cost the user their reply, so it's swallowed rather than retried.
     */
    protected function indicateTyping(WhatsappSender $sender): void
    {
        if (blank($this->messageSid)) {
            return;
        }

        try {
            $sender->indicateTyping($this->messageSid);
        } catch (Throwable $e) {
            Log::debug('No se pudo mostrar el indicador de escritura.', [
                'from' => $this->from,
                'message_sid' => $this->messageSid,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reached once the retries above are exhausted. The user gets nothing at
     * all in that case, so leave enough context behind to follow up manually.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('No se pudo responder un mensaje de WhatsApp.', [
            'from' => $this->from,
            'body' => $this->body,
            'button_payload' => $this->buttonPayload,
            'exception' => $e?->getMessage(),
        ]);
    }
}
