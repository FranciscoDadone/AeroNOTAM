<?php

namespace App\Console\Commands;

use App\Services\SmnPronareaService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the PRONAREA cache warm so a WhatsApp reply never has to be the
 * first thing to try the SMN.
 *
 * PRONAREA has no second source the way METAR/TAF fail over to NOAA (see
 * SmnPronareaService), and the SMN's own PRONAREA page warns it 502s under
 * load — especially, presumably, right after a bulletin is issued and every
 * client refreshes at once. So the resilience for this feature lives here
 * instead of in the request path: retry hard, in the background, on a
 * schedule tied to when the SMN actually reissues each bulletin (see
 * routes/console.php), rather than making a live reply wait on retries.
 *
 * One FIR being unreachable does not stop the others from refreshing — same
 * reasoning as metar:watch's per-station loop.
 */
class RefreshPronareaCache extends Command
{
    protected $signature = 'pronarea:refresh-cache';

    protected $description = 'Fetch the current PRONAREA for every FIR and warm the cache WhatsappBotService reads from.';

    public function handle(SmnPronareaService $pronarea): int
    {
        $attempts = (int) config('services.pronarea.refresh_attempts');
        $retrySeconds = (int) config('services.pronarea.refresh_retry_seconds');
        $firs = array_keys(SmnPronareaService::STATION_IDS);

        $refreshed = 0;

        foreach ($firs as $fir) {
            if ($this->refreshOne($pronarea, $fir, $attempts, $retrySeconds)) {
                $refreshed++;
            }
        }

        $this->info(sprintf('PRONAREA: %d de %d FIR actualizadas.', $refreshed, count($firs)));

        return $refreshed > 0 || $firs === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Keep asking for one FIR until a genuinely fresh bulletin comes back, or
     * give up after $attempts.
     *
     * forFir() can return successfully without a live fetch ever having
     * worked, serving the last good bulletin — that is the right answer for
     * a WhatsApp reply, but not for this command: it exists specifically to
     * make that fallback unnecessary, so a stale result here is treated the
     * same as a failure and retried, not accepted as done.
     */
    protected function refreshOne(SmnPronareaService $pronarea, string $fir, int $attempts, int $retrySeconds): bool
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $forecast = $pronarea->forFir($fir);

                if (! $forecast->stale) {
                    $this->info("FIR {$fir}: actualizado.");

                    return true;
                }
            } catch (Throwable $e) {
                if ($attempt === $attempts) {
                    report($e);
                }
            }

            if ($attempt < $attempts && $retrySeconds > 0) {
                sleep($retrySeconds);
            }
        }

        $this->warn("FIR {$fir}: no se pudo actualizar tras {$attempts} intentos.");

        return false;
    }
}
