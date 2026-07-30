<?php

use App\Models\AerometStationObservation;
use App\Services\SmnAerometSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * aeromet:refresh-cache exists to keep the cache warm so a live WhatsApp
 * reply is rarely the one paying a group's up-to-two-minute cost (see
 * SmnAerometSource), and to retry each of SmnAerometSource::FIR_GROUPS
 * independently, far harder than a live reply could ever afford to — same
 * shape as RefreshPronareaCache retrying each FIR independently, and for the
 * same reason: confirmed live, a group routinely 522s on an early attempt and
 * comes through on a later one, and one group answering must never be read as
 * the whole job being done.
 *
 * services.smn.attempts is pinned to 1 throughout: SmnReportSource::get()
 * already retries the isolated Cloudflare challenge on its own, and leaving
 * it at its default would mean every failure here costs two HTTP calls
 * instead of one, for no assertion this file cares about.
 */
beforeEach(function () {
    Cache::flush();
    config(['services.smn.attempts' => 1]);
});

it('refreshes every group', function () {
    fakeAeromet();

    $this->artisan('aeromet:refresh-cache')->assertSuccessful();

    Http::assertSentCount(count(SmnAerometSource::FIR_GROUPS));
});

it('retries a group that failed before moving on to the next one', function () {
    config(['services.aeromet.refresh_attempts' => 2, 'services.aeromet.refresh_retry_seconds' => 0]);

    // Group 0 (FIR EZEIZA) fails once, then succeeds on retry. The other
    // four groups succeed on their first try.
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html')),
    ]);

    $this->artisan('aeromet:refresh-cache')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 1/2...')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 1/2 fallido')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 2/2...')
        ->expectsOutputToContain(sprintf('AEROMET: %d de %d grupos actualizados.', count(SmnAerometSource::FIR_GROUPS), count(SmnAerometSource::FIR_GROUPS)))
        ->assertSuccessful();

    Http::assertSentCount(6);
});

it('reports a group that could not be refreshed without failing the whole run', function () {
    config(['services.aeromet.refresh_attempts' => 2, 'services.aeromet.refresh_retry_seconds' => 0]);

    // Group 0 exhausts both attempts; the other four groups still succeed.
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html'))
            ->push(smnFixture('aeromet-multi.html')),
    ]);

    $this->artisan('aeromet:refresh-cache')
        ->expectsOutputToContain('AEROMET: grupo 1/5: no se pudo actualizar')
        ->expectsOutputToContain('AEROMET: 4 de 5 grupos actualizados.')
        ->assertSuccessful();

    expect(Cache::get('aeromet:0'))->toBeNull()
        // Group 1 succeeded — whatever it warmed made it into the
        // last-good store during this run.
        ->and(AerometStationObservation::where('wmo_code', '87548')->exists())->toBeTrue();
});

it('fails only when every group could not be refreshed', function () {
    config(['services.aeromet.refresh_attempts' => 1, 'services.aeromet.refresh_retry_seconds' => 0]);
    fakeAeromet(Http::response(smnFixture('challenge.html'), 403));

    $this->artisan('aeromet:refresh-cache')->assertFailed();
});
