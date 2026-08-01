<?php

namespace App\Services;

use App\DataObjects\SunTimes;
use Carbon\CarbonImmutable;

/**
 * The sun's schedule worked out from a coordinate, for the places the SHN's
 * table does not reach.
 *
 * The SHN stays the answer wherever it publishes one: it is the Argentine
 * authority on the matter, and a number we computed has no business overruling
 * a number the State published. But it publishes 34 localities and MADHEL lists
 * 712 aerodromes, and "esa ciudad no está en la lista del SHN" is a poor answer
 * to someone asking whether they get into Tandil before last light — MADHEL
 * already tells us to the second where Tandil is.
 *
 * date_sun_info() is PHP's own implementation of the standard NOAA solar
 * position algorithm, and it works to the same definitions the SHN's table
 * does: the sun's upper limb at the horizon with refraction for salida and
 * puesta, and its centre 6° below it for the crepúsculo civil, which is the one
 * the local rules are written against. Checked against the SHN's own Santa Rosa
 * table — the four moments land within a minute of what it prints.
 *
 * What this does not model is terrain. The published horizon is the sea one,
 * and an aerodrome in a valley loses its sun earlier than any of this says;
 * that is equally true of the SHN's own table, so it is a property of the
 * quantity rather than of the fallback.
 */
class SunCalculator
{
    /**
     * Our four moments, and the keys date_sun_info() returns them under.
     */
    protected const KEYS = [
        'dawn' => 'civil_twilight_begin',
        'sunrise' => 'sunrise',
        'sunset' => 'sunset',
        'dusk' => 'civil_twilight_end',
    ];

    /**
     * The sun over one coordinate on one day, in UTC.
     */
    public function forCoordinates(string $place, float $latitude, float $longitude, CarbonImmutable $date): SunTimes
    {
        // Midday, not the midnight $date carries: date_sun_info() reads a
        // timestamp as "the day it falls on", and midnight in Argentine
        // official time is already 03:00 UTC. Midday leaves no room for the
        // question of which day that is.
        $info = date_sun_info($date->setTime(12, 0)->getTimestamp(), $latitude, $longitude);

        $moments = [];
        $symbols = [];

        foreach (self::KEYS as $moment => $key) {
            $value = $info[$key];

            if (! is_int($value)) {
                // Not a time but a whole polar day or night. Reported under
                // the SHN's own symbols so the reply renders both sources
                // through the same three sentences.
                $moments[$moment] = null;
                $symbols[$moment] = $this->symbolFor($moment, $value);

                continue;
            }

            // Seconds are precision this quantity does not have and nobody
            // reads. Rounded rather than truncated, so 08:29:57 is the 08:30
            // the SHN prints and not the 08:29 a cast would give.
            $moments[$moment] = CarbonImmutable::createFromTimestamp($value, 'UTC')->roundMinute();
        }

        return new SunTimes(
            place: $place,
            date: $date,
            dawn: $moments['dawn'],
            sunrise: $moments['sunrise'],
            sunset: $moments['sunset'],
            dusk: $moments['dusk'],
            symbols: $symbols,
            source: SunTimes::CALCULATED,
        );
    }

    /**
     * date_sun_info() answers true when the sun stays above the threshold the
     * moment is defined by all day, and false when it never reaches it.
     *
     * For salida and puesta the threshold is the horizon, so true is the
     * midnight sun ("no se pone") and false the polar night ("no sale"). For
     * the crepúsculos it is 6° below the horizon, and true means the sun never
     * gets under it — night never falls, which is the SHN's "////".
     */
    protected function symbolFor(string $moment, bool $alwaysAbove): string
    {
        if (! $alwaysAbove) {
            return '----';
        }

        return in_array($moment, ['dawn', 'dusk'], true) ? '////' : '***';
    }
}
