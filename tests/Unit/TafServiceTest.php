<?php

use App\Services\TafService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The forecast side shares its cache, cooldown and failover with the
 * observation side (AviationReportService), so the ground those cover is
 * already held down by MetarServiceTest. What is tested here is what is
 * genuinely different: the SMN's TAF screen, NOAA's TAF endpoint, and the fact
 * that a rest earned on one channel is honoured on the other.
 */
beforeEach(function () {
    Cache::flush();
});

function tafService(): TafService
{
    return app(TafService::class);
}

it('parses a taf from the real SMN markup', function () {
    fakeTaf();

    $tafs = tafService()->getTafs('SAEZ');

    expect($tafs)->toHaveCount(1)
        ->and($tafs[0]->station)->toBe('SAEZ')
        ->and($tafs[0]->airportName)->toBe('EZEIZA')
        ->and($tafs[0]->issuedAt)->toBe('27 - 17:00')
        ->and($tafs[0]->raw)->toStartWith('TAF SAEZ 271700Z 2718/2818');
});

/**
 * The TAF screen labels its header cell "Estacion" where the METAR screen says
 * "Aeropuerto". Both are stripped, so an aerodrome is named the same way
 * whichever channel it came off.
 */
it('reads the aerodrome name off the forecast screen', function () {
    fakeTaf(Http::response(smnTafWith('TAF SAWH 271700Z 2718/2818 25015KT =', 'USHUAIA')));

    expect(tafService()->getTafs('SAWH')[0]->airportName)->toBe('USHUAIA');
});

it('keeps stations apart when several are returned', function () {
    fakeTaf(Http::response(smnFixture('taf-multi.html')));

    $tafs = tafService()->getTafs('SABE');

    expect($tafs)->toHaveCount(4);

    $byStation = collect($tafs)->keyBy->station;

    expect($byStation['SABE']->airportName)->toBe('AEROPARQUE J. NEWBERY')
        ->and($byStation['SAME']->airportName)->toBe('MENDOZA')
        ->and($byStation['SAME']->raw)->toContain('TAF SAME 271700Z 2718/2818 14010KT CAVOK')
        ->and($byStation['SAWH']->airportName)->toBe('USHUAIA')
        ->and($byStation['SACO']->airportName)->toBe('CORDOBA');
});

/**
 * An amendment carries "AMD" between the keyword and the station, so a station
 * matcher that expected them adjacent would attribute the forecast to nobody.
 */
it('reads the station past an amendment marker', function () {
    fakeTaf(Http::response(smnTafWith('TAF AMD SAEZ 271900Z 2719/2818 18025KT =')));

    $taf = tafService()->getTafs('SAEZ')[0];

    expect($taf->station)->toBe('SAEZ')
        ->and($taf->isAmended())->toBeTrue();
});

it('returns no forecast for a station the SMN does not publish', function () {
    fakeTaf(Http::response(smnFixture('taf-empty.html')));

    expect(tafService()->getTafs('ZZZZ'))->toBe([]);
});

it('queries the SMN forecast screen by ICAO code', function () {
    fakeTaf();

    tafService()->getTafs('saez');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'CODIGO=SAEZ')
        && str_contains($request->url(), 'observacion=taf')
        && str_contains($request->url(), 'tipoEstacion=OACI'));
});

/*
|--------------------------------------------------------------------------
| Failover
|--------------------------------------------------------------------------
*/

it('falls through to NOAA when the SMN is blocking', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    $tafs = tafService()->getTafs('SAEZ');

    expect($tafs)->toHaveCount(1)
        ->and($tafs[0]->station)->toBe('SAEZ')
        ->and($tafs[0]->source)->toBe('noaa')
        ->and($tafs[0]->issuedAt)->toBe('27 - 17:00');
});

/**
 * NOAA returns its own decoded breakdown alongside the text. It is ignored: the
 * point of a relay is to hand the SMN's forecast on unaltered, and our decoder
 * is what turns it into Spanish.
 */
it('takes only the raw forecast from NOAA', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403), Http::response([[
        'icaoId' => 'SAEZ',
        'rawTAF' => 'TAF SAEZ 271700Z 2718/2818 02005KT 9999',
        'fcsts' => [['wdir' => 999, 'visib' => 'nonsense']],
    ]]));

    expect(tafService()->getTafs('SAEZ')[0]->raw)
        ->toBe('TAF SAEZ 271700Z 2718/2818 02005KT 9999');
});

it('asks NOAA far enough back to clear an issue cycle', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    tafService()->getTafs('SAEZ');

    // TAFs go out every six hours; a shorter window would read a station that
    // simply had not reissued yet as having no forecast at all.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api/data/taf')
        || (int) $request->data()['hours'] >= 6);
});

it('only fails once every source has been tried', function () {
    fakeTaf(
        Http::response(smnFixture('challenge.html'), 403),
        Http::response('down', 503),
    );

    tafService()->getTafs('SAEZ');
})->throws(RuntimeException::class);

/*
|--------------------------------------------------------------------------
| Only the current forecast
|--------------------------------------------------------------------------
|
| NOAA hands back several issues of a TAF within the look-back window. Only the
| one in force is served: a superseded forecast beside the current one, with
| nothing marking which is which, is worse than no forecast.
|
*/

it('keeps only the forecast in force', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403), Http::response([
        ['icaoId' => 'SAEZ', 'rawTAF' => 'TAF SAEZ 271700Z 2718/2818 02005KT 9999'],
        ['icaoId' => 'SAEZ', 'rawTAF' => 'TAF SAEZ 271100Z 2712/2812 18010KT 9999'],
    ]));

    $tafs = tafService()->getTafs('SAEZ');

    expect($tafs)->toHaveCount(1)
        ->and($tafs[0]->raw)->toContain('271700Z');
});

it('picks the newest even when the source lists it last', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403), Http::response([
        ['icaoId' => 'SAEZ', 'rawTAF' => 'TAF SAEZ 271100Z 2712/2812 18010KT 9999'],
        ['icaoId' => 'SAEZ', 'rawTAF' => 'TAF SAEZ 271700Z 2718/2818 02005KT 9999'],
    ]));

    expect(tafService()->getTafs('SAEZ')[0]->raw)->toContain('271700Z');
});

/*
|--------------------------------------------------------------------------
| The cooldown is shared with the observation channel
|--------------------------------------------------------------------------
|
| The SMN's challenge is aimed at us, not at one of its pages. Asking it for a
| forecast seconds after it refused us an observation would keep our own block
| alive exactly as retrying would — which is the thing the cooldown exists to
| stop.
|
*/

it('respects a cooldown earned on the metar channel', function () {
    Cache::put('weather:cooldown:smn', true, 900);

    fakeTaf();

    expect(tafService()->getTafs('SAEZ')[0]->source)->toBe('noaa');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mensajes/index.php'));
});

it('rests the SMN for the metar channel too once the forecast query fails', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    tafService()->getTafs('SAEZ');

    expect(Cache::has('weather:cooldown:smn'))->toBeTrue();
});

it('caches the forecast separately from the observation', function () {
    fakeMetar();
    fakeTaf();

    tafService()->getTafs('SAEZ');

    // The METAR cache entry must not answer a TAF lookup, or the aerodrome
    // would be handed an observation labelled as a forecast.
    expect(Cache::has('taf:SAEZ'))->toBeTrue()
        ->and(Cache::has('metar:SAEZ'))->toBeFalse();
});

it('does not hit the SMN twice for the same station', function () {
    fakeTaf();

    tafService()->getTafs('SAEZ');
    tafService()->getTafs('SAEZ');

    Http::assertSentCount(1);
});
