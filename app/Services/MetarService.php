<?php

namespace App\Services;

use App\DataObjects\Metar;
use RuntimeException;

/**
 * The single entry point for reading METARs.
 *
 * Everything that keeps the feature answering — the cache, the per-source
 * cooldown and the failover order — lives in AviationReportService and is
 * shared with the forecast side. What is left here is only what "the current
 * observation" means.
 */
class MetarService extends AviationReportService
{
    protected function kind(): string
    {
        return 'metar';
    }

    /**
     * The current observation for an ICAO station code, one per station.
     *
     * @return array<int, Metar>
     */
    public function getMetars(string $icaoCode): array
    {
        return array_map(Metar::fromArray(...), $this->currentRows($icaoCode));
    }

    /**
     * @param  array<int, string>  $failures
     */
    protected function unreachable(array $failures): RuntimeException
    {
        return new RuntimeException(
            'No se pudo obtener el METAR de ninguna fuente ('.implode(', ', $failures).').'
        );
    }
}
