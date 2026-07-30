<?php

namespace App\Services;

use App\DataObjects\AerometObservation;
use Throwable;

/**
 * Adds the plain-Spanish explanation to raw AEROMET observations, same split
 * as MetarEnricher and for the same reason: the raw line is what a pilot can
 * cross-check, the explanation a convenience laid on top that must degrade
 * gracefully rather than take the whole reply down with it.
 */
class AerometEnricher
{
    public function __construct(protected AerometDecoder $decoder) {}

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
            $lines = $this->decoder->explain($this->withoutStationName($observation));

            if ($observation->phenomenonNote !== null) {
                $lines[] = "Fenómeno: {$observation->phenomenonNote}";
            }

            return $observation->withExplanation($lines);
        } catch (Throwable $e) {
            report($e);

            return $observation;
        }
    }

    /**
     * The line opens with the station's own name ("JUNIN 090/06KT ..."),
     * which AerometDecoder has no use for — it is already known from
     * $observation->station — and whose shape (one word, or several for
     * "MAR DEL PLATA"/"AEROPARQUE J. NEWBERY") the decoder's token grammar
     * cannot tell apart from a group it should decode.
     */
    protected function withoutStationName(AerometObservation $observation): string
    {
        $prefix = '/^'.preg_quote($observation->station, '/').'\s+/';

        return preg_replace($prefix, '', $observation->raw, limit: 1) ?? $observation->raw;
    }
}
