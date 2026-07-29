<?php

use App\Services\SmnPronareaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PRONAREA has no second source the way METAR/TAF fail over to NOAA, so what
 * matters here beyond parsing is the one thing that replaces a failover: the
 * last bulletin fetched successfully is kept and served, marked stale, when
 * the SMN cannot be reached at all.
 */
beforeEach(function () {
    Cache::flush();
});

function pronareaService(): SmnPronareaService
{
    return app(SmnPronareaService::class);
}

it('parses a bulletin from the real SMN markup', function () {
    fakePronarea();

    $forecast = pronareaService()->forFir('EZE');

    expect($forecast->fir)->toBe('EZE')
        ->and($forecast->raw)->toStartWith('PRONAREA FIR EZE VALIDEZ 1604')
        ->and($forecast->raw)->toContain('FCST: DIA 1604')
        ->and($forecast->stale)->toBeFalse();
});

it('queries the SMN by the station id that issues each FIR bulletin', function () {
    fakePronarea();

    pronareaService()->forFir('eze');

    // Verified against a live browser session: PRONAREA has no ICAO/FIR text
    // parameter the way METAR/TAF do — the "mensajes" app expects the SMN's
    // own internal id for the one station that issues that FIR's bulletin
    // (see SmnPronareaService::STATION_IDS), sent as "{id}=on".
    Http::assertSent(fn ($request) => str_contains($request->url(), 'observacion=pronarea')
        && str_contains($request->url(), '87582=on'));
});

it('knows the issuing station id for every FIR', function () {
    expect(SmnPronareaService::STATION_IDS)->toBe([
        'EZE' => '87582',
        'CBA' => '87344',
        'DOZ' => '87418',
        'SIS' => '87155',
        'CRV' => '87860',
    ]);
});

it('caches the fetch so a second call does not ask again', function () {
    fakePronarea();

    pronareaService()->forFir('EZE');
    pronareaService()->forFir('EZE');

    Http::assertSentCount(1);
});

it('throws when the SMN publishes nothing for a FIR', function () {
    fakePronarea(Http::response(smnFixture('pronarea-empty.html')));

    expect(fn () => pronareaService()->forFir('EZE'))->toThrow(RuntimeException::class);
});

it('fails when the SMN cannot be reached and nothing was ever fetched', function () {
    fakePronarea(Http::response(smnFixture('challenge.html'), 403));

    expect(fn () => pronareaService()->forFir('EZE'))->toThrow(RuntimeException::class);
});

it('throws for a FIR with no known issuing station', function () {
    expect(fn () => pronareaService()->forFir('ZZZ'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

/**
 * The fallback that stands in for a second source: once a bulletin has been
 * fetched, a later block does not leave the question unanswered — it answers
 * with that same bulletin, marked stale, rather than an error.
 */
it('serves the last good bulletin, marked stale, when the SMN is blocked', function () {
    // A sequence rather than two fakePronarea() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one (see MetarServiceTest for the same lesson).
    Http::fake([
        '*observacion=pronarea*' => Http::sequence()
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    $first = pronareaService()->forFir('EZE');

    // Simulate the short-lived cache expiring while the long-lived "last
    // good" copy is still within its window — the situation this fallback
    // exists for.
    Cache::forget('pronarea:EZE');

    $second = pronareaService()->forFir('EZE');

    expect($second->stale)->toBeTrue()
        ->and($second->raw)->toBe($first->raw)
        ->and($second->fetchedAt->toIso8601String())->toBe($first->fetchedAt->toIso8601String());
});
