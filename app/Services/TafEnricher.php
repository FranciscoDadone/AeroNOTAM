<?php

namespace App\Services;

use App\DataObjects\Taf;
use Throwable;

/**
 * Adds the plain-Spanish explanation to raw forecasts coming out of TafService.
 *
 * Same split, and for the same reason, as MetarEnricher: the raw TAF is the
 * payload a pilot actually needs, the explanation is a convenience on top of
 * it, and a bug in the latter must degrade the response rather than destroy it.
 */
class TafEnricher
{
    public function __construct(protected TafDecoder $decoder) {}

    /**
     * @param  array<int, Taf>  $tafs
     * @return array<int, Taf>
     */
    public function enrich(array $tafs): array
    {
        return array_map($this->enrichOne(...), $tafs);
    }

    protected function enrichOne(Taf $taf): Taf
    {
        try {
            return $taf->withExplanation($this->decoder->explain($taf->raw));
        } catch (Throwable $e) {
            report($e);

            return $taf;
        }
    }
}
