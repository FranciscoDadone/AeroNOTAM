<?php

namespace App\Jobs;

use App\Contracts\WhatsappSender;
use App\DataObjects\WhatsappReply;
use App\Jobs\Concerns\DeliversWhatsappReply;
use App\Models\WhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsappMessage implements ShouldQueue
{
    use DeliversWhatsappReply, Queueable;

    public int $timeout = 120;

    /**
     * ANAC, the SMN and WhatsApp all have transient failures, and the user is
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

        // The row the webhook already opened for this message, when there is
        // one: the local test endpoint and the tests call the job directly.
        protected ?WhatsappMessage $log = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsappBotService $bot, WhatsappSender $sender): void
    {
        $startedAt = microtime(true);

        $this->indicateTyping($sender);

        try {
            $reply = $bot->reply($this->body, $this->from, $this->buttonPayload);
            $this->log?->recordUnderstanding($bot->lastContext());
        } catch (Throwable $e) {
            report($e);
            $this->log?->recordUnderstanding($bot->lastContext());
            $this->log?->recordFailure($e->getMessage());

            // Deliberately not rethrown: if building the reply failed, a retry
            // will most likely fail the same way, and the user is better served
            // by an apology now than by silence while the backoff runs.
            $reply = WhatsappReply::of('Tuve un problema procesando tu consulta. Probá de nuevo en unos minutos.');
        }

        $this->deliver($sender, $this->from, $reply);

        // In the order they went out, attachments first — a reply that is only
        // PDFs would otherwise leave an answered row with nothing in it.
        $this->log?->recordReply([
            ...array_column($reply->documents, 'caption'),
            ...array_column($reply->outbound(), 0),
        ], $startedAt);
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
        $this->log?->recordFailure($e?->getMessage());

        Log::error('No se pudo responder un mensaje de WhatsApp.', [
            'from' => $this->from,
            'body' => $this->body,
            'button_payload' => $this->buttonPayload,
            'exception' => $e?->getMessage(),
        ]);
    }
}
