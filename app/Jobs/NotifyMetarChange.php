<?php

namespace App\Jobs;

use App\Contracts\WhatsappSender;
use App\DataObjects\Metar;
use App\Jobs\Concerns\DeliversWhatsappReply;
use App\Services\WhatsappBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one "the weather changed" alert.
 *
 * Split out from the watcher command on purpose: the round that finds the
 * changes talks to the SMN and must not be held up — or worse, aborted midway —
 * by WhatsApp being slow for one recipient. Each alert retries on its own.
 */
class NotifyMetarChange implements ShouldQueue
{
    use DeliversWhatsappReply, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $metar  A Metar as plain row data — the same reason
     *                                       AviationReportService caches rows and not objects:
     *                                       a queued payload outlives the deploy that wrote it.
     * @param  array<int, string>  $changes  From MetarConditions::changesFrom().
     */
    public function __construct(
        protected string $phone,
        protected string $anacCode,
        protected array $metar,
        protected array $changes,
        protected string $expiryLabel,
    ) {}

    /**
     * WhatsApp's answer for a message sent outside the 24-hour window the
     * user's own message opened. Subscriptions are capped at that same 24 hours
     * (services.metar.watch.max_ttl) precisely so this cannot happen — but when
     * it does, the window is shut and no amount of retrying will reopen it.
     */
    protected const OUT_OF_WINDOW = 131047;

    public function handle(WhatsappBotService $bot, WhatsappSender $sender): void
    {
        $reply = $bot->changeAlert(
            $this->anacCode,
            Metar::fromArray($this->metar),
            $this->changes,
            $this->expiryLabel,
        );

        try {
            $this->deliver($sender, $this->phone, $reply);
        } catch (RequestException $e) {
            if ((int) $e->response->json('error.code') !== self::OUT_OF_WINDOW) {
                throw $e;
            }

            Log::warning('El aviso quedó fuera de la ventana de 24 h y no se entregó.', [
                'phone' => $this->phone,
                'anac_code' => $this->anacCode,
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('No se pudo enviar un aviso de cambio de METAR.', [
            'phone' => $this->phone,
            'anac_code' => $this->anacCode,
            'exception' => $e?->getMessage(),
        ]);
    }
}
