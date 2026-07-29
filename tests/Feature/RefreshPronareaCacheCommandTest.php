<?php

use App\Services\SmnPronareaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * pronarea:refresh-cache exists to keep the cache warm so a WhatsApp reply
 * never has to be the one that first tries the SMN — and to retry far harder
 * than a live reply could ever afford to, since the SMN's own PRONAREA page
 * warns it 502s under load.
 *
 * services.smn.attempts is pinned to 1 throughout: SmnPronareaService already
 * retries internally (see SmnPronareaServiceTest for that), so leaving it at
 * its default would mean every failure here costs two HTTP calls instead of
 * one, for no assertion this file cares about.
 */
beforeEach(function () {
    Cache::flush();
    config(['services.smn.attempts' => 1]);
});

it('refreshes every fir', function () {
    fakePronarea();

    $this->artisan('pronarea:refresh-cache')->assertSuccessful();

    foreach (array_keys(SmnPronareaService::STATION_IDS) as $fir) {
        $forecast = app(SmnPronareaService::class)->forFir($fir);

        expect($forecast->stale)->toBeFalse();
    }

    Http::assertSentCount(count(SmnPronareaService::STATION_IDS));
});

it('retries a fir that failed before moving on to the next one', function () {
    config(['services.pronarea.refresh_attempts' => 2, 'services.pronarea.refresh_retry_seconds' => 0]);

    // EZE is first in STATION_IDS: fails once, then succeeds on retry. The
    // other four succeed on their first try.
    Http::fake([
        '*observacion=pronarea*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html')),
    ]);

    $this->artisan('pronarea:refresh-cache')->assertSuccessful();

    expect(app(SmnPronareaService::class)->forFir('EZE')->stale)->toBeFalse();

    Http::assertSentCount(6);
});

it('reports a fir that could not be refreshed without failing the whole run', function () {
    config(['services.pronarea.refresh_attempts' => 2, 'services.pronarea.refresh_retry_seconds' => 0]);

    // EZE exhausts both attempts; the other four FIRs still succeed.
    Http::fake([
        '*observacion=pronarea*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html')),
    ]);

    $this->artisan('pronarea:refresh-cache')
        ->expectsOutputToContain('FIR EZE: no se pudo actualizar')
        ->assertSuccessful();

    expect(Cache::get('pronarea:EZE'))->toBeNull()
        ->and(app(SmnPronareaService::class)->forFir('CBA')->stale)->toBeFalse();
});

it('fails only when every fir could not be refreshed', function () {
    config(['services.pronarea.refresh_attempts' => 1, 'services.pronarea.refresh_retry_seconds' => 0]);
    fakePronarea(Http::response(smnFixture('challenge.html'), 403));

    $this->artisan('pronarea:refresh-cache')->assertFailed();
});

/**
 * forFir() serving the last good bulletin instead of throwing is the right
 * answer for a WhatsApp reply — but this command exists specifically to make
 * that fallback unnecessary, so it must not mistake "got an answer" for
 * "the SMN is actually reachable again" and stop retrying early.
 */
it('keeps retrying a fir even when a stale bulletin is available to fall back on', function () {
    config(['services.pronarea.refresh_attempts' => 2, 'services.pronarea.refresh_retry_seconds' => 0]);

    // One combined sequence for both runs — Http::fake() merges stubs and
    // the first match wins, so a fakePronarea() call before the second run
    // would keep answering every request from here, regardless of what a
    // later fake registers (see MetarServiceTest for the same lesson).
    //
    // First run: all five FIRs succeed, warming the cache. Second run (after
    // EZE's fresh entry is forgotten): EZE fails both attempts despite a
    // stale fallback being available; the other four succeed again.
    Http::fake([
        '*observacion=pronarea*' => Http::sequence()
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('pronarea-eze.html')),
    ]);

    $this->artisan('pronarea:refresh-cache')->assertSuccessful();

    // Simulate time passing until the next scheduled run, when every fresh
    // entry from the first one has expired — leaving only the long-lived
    // "last good" copies behind, which is what this test needs EZE to have.
    foreach (array_keys(SmnPronareaService::STATION_IDS) as $fir) {
        Cache::forget("pronarea:{$fir}");
    }

    $this->artisan('pronarea:refresh-cache')
        ->expectsOutputToContain('FIR EZE: no se pudo actualizar')
        ->assertSuccessful();

    Http::assertSentCount(11);
});
