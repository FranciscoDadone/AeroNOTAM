<?php

namespace App\Services;

use App\DataObjects\AerometObservation;
use Throwable;

/**
 * Adds the plain-Spanish explanation to raw AEROMET observations, same split
 * as MetarEnricher and for the same reason: the raw line is what a pilot can
 * cross-check, the explanation a convenience laid on top that must degrade
 * gracefully rather than take the whole reply down with it.
 *
 * SynopDecoder is the only decoder AEROMET needs: every observation's raw
 * text is genuine SYNOP now that OgimetAerometSource is AEROMET's only
 * source (see its own docblock for why the SMN's own compact line — a
 * different grammar entirely — is no longer in the picture).
 */
class AerometEnricher
{
    public function __construct(protected SynopDecoder $decoder) {}

    /**
     * @param  array<int, AerometObservation>  $observations
     * @return array<int, AerometObservation>
     */
    public function enrich(array $observations): array
    {
        return array_map($this->enrichOne(...), $observations);
    }

    protected function enrichOne(AerometObservation $observation): AerometObservation
    {
        try {
            $lines = $this->decoder->explain($observation->raw);

            if ($observation->phenomenonNote !== null) {
                $lines[] = "Fenómeno: {$observation->phenomenonNote}";
            }

            return $observation->withExplanation($lines);
        } catch (Throwable $e) {
            report($e);

            return $observation;
        }
    }
}
