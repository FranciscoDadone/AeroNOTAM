<?php

namespace App\Contracts;

/**
 * A place aerodrome weather reports can be read from — observations (METAR) or
 * forecasts (TAF), depending on the implementation.
 *
 * Sources are interchangeable on purpose: the SMN publishes Argentina's reports
 * but sits behind a bot challenge that blocks us for stretches at a time, and a
 * weather report nobody can read is worth nothing. Implementations are tried in
 * order by AviationReportService until one answers.
 */
interface AviationReportSource
{
    /**
     * Short identifier carried through to the caller, so a reply can say which
     * source actually answered: 'smn', 'noaa'.
     *
     * It names the publisher rather than the endpoint, and that is what lets a
     * failing source be rested for every kind of report at once — the block is
     * against us at the source's front door, not against one of its pages.
     */
    public function name(): string;

    /**
     * Current reports for an ICAO station code.
     *
     * Returns plain rows rather than data objects so the result can be cached
     * without pinning a serialized class shape. An empty array is a valid
     * answer — the station simply has nothing published — and must not be
     * signalled by throwing.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException When the source could not be reached at all.
     */
    public function fetch(string $icaoCode): array;
}
