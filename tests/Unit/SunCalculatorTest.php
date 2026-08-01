<?php

use App\DataObjects\SunTimes;
use App\Services\SunCalculator;
use Carbon\CarbonImmutable;

/**
 * This is the fallback the SHN's 34 localities do not reach, and the only thing
 * that makes it usable is that its numbers are the SHN's numbers. So the test
 * that matters is the comparison: the same day, the same place, checked against
 * what hidro.gov.ar actually prints.
 */
function sunCalculator(): SunCalculator
{
    return app(SunCalculator::class);
}

/**
 * Santa Rosa on 01/07/2026, the same row tests/Fixtures/shn carries: the SHN
 * publishes 08:01, 08:30, 18:12 and 18:40 hora oficial argentina. Three land on
 * the minute and the fourth a minute later — which is the whole claim, that a
 * computed answer is the published one to the minute rather than merely close.
 */
it('lands on the SHN table it stands in for', function () {
    $times = sunCalculator()->forCoordinates('SANTA ROSA', -36.5883333, -64.2758333, sunDate('2026-07-01'));

    expect($times->dawn->format('H:i'))->toBe('11:01')
        ->and($times->sunrise->format('H:i'))->toBe('11:30')
        ->and($times->sunset->format('H:i'))->toBe('21:12')
        ->and($times->dusk->format('H:i'))->toBe('21:41');
});

/**
 * UTC, like every other hour the bot serves — a flight plan is written in it.
 */
it('answers in UTC on the local day asked for', function () {
    $times = sunCalculator()->forCoordinates('SANTA ROSA', -36.5883333, -64.2758333, sunDate('2026-07-01'));

    expect($times->sunset->timezoneName)->toBe('UTC')
        ->and($times->sunset->toDateString())->toBe('2026-07-01')
        ->and($times->sunset->setTimezone('-03:00')->format('d/m H:i'))->toBe('01/07 18:12');
});

/**
 * A number we worked out is not the same claim as one the State published, and
 * the reply is built to say which — so the flag has to be on the object.
 */
it('marks its answers as calculated', function () {
    $times = sunCalculator()->forCoordinates('TANDIL', -37.2374, -59.2279, sunDate('2026-07-01'));

    expect($times->source)->toBe(SunTimes::CALCULATED)
        ->and($times->isCalculated())->toBeTrue()
        ->and($times->place)->toBe('TANDIL');
});

/**
 * Below the polar circle there are days with no sunrise and days with no
 * sunset, and Argentina has aerodromes down there — Belgrano II sits at 77°S.
 * Reported under the SHN's own symbols, so the reply renders both sources
 * through the same three sentences instead of learning a second vocabulary.
 */
it('reports a polar night and a midnight sun the way the SHN does', function () {
    $winter = sunCalculator()->forCoordinates('BELGRANO II', -77.87, -34.63, sunDate('2026-06-21'));

    expect($winter->sunrise)->toBeNull()
        ->and($winter->symbolFor('sunrise'))->toBe('----')
        ->and($winter->symbolFor('dusk'))->toBe('----');

    $summer = sunCalculator()->forCoordinates('BELGRANO II', -77.87, -34.63, sunDate('2026-12-21'));

    expect($summer->sunset)->toBeNull()
        ->and($summer->symbolFor('sunset'))->toBe('***')
        ->and($summer->symbolFor('dawn'))->toBe('////');
});

function sunDate(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, '-03:00')->startOfDay();
}
