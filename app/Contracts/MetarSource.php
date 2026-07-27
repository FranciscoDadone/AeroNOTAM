<?php

namespace App\Contracts;

/**
 * A place METAR observations can be read from.
 *
 * Sources are interchangeable on purpose: the SMN publishes Argentina's
 * observations but sits behind a bot challenge that blocks us for stretches at
 * a time, and a weather report nobody can read is worth nothing. Implementations
 * are tried in order by MetarService until one answers.
 */
interface MetarSource
{
    /**
     * Short identifier carried through to the caller, so a reply can say which
     * source actually answered: 'smn', 'noaa'.
     */
    public function name(): string;

    /**
     * Current observations for an ICAO station code, most recent first.
     *
     * Returns plain rows rather than Metar objects so the result can be cached
     * without pinning a serialized class shape. An empty array is a valid
     * answer — the station simply has no observation — and must not be
     * signalled by throwing.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException When the source could not be reached at all.
     */
    public function fetch(string $icaoCode): array;
}
