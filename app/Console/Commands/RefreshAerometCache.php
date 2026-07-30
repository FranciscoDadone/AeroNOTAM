<?php

namespace App\Console\Commands;

use App\Services\AerometService;
use App\Support\AerometStationResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the AEROMET cache warm so a WhatsApp reply is rarely the first thing
 * to try OGIMET.
 *
 * Retries each of AerometStationResolver::FIR_GROUPS independently, same
 * shape as RefreshPronareaCache retrying each FIR independently, and for the
 * same reason: one group being unreachable must not stop the others from
 * refreshing, and one group answering must not be mistaken for the whole job
 * being done — confirmed live, back when the SMN was still tried first here
 * (see OgimetAerometSource's own docblock for why it no longer is): a first
 * pass that got Córdoba and Resistencia back and quit there because
 * "something" came back left Ezeiza — the group with by far the most
 * commonly asked-about stations — unwarmed for a full TTL. Retrying per
 * group instead of as one unit is what fixed that, and stays worth keeping
 * even against a source as reliable as OGIMET has been: a scheduled run has
 * nobody waiting on it and can afford to insist through an isolated blip in
 * a way a live WhatsApp reply never could.
 */
class RefreshAerometCache extends Command
{
    protected $signature = 'aeromet:refresh-cache';

    protected $description = 'Fetch every AEROMET FIR group and warm the cache WhatsappBotService reads from.';

    public function handle(AerometService $aeromet): int
    {
        $attempts = (int) config('services.aeromet.refresh_attempts');
        $retrySeconds = (int) config('services.aeromet.refresh_retry_seconds');
        $groups = count(AerometStationResolver::FIR_GROUPS);

        $refreshed = 0;
        $warmed = [];

        foreach (array_keys(AerometStationResolver::FIR_GROUPS) as $index) {
            $rows = $this->refreshOne($aeromet, $index, $groups, $attempts, $retrySeconds);

            if ($rows === null) {
                continue;
            }

            $refreshed++;
            $warmed = [...$warmed, ...$rows];
        }

        $this->info(sprintf('AEROMET: %d de %d grupos actualizados.', $refreshed, $groups));

        if ($warmed !== []) {
            $this->info(sprintf('AEROMET: %d estaciones: %s', count($warmed), $this->stationNames($warmed)));
        }

        return $refreshed > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Keep asking for one FIR group until it answers, or give up after
     * $attempts — that one group's own stations then fall back to whatever
     * AerometStationObservation already has for them, the same as a live
     * reply would.
     *
     * @return array<int, array<string, mixed>>|null what this group warmed,
     *                                               or null if it never
     *                                               answered.
     */
    protected function refreshOne(AerometService $aeromet, int $index, int $groups, int $attempts, int $retrySeconds): ?array
    {
        $label = $index + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->info("AEROMET: grupo {$label}/{$groups}, intento {$attempt}/{$attempts}...");

            try {
                return $aeromet->refreshGroup($index);
            } catch (Throwable $e) {
                $this->warn("AEROMET: grupo {$label}/{$groups}, intento {$attempt}/{$attempts} fallido ({$e->getMessage()}).");

                if ($attempt === $attempts) {
                    report($e);
                }
            }

            if ($attempt < $attempts && $retrySeconds > 0) {
                sleep($retrySeconds);
            }
        }

        $this->warn("AEROMET: grupo {$label}/{$groups}: no se pudo actualizar tras {$attempts} intentos.");

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function stationNames(array $rows): string
    {
        $names = array_map(fn (array $row) => (string) $row['airport_name'], $rows);
        sort($names);

        return implode(', ', $names);
    }
}
