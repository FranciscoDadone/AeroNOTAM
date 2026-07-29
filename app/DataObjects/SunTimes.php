<?php

namespace App\DataObjects;

use Carbon\CarbonImmutable;

/**
 * The sun's schedule for one city on one day, as the Servicio de Hidrografía
 * Naval publishes it.
 *
 * The four moments are UTC instants, like every other time the bot serves, even
 * though the SHN prints them in Argentine official time — a pilot cross-checks
 * against a flight plan, and that is written in UTC. The date, on the other
 * hand, is the local one: "hoy" for whoever is asking rolls over at midnight in
 * Argentina, not at 21:00 the day before.
 *
 * Any of the four can be null. Above 66° there are days when the sun never
 * rises, never sets, or never gets far enough below the horizon for night to
 * fall, and the SHN prints "----", "***" or "////" instead of an hour. The
 * symbol is kept in $symbols so the reply can say which of the three happened
 * rather than silently dropping the line.
 */
final readonly class SunTimes
{
    /**
     * @param  CarbonImmutable  $date  The day itself, in Argentine official time.
     * @param  array<string, string>  $symbols  Raw SHN symbol, keyed by moment, for the ones with no hour.
     */
    public function __construct(
        public string $city,
        public CarbonImmutable $date,
        public ?CarbonImmutable $dawn,
        public ?CarbonImmutable $sunrise,
        public ?CarbonImmutable $sunset,
        public ?CarbonImmutable $dusk,
        public array $symbols = [],
    ) {}

    public function symbolFor(string $moment): ?string
    {
        return $this->symbols[$moment] ?? null;
    }
}
