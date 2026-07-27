<?php

use App\Services\MetarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The SMN scraping rests entirely on captured markup (tests/Fixtures/smn/), and
 * the failover on top of it is what keeps the feature answering while the SMN
 * is blocking us. Both are exercised here through MetarService, which is the
 * only entry point the rest of the application uses.
 */
beforeEach(function () {
    Cache::flush();
});

function metarService(): MetarService
{
    return app(MetarService::class);
}

it('parses a metar from the real SMN markup', function () {
    fakeMetar();

    $metars = metarService()->getMetars('SAEZ');

    expect($metars)->toHaveCount(1)
        ->and($metars[0]->station)->toBe('SAEZ')
        ->and($metars[0]->airportName)->toBe('EZEIZA')
        ->and($metars[0]->observedAt)->toBe('27 - 14:00')
        ->and($metars[0]->raw)->toBe('METAR SAEZ 271400Z 03009KT 9999 BKN008 OVC100 15/14 Q1009 NOSIG =');
});

/**
 * The SMN answers a multi-station query with one table per station. Attributing
 * an observation to the wrong aerodrome is about the worst thing this parser
 * could do, so the station/name pairing is asserted explicitly.
 */
it('keeps stations apart when several are returned', function () {
    fakeMetar(Http::response(smnFixture('metar-multi.html')));

    $metars = metarService()->getMetars('SABE');

    expect($metars)->toHaveCount(4);

    $byStation = collect($metars)->keyBy->station;

    expect($byStation['SABE']->airportName)->toBe('AEROPARQUE J. NEWBERY')
        ->and($byStation['SAME']->airportName)->toBe('MENDOZA')
        ->and($byStation['SAME']->raw)->toBe('METAR SAME 271400Z 16011KT CAVOK 15/05 Q1005 =')
        ->and($byStation['SAWH']->airportName)->toBe('USHUAIA')
        ->and($byStation['SACO']->airportName)->toBe('CORDOBA');
});

/**
 * The station is read out of the report itself rather than echoed back from
 * the query, since the SMN can relay an observation for a different aerodrome
 * than the one asked for.
 */
it('takes the station code from the report body', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAWH 271200Z 32005KT 9999 M03/M07 Q1010 =', 'USHUAIA')));

    expect(metarService()->getMetars('SAEZ')[0]->station)->toBe('SAWH');
});

it('returns no observations for a station the SMN does not publish', function () {
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    expect(metarService()->getMetars('ZZZZ'))->toBe([]);
});

/**
 * Cloudflare sits in front of the SMN and intermittently answers with an
 * interstitial instead of the page. An isolated challenge is not a rejection —
 * the same request succeeds moments later — so a couple of retries clear it
 * without needing to fail over at all.
 */
it('retries past an isolated cloudflare interstitial', function () {
    Http::fake([
        '*/mensajes/index.php*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('metar-saez.html'), 200),
        '*aviationweather.gov*' => Http::response(noaaMetarFixture()),
    ]);

    $metars = metarService()->getMetars('SAEZ');

    expect($metars[0]->station)->toBe('SAEZ')
        ->and($metars[0]->source)->toBe('smn');
});

/*
|--------------------------------------------------------------------------
| Failover
|--------------------------------------------------------------------------
|
| The SMN blocks us outright for stretches at a time, which is what made this
| necessary: a weather report nobody can read is worth nothing. NOAA relays
| the same SMN-issued reports over the WMO exchange, so falling through to it
| keeps answering with the same data rather than with a different opinion.
|
*/

it('falls through to NOAA when the SMN is blocking', function () {
    fakeMetar(Http::response(smnFixture('challenge.html'), 403));

    $metars = metarService()->getMetars('SAEZ');

    expect($metars)->toHaveCount(1)
        ->and($metars[0]->station)->toBe('SAEZ')
        ->and($metars[0]->source)->toBe('noaa')
        ->and($metars[0]->raw)->toBe('METAR SAEZ 271700Z 33007KT 9999 BKN014 OVC017 17/14 Q1007 NOSIG')
        ->and($metars[0]->observedAt)->toBe('27 - 17:00');
});

/*
|--------------------------------------------------------------------------
| Only the current observation
|--------------------------------------------------------------------------
|
| The sources disagree about how much history they hand back — the SMN returns
| only the latest observation, NOAA a couple of hours of them. Passing that
| straight through made the answer depend on which source replied, and put a
| superseded reading next to the current one with nothing marking which was
| which.
|
*/

it('keeps only the most recent observation', function () {
    fakeMetar(Http::response(smnFixture('challenge.html'), 403), Http::response([
        [
            'icaoId' => 'SAZR',
            'name' => 'Santa Rosa',
            'rawOb' => 'METAR SAZR 271700Z 16009KT CAVOK 17/09 Q1006',
        ],
        [
            'icaoId' => 'SAZR',
            'name' => 'Santa Rosa',
            'rawOb' => 'METAR SAZR 271600Z 17015KT CAVOK 17/10 Q1006',
        ],
    ]));

    $metars = metarService()->getMetars('SAZR');

    expect($metars)->toHaveCount(1)
        ->and($metars[0]->raw)->toContain('271700Z')
        ->and($metars[0]->observedAt)->toBe('27 - 17:00');
});

/**
 * Order is not guaranteed by either source, so the newest has to be chosen
 * rather than assumed to come first.
 */
it('picks the newest even when the source lists it last', function () {
    fakeMetar(Http::response(smnFixture('challenge.html'), 403), Http::response([
        ['icaoId' => 'SAZR', 'rawOb' => 'METAR SAZR 271600Z 17015KT CAVOK 17/10 Q1006'],
        ['icaoId' => 'SAZR', 'rawOb' => 'METAR SAZR 271700Z 16009KT CAVOK 17/09 Q1006'],
    ]));

    expect(metarService()->getMetars('SAZR')[0]->raw)->toContain('271700Z');
});

/**
 * A METAR carries the day of the month but not the month, so around a rollover
 * the raw digits sort backwards: the 31st would beat the 1st and an eight-hour
 * -old reading would be served as the current weather.
 */
it('resolves the day of month across a month boundary', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 01:00:00', 'UTC'));

    fakeMetar(Http::response(smnFixture('challenge.html'), 403), Http::response([
        ['icaoId' => 'SAZR', 'rawOb' => 'METAR SAZR 312300Z 17015KT CAVOK 17/10 Q1006'],
        ['icaoId' => 'SAZR', 'rawOb' => 'METAR SAZR 010030Z 16009KT CAVOK 17/09 Q1006'],
    ]));

    expect(metarService()->getMetars('SAZR')[0]->raw)->toContain('010030Z');

    Carbon::setTestNow();
});

/**
 * Collapsing is per station, so a response covering several aerodromes still
 * yields one current observation for each.
 */
it('keeps one observation per station', function () {
    fakeMetar(Http::response(smnFixture('metar-multi.html')));

    expect(metarService()->getMetars('SABE'))->toHaveCount(4);
});

it('prefers the SMN when it answers', function () {
    fakeMetar();

    expect(metarService()->getMetars('SAEZ')[0]->source)->toBe('smn');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'aviationweather.gov'));
});

it('only fails once every source has been tried', function () {
    fakeMetar(
        Http::response(smnFixture('challenge.html'), 403),
        Http::response('down', 503),
    );

    metarService()->getMetars('SAEZ');
})->throws(RuntimeException::class);

/**
 * An empty answer is an answer: the station genuinely has no observation. It
 * must not be mistaken for a failure, or every quiet aerodrome would trigger a
 * needless second request to the fallback.
 */
it('does not fail over when the SMN legitimately has nothing', function () {
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    expect(metarService()->getMetars('SAEZ'))->toBe([]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'aviationweather.gov'));
});

/*
|--------------------------------------------------------------------------
| Cooldown
|--------------------------------------------------------------------------
*/

/**
 * This is the fix for the block feeding itself. The SMN's challenge tightens
 * the more it is hit, so once it has refused us we stop asking for a while —
 * otherwise every incoming message would hold our own block open.
 */
it('stops asking a source that just failed', function () {
    fakeMetar(Http::response(smnFixture('challenge.html'), 403));

    metarService()->getMetars('SAEZ');
    Cache::forget('metar:SAEZ');
    metarService()->getMetars('SAEZ');

    $smnRequests = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'mensajes/index.php'))
        ->count();

    // Only the first lookup reached the SMN; the second went straight to NOAA.
    expect($smnRequests)->toBe((int) config('services.smn.attempts'));
});

it('goes back to the SMN once the cooldown expires', function () {
    config(['services.smn.attempts' => 2]);

    // A sequence rather than two fakeMetar() calls: Http::fake() merges stubs and
    // the first match wins, so a later fake cannot override an earlier one.
    Http::fake([
        '*/mensajes/index.php*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('metar-saez.html'), 200),
        '*aviationweather.gov*' => Http::response(noaaMetarFixture()),
    ]);

    expect(metarService()->getMetars('SAEZ')[0]->source)->toBe('noaa');

    Cache::forget('metar:SAEZ');
    Cache::forget('weather:cooldown:smn');

    expect(metarService()->getMetars('SAEZ')[0]->source)->toBe('smn');
});

/**
 * A cooldown must never be the reason nobody gets an answer: with every source
 * resting, the service tries them all rather than failing without asking.
 */
it('ignores the cooldowns when every source is resting', function () {
    Cache::put('weather:cooldown:smn', true, 900);
    Cache::put('weather:cooldown:noaa', true, 900);

    fakeMetar();

    expect(metarService()->getMetars('SAEZ')[0]->source)->toBe('smn');
});

/**
 * The cache is the actual defence against the rate limiting in front of the
 * SMN, not the retry loop — so it matters that it works.
 */
it('does not hit the SMN twice for the same station', function () {
    fakeMetar();

    metarService()->getMetars('SAEZ');
    metarService()->getMetars('SAEZ');

    Http::assertSentCount(1);
});

it('queries the SMN by ICAO code', function () {
    fakeMetar();

    metarService()->getMetars('saez');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'CODIGO=SAEZ')
        && str_contains($request->url(), 'observacion=metar')
        && str_contains($request->url(), 'tipoEstacion=OACI'));
});
