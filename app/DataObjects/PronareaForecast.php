<?php

namespace App\DataObjects;

use Carbon\CarbonImmutable;

/**
 * One FIR's PRONAREA, verbatim.
 *
 * The raw text is the whole of it for now — PRONAREA's three sections
 * (SIGFENOM, WIND/T, FCST) are each a coded format of their own, and decoding
 * them the way TAF's enricher does is separate work. What a pilot needs first
 * is the bulletin itself, exactly as the SMN issued it.
 */
final readonly class PronareaForecast
{
    /**
     * @param  bool  $stale  True when this is not the current bulletin but the
     *                       last one successfully fetched, served because the
     *                       SMN could not be reached just now — see
     *                       SmnPronareaService.
     */
    public function __construct(
        public string $fir,
        public string $raw,
        public CarbonImmutable $fetchedAt,
        public bool $stale = false,
    ) {}
}
