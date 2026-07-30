<?php

use App\Services\AerometService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * AEROMET's only source is OGIMET (tests/Fixtures/ogimet/) — a public
 * aggregator of the WMO's Global Telecommunication System, reached with a
 * single request per FIR group (see OgimetAerometSource's own docblock for
 * why the SMN, tried here first for a while, is not a source here any more).
 *
 * AerometService fetches one of AerometStationResolver::FIR_GROUPS at a
 * time — only the group(s) a request's stations actually belong to, cached
 * under "aeromet:{group index}" — rather than one request for all 119
 * stations or one per group regardless of who was asked about. Junín, Mar
 * del Plata and Bariloche — the stations these fixtures name — fall under
 * FIR EZEIZA, group index 0, which is why asking about any of them costs
 * exactly one request.
 */
beforeEach(function () {
    Cache::flush();
});

function aerometService(): AerometService
{
    return app(AerometService::class);
}

it('parses an aeromet observation from real ogimet synop text', function () {
    fakeAeromet();

    $observations = aerometService()->getObservations('87548');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->station)->toBe('JUNIN')
        ->and($observations[0]->observedAt)->toBe('30 - 17:00')
        ->and($observations[0]->raw)->toBe('AAXX 30174 87548 42562 50514 10181 20122 30064 40162 57032 83530 333 56160 83630=')
        ->and($observations[0]->source)->toBe('ogimet');
});

it('keeps stations apart when several are returned', function () {
    fakeAeromet(Http::response(ogimetFixture('ogimet-multi.txt')));

    $observations = aerometService()->getObservations('87548 87765 87938');
    $byStation = collect($observations)->keyBy->station;

    expect($observations)->toHaveCount(3)
        ->and($byStation['JUNIN']->raw)->toStartWith('AAXX 30174 87548')
        ->and($byStation['BARILOCHE']->raw)->toStartWith('AAXX 30174 87765')
        ->and($byStation['USHUAIA']->raw)->toStartWith('AAXX 30174 87938');
});

it('drops a station that reported nothing rather than passing an empty observation on', function () {
    // 87548 (Junín) has a real report; 87022 (Tartagal) is NIL for this
    // slot — asking about both should only ever answer for the one that has
    // something to say.
    fakeAeromet(Http::response(ogimetFixture('ogimet-with-nil.txt')));

    $observations = aerometService()->getObservations('87548 87022');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->station)->toBe('JUNIN');
});

it('returns no observations for a code none of the fir groups name, without asking ogimet at all', function () {
    expect(aerometService()->getObservations('00000'))->toBe([]);

    Http::assertNothingSent();
});

it('does not hit ogimet again for a station in an already-cached group', function () {
    fakeAeromet(Http::response(ogimetFixture('ogimet-multi.txt')));

    // Junín and Bariloche are both FIR EZEIZA.
    aerometService()->getObservations('87548');
    aerometService()->getObservations('87765');

    Http::assertSentCount(1);
});

it('fetches only the groups the requested stations actually belong to', function () {
    fakeAeromet(Http::sequence()
        ->push(ogimetFixture('ogimet-junin.txt'))
        ->push(ogimetFixture('ogimet-junin.txt')));

    // 87548 (Junín) is FIR EZEIZA; 87344 (Córdoba) is FIR CORDOBA — two
    // different groups, so this costs two requests, not one.
    aerometService()->getObservations('87548 87344');

    Http::assertSentCount(2);
});

it('only loses the stations of the one group that could not be reached', function () {
    Http::fake([
        '*ogimet.com*' => Http::sequence()
            ->push(ogimetFixture('ogimet-junin.txt')) // FIR EZEIZA (87548)
            ->push('', 503), // FIR CORDOBA (87344)
    ]);

    $observations = aerometService()->getObservations('87548 87344');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->station)->toBe('JUNIN');
});

it('fails when ogimet cannot be reached', function () {
    fakeAeromet(Http::response('', 503));

    aerometService()->getObservations('87548');
})->throws(RuntimeException::class);

/*
|--------------------------------------------------------------------------
| Stale fallback
|--------------------------------------------------------------------------
|
| AEROMET has no second source — SYNOP surface observations are not relayed
| over OPMET the way aerodrome reports are — so a group that cannot reach
| OGIMET at all is ridden out by serving each station's last observation
| fetched successfully instead of failing outright, same fix as
| SmnPronareaService.
|
| A group that DOES reach OGIMET falls back the same way, station by
| station, when it simply leaves one of its own stations out (NIL for that
| hour) — confirmed live, and not distinguishable from a station genuinely
| having nothing published, from the response alone.
|
*/

it('serves the last good observation, marked stale, when ogimet cannot be reached at all', function () {
    // A sequence rather than two fakeAeromet() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one.
    Http::fake([
        '*ogimet.com*' => Http::sequence()
            ->push(ogimetFixture('ogimet-junin.txt'))
            ->push('', 503),
    ]);

    aerometService()->getObservations('87548');

    Cache::forget('aeromet:0');

    $observations = aerometService()->getObservations('87548');

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->raw)->toBe('AAXX 30174 87548 42562 50514 10181 20122 30064 40162 57032 83530 333 56160 83630=')
        ->and($observations[0]->stale)->toBeTrue();
});

it('serves a station own last good reading when it drops out of an otherwise successful group response', function () {
    Http::fake([
        '*ogimet.com*' => Http::sequence()
            ->push(ogimetFixture('ogimet-multi.txt'))
            // Second round: Junín is missing, only Bariloche answers — same
            // as a live round dropping a station's report can look.
            ->push(ogimetFixture('ogimet-bariloche-only.txt')),
    ]);

    aerometService()->getObservations('87548 87765');

    Cache::forget('aeromet:0');

    $observations = aerometService()->getObservations('87548 87765');
    $byStation = collect($observations)->keyBy->station;

    expect($observations)->toHaveCount(2)
        ->and($byStation['JUNIN']->stale)->toBeTrue()
        ->and($byStation['BARILOCHE']->stale)->toBeFalse();
});

it('still fails when ogimet cannot be reached and nothing was ever fetched', function () {
    fakeAeromet(Http::response('', 503));

    aerometService()->getObservations('87548');
})->throws(RuntimeException::class);

it('warms the cache for one group without asking about any one station', function () {
    Http::fake([
        '*ogimet.com*' => Http::sequence()
            ->push(ogimetFixture('ogimet-junin.txt'))
            ->push('', 503),
    ]);

    aerometService()->refreshGroup(0);

    Http::assertSentCount(1);

    // OGIMET is unreachable now, but refreshGroup() already populated
    // Junín's last-good entry — this still answers, marked stale.
    Cache::forget('aeromet:0');
    $observations = aerometService()->getObservations('87548');

    Http::assertSentCount(2);
    expect($observations)->toHaveCount(1)
        ->and($observations[0]->stale)->toBeTrue();
});
