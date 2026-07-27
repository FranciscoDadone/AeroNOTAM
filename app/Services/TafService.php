<?php

namespace App\Services;

use App\DataObjects\Taf;
use RuntimeException;

/**
 * The single entry point for reading TAFs, and the forecast counterpart of
 * MetarService — same cache, same cooldown, same failover order, all inherited.
 *
 * Only the current forecast is served. A TAF is superseded the moment the next
 * issue or an amendment goes out, and putting a withdrawn forecast beside the
 * one in force would be worse than showing nothing.
 */
class TafService extends AviationReportService
{
    protected function kind(): string
    {
        return 'taf';
    }

    /**
     * The forecast in force for an ICAO station code, one per station.
     *
     * @return array<int, Taf>
     */
    public function getTafs(string $icaoCode): array
    {
        return array_map(Taf::fromArray(...), $this->currentRows($icaoCode));
    }

    /**
     * @param  array<int, string>  $failures
     */
    protected function unreachable(array $failures): RuntimeException
    {
        return new RuntimeException(
            'No se pudo obtener el TAF de ninguna fuente ('.implode(', ', $failures).').'
        );
    }
}
