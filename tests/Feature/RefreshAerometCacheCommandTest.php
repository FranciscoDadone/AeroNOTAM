<?php

use App\Models\AerometStationObservation;
use App\Support\AerometStationResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * aeromet:refresh-cache exists to keep the cache warm so a live WhatsApp
 * reply is rarely the one paying OGIMET's cost, and to retry each of
 * AerometStationResolver::FIR_GROUPS independently, far harder than a live
 * reply could ever afford to — same shape as RefreshPronareaCache retrying
 * each FIR independently, and for the same reason: one group being
 * unreachable must not stop the others from refreshing, and one group
 * answering must not be read as the whole job being done.
 */
beforeEach(function () {
    Cache::flush();
});

it('refreshes every group', function () {
    fakeAeromet();

    $this->artisan('aeromet:refresh-cache')->assertSuccessful();

    Http::assertSentCount(count(AerometStationResolver::FIR_GROUPS));
});

it('retries a group that failed before moving on to the next one', function () {
    config(['services.aeromet.refresh_attempts' => 2, 'services.aeromet.refresh_retry_seconds' => 0]);

    // Group 0 (FIR EZEIZA) fails once, then succeeds on retry. The other
    // four groups succeed on their first try.
    fakeAeromet(Http::sequence()
        ->push('', 503)
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt')));

    $this->artisan('aeromet:refresh-cache')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 1/2...')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 1/2 fallido')
        ->expectsOutputToContain('AEROMET: grupo 1/5, intento 2/2...')
        ->expectsOutputToContain(sprintf('AEROMET: %d de %d grupos actualizados.', count(AerometStationResolver::FIR_GROUPS), count(AerometStationResolver::FIR_GROUPS)))
        ->assertSuccessful();

    Http::assertSentCount(6);
});

it('reports a group that could not be refreshed without failing the whole run', function () {
    config(['services.aeromet.refresh_attempts' => 2, 'services.aeromet.refresh_retry_seconds' => 0]);

    // Group 0 exhausts both attempts; the other four groups still succeed.
    fakeAeromet(Http::sequence()
        ->push('', 503)
        ->push('', 503)
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt'))
        ->push(ogimetFixture('ogimet-multi.txt')));

    $this->artisan('aeromet:refresh-cache')
        ->expectsOutputToContain('AEROMET: grupo 1/5: no se pudo actualizar')
        ->expectsOutputToContain('AEROMET: 4 de 5 grupos actualizados.')
        ->assertSuccessful();

    expect(Cache::get('aeromet:0'))->toBeNull()
        // Group 4 (FIR C. RIVADAVIA) succeeded — Ushuaia, the one station in
        // this fixture that group actually publishes, made it into the
        // last-good store during this run.
        ->and(AerometStationObservation::where('wmo_code', '87938')->exists())->toBeTrue();
});

it('fails only when every group could not be refreshed', function () {
    config(['services.aeromet.refresh_attempts' => 1, 'services.aeromet.refresh_retry_seconds' => 0]);
    fakeAeromet(Http::response('', 503));

    $this->artisan('aeromet:refresh-cache')->assertFailed();
});
