<?php

use App\Services\AerometService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The SMN scraping rests on captured markup (tests/Fixtures/smn/), same as
 * MetarServiceTest. Unlike METAR/TAF there is no NOAA failover to exercise —
 * AEROMET has no relayed equivalent — so this only covers the single source,
 * its cache, and its cooldown.
 *
 * AerometService fetches one of SmnAerometSource::FIR_GROUPS at a time — only
 * the group(s) a request's stations actually belong to, cached under
 * "aeromet:{group index}" — rather than one request for all 119 stations or
 * one per group regardless of who was asked about. Junín, Mar del Plata and
 * Neuquén — every station these fixtures name — all fall under FIR EZEIZA,
 * group index 0, which is why asking about any of them costs exactly one
 * request.
 */
beforeEach(function () {
    Cache::flush();
});

function aerometService(): AerometService
{
    return app(AerometService::class);
}

it('parses an aeromet observation from the real SMN markup', function () {
    fakeAeromet();

    $observations = aerometService()->getObservations('87548');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->station)->toBe('JUNIN')
        ->and($observations[0]->observedAt)->toBe('29 - 21:00')
        ->and($observations[0]->raw)->toBe('JUNIN 090/06KT 12KM 4Ci19800FT 16/07 Q1018.4')
        ->and($observations[0]->phenomenonNote)->toBeNull();
});

it('keeps stations apart when several are returned', function () {
    fakeAeromet(Http::response(smnFixture('aeromet-multi.html')));

    $observations = aerometService()->getObservations('87548 87692 87715');

    expect($observations)->toHaveCount(3);

    $byStation = collect($observations)->keyBy->station;

    expect($byStation['JUNIN']->raw)->toBe('JUNIN 090/06KT 12KM 4Ci19800FT 16/07 Q1018.4')
        ->and($byStation['MAR DEL PLATA']->raw)->toBe('MAR DEL PLATA 200/06KT 10KM 6Sc2500FT 3Ac9900FT 11/07 Q1019.7')
        ->and($byStation['NEUQUEN']->raw)->toBe('NEUQUEN 110/04KT 10KM FBL RA CONS 3St3000FT 5Sc4900FT 05/03 Q1017.5')
        ->and($byStation['NEUQUEN']->phenomenonNote)->toBe(
            'Lluvia. Continua, no congelandose, debil en el momento de la observacion.'
        );
});

it('returns no observations for a code none of the fir groups name, without asking the smn at all', function () {
    expect(aerometService()->getObservations('00000'))->toBe([]);

    Http::assertNothingSent();
});

it('asks the SMN for every station in a group at once, not just the one requested', function () {
    fakeAeromet();

    aerometService()->getObservations('87548');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'observacion=aeromet')
        && str_contains($request->url(), 'operacion=consultar')
        && str_contains($request->url(), '87548=on')
        // A second station from the same FIR group, never asked about,
        // confirms this is a group request and not a per-station one.
        && str_contains($request->url(), '87641=on'));
});

it('does not hit the SMN again for a station in an already-cached group', function () {
    fakeAeromet(Http::response(smnFixture('aeromet-multi.html')));

    aerometService()->getObservations('87548');
    aerometService()->getObservations('87692');
    aerometService()->getObservations('87715');

    Http::assertSentCount(1);
});

it('fetches only the groups the requested stations actually belong to', function () {
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-junin.html'))
            ->push(smnFixture('aeromet-junin.html')),
    ]);

    // 87548 (Junín) is FIR EZEIZA; 87344 (Córdoba) is FIR CORDOBA — two
    // different groups, so this costs two requests, not one.
    aerometService()->getObservations('87548 87344');

    Http::assertSentCount(2);
});

it('only loses the stations of the one group that could not be reached', function () {
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-junin.html')) // FIR EZEIZA (87548)
            ->push(smnFixture('challenge.html'), 403) // FIR CORDOBA (87344)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    $observations = aerometService()->getObservations('87548 87344');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->station)->toBe('JUNIN');
});

it('fails when the SMN cannot be reached', function () {
    fakeAeromet(Http::response(smnFixture('challenge.html'), 403));

    aerometService()->getObservations('87548');
})->throws(RuntimeException::class);

/*
|--------------------------------------------------------------------------
| Stale fallback
|--------------------------------------------------------------------------
|
| AEROMET has no second source the way METAR/TAF fail over to NOAA — SYNOP
| surface observations are not relayed over OPMET — so a group that cannot
| reach the SMN at all is ridden out by serving each station's last
| observation fetched successfully instead of failing outright, same fix as
| SmnPronareaService.
|
| A group that DOES reach the SMN falls back the same way, station by
| station, when it simply leaves one of its own stations out ("Error: El
| código [X] es erroneo") — confirmed live, and not distinguishable from a
| station genuinely having nothing published, from the response alone.
|
*/

it('serves the last good observation, marked stale, when the smn cannot be reached at all', function () {
    // A sequence rather than two fakeAeromet() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one.
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-junin.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    aerometService()->getObservations('87548');

    Cache::forget('aeromet:0');

    $observations = aerometService()->getObservations('87548');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->raw)->toBe('JUNIN 090/06KT 12KM 4Ci19800FT 16/07 Q1018.4')
        ->and($observations[0]->stale)->toBeTrue();
});

it('serves a station own last good reading when it drops out of an otherwise successful group response', function () {
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-neuquen.html')),
    ]);

    aerometService()->getObservations('87548 87715');

    Cache::forget('aeromet:0');

    // The second response only carries Neuquén — Junín "erroneo"'d out of
    // it, same as a live round does — but it still answers fresh for
    // Neuquén and stale for Junín, in the same call.
    $observations = aerometService()->getObservations('87548 87715');
    $byStation = collect($observations)->keyBy->station;

    expect($observations)->toHaveCount(2)
        ->and($byStation['JUNIN']->stale)->toBeTrue()
        ->and($byStation['NEUQUEN']->stale)->toBeFalse();
});

it('still fails when the smn cannot be reached and nothing was ever fetched', function () {
    fakeAeromet(Http::response(smnFixture('challenge.html'), 403));

    aerometService()->getObservations('87548');
})->throws(RuntimeException::class);

it('warms the cache for one group without asking about any one station', function () {
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    aerometService()->refreshGroup(0);

    Http::assertSentCount(1);

    // The SMN is unreachable now, but refreshGroup() already populated
    // Junín's last-good entry — this still answers, marked stale.
    Cache::forget('aeromet:0');
    $observations = aerometService()->getObservations('87548');

    Http::assertSentCount(3);
    expect($observations)->toHaveCount(1)
        ->and($observations[0]->stale)->toBeTrue();
});
