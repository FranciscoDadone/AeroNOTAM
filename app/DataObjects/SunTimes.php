<?php

namespace App\DataObjects;

use Carbon\CarbonImmutable;

/**
 * The sun's schedule for one place on one day.
 *
 * Two things produce it: the Servicio de Hidrografía Naval's table, which is the
 * Argentine authority and covers 34 localities, and a calculation from the
 * aerodrome's own coordinates for everywhere else. $source says which, because a
 * pilot deciding against last light is entitled to know whether the number was
 * published or computed.
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
 * rather than silently dropping the line; the calculation reports the same three
 * cases under the same symbols, so the reply does not care where it came from.
 */
final readonly class SunTimes
{
    public const SHN = 'shn';

    public const CALCULATED = 'calculado';

    /**
     * @param  string  $place  The SHN locality, or the aerodrome the coordinates belong to.
     * @param  CarbonImmutable  $date  The day itself, in Argentine official time.
     * @param  array<string, string>  $symbols  SHN symbol, keyed by moment, for the ones with no hour.
     */
    public function __construct(
        public string $place,
        public CarbonImmutable $date,
        public ?CarbonImmutable $dawn,
        public ?CarbonImmutable $sunrise,
        public ?CarbonImmutable $sunset,
        public ?CarbonImmutable $dusk,
        public array $symbols = [],
        public string $source = self::SHN,
    ) {}

    public function symbolFor(string $moment): ?string
    {
        return $this->symbols[$moment] ?? null;
    }

    public function isCalculated(): bool
    {
        return $this->source === self::CALCULATED;
    }
}
