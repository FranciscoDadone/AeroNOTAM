<?php

namespace App\Services;

use App\DataObjects\Metar;
use Throwable;

/**
 * Adds the plain-Spanish explanation to raw observations coming out of
 * SmnMetarService.
 *
 * Same split, and for the same reason, as NotamEnricher: the raw METAR is the
 * payload a pilot actually needs, the explanation is a convenience on top of
 * it, and a bug in the latter must degrade the response rather than destroy
 * it. There is no AI tier here — a METAR is a positional code that a
 * deterministic parser decodes exactly, so MetarDecoder is the only decoder
 * and its failure simply leaves the observation unexplained.
 */
class MetarEnricher
{
    public function __construct(protected MetarDecoder $decoder) {}

    /**
     * @param  array<int, Metar>  $metars
     * @return array<int, Metar>
     */
    public function enrich(array $metars): array
    {
        return array_map($this->enrichOne(...), $metars);
    }

    protected function enrichOne(Metar $metar): Metar
    {
        try {
            return $metar->withExplanation($this->decoder->explain($metar->raw));
        } catch (Throwable $e) {
            report($e);

            return $metar;
        }
    }
}
