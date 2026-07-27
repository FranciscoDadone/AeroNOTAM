<?php

use App\Services\SmnMetarService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Like the ANAC scraper, this rests entirely on the SMN's HTML, so these tests
 * run against markup captured verbatim from the live site. If the SMN changes
 * it, these are what tell us — the failure mode otherwise is an empty result
 * served silently, as though the aerodrome simply had no observation.
 */
beforeEach(function () {
    Cache::flush();
});

function smn(): SmnMetarService
{
    return app(SmnMetarService::class);
}

it('parses a metar from the real SMN markup', function () {
    fakeSmn();

    $metars = smn()->getMetars('SAEZ');

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
    fakeSmn(Http::response(smnFixture('metar-multi.html')));

    $metars = smn()->getMetars('SABE');

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
    fakeSmn(Http::response(smnWith('METAR SAWH 271200Z 32005KT 9999 M03/M07 Q1010 =', 'USHUAIA')));

    expect(smn()->getMetars('SAEZ')[0]->station)->toBe('SAWH');
});

it('returns no observations for a station the SMN does not publish', function () {
    fakeSmn(Http::response(smnFixture('metar-empty.html')));

    expect(smn()->getMetars('ZZZZ'))->toBe([]);
});

/**
 * Cloudflare sits in front of the SMN and intermittently answers with an
 * interstitial instead of the page. It is not a rejection of the request —
 * the same request succeeds moments later — so the service retries past it
 * rather than reporting a failure the user can do nothing about.
 */
it('retries past the cloudflare interstitial', function () {
    Http::fake([
        '*/mensajes/index.php*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 200)
            ->push(smnFixture('metar-saez.html'), 200),
    ]);

    expect(smn()->getMetars('SAEZ')[0]->station)->toBe('SAEZ');

    Http::assertSentCount(3);
});

it('gives up once the retries are exhausted', function () {
    config(['services.smn.attempts' => 2]);

    fakeSmn(Http::response(smnFixture('challenge.html'), 403));

    smn()->getMetars('SAEZ');
})->throws(RuntimeException::class);

it('raises on an http failure', function () {
    fakeSmn(Http::response('gateway down', 503));

    smn()->getMetars('SAEZ');
})->throws(RuntimeException::class);

/**
 * The cache is the actual defence against the rate limiting in front of the
 * SMN, not the retry loop — so it matters that it works.
 */
it('does not hit the SMN twice for the same station', function () {
    fakeSmn();

    smn()->getMetars('SAEZ');
    smn()->getMetars('SAEZ');

    Http::assertSentCount(1);
});

it('queries the SMN by ICAO code', function () {
    fakeSmn();

    smn()->getMetars('saez');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'CODIGO=SAEZ')
        && str_contains($request->url(), 'observacion=metar')
        && str_contains($request->url(), 'tipoEstacion=OACI'));
});
