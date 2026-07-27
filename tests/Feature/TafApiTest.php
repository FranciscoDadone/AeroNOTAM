<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns the taf for an aerodrome', function () {
    fakeTaf();

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'EZE')
        ->assertJsonPath('estacion', 'SAEZ')
        ->assertJsonPath('cantidad', 1)
        ->assertJsonPath('tafs.0.emitido', '27 - 17:00');

    expect($this->getJson('/api/taf?aerodromo=EZE')->json('tafs.0.taf'))
        ->toStartWith('TAF SAEZ 271700Z 2718/2818');
});

it('accepts an ICAO code just like the other endpoints', function () {
    fakeTaf();

    $this->getJson('/api/taf?aerodromo=SAEZ')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'EZE');
});

it('includes the spanish explanation alongside the raw forecast', function () {
    fakeTaf();

    $response = $this->getJson('/api/taf?aerodromo=EZE')->assertOk();

    expect($response->json('tafs.0.explicacion'))
        ->toContain('Válido desde el día 27 a las 18:00 hasta el día 28 a las 18:00 UTC.')
        ->toContain('Cambio gradual (BECMG) el día 28 entre las 02:00 y las 04:00 UTC:');
});

it('skips the explanation with decode=false', function () {
    fakeTaf();

    $response = $this->getJson('/api/taf?aerodromo=EZE&decode=false')->assertOk();

    expect($response->json('tafs.0.explicacion'))->toBe([])
        // The raw forecast is still fully present.
        ->and($response->json('tafs.0.taf'))->toContain('TAF SAEZ');
});

/**
 * Whether the forecast still stands is the first thing a reader needs, so it is
 * a field rather than something to be inferred from the raw text.
 */
it('flags an amendment', function () {
    fakeTaf(Http::response(smnTafWith('TAF AMD SAEZ 271900Z 2719/2818 18025KT =')));

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('tafs.0.enmendado', true)
        ->assertJsonPath('tafs.0.cancelado', false);
});

it('flags a cancellation', function () {
    fakeTaf(Http::response(smnTafWith('TAF SAEZ 271700Z 2718/2818 CNL =')));

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('tafs.0.cancelado', true)
        ->assertJsonPath('tafs.0.enmendado', false);
});

it('requires an aerodrome', function () {
    fakeTaf();

    $this->getJson('/api/taf')
        ->assertStatus(422)
        ->assertJsonValidationErrors('aerodromo');
});

it('404s for an unknown aerodrome', function () {
    fakeTaf();

    $this->getJson('/api/taf?aerodromo=ZZZ')
        ->assertNotFound()
        ->assertJsonPath('aerodromo', 'ZZZ');
});

/**
 * A permanent property of the aerodrome rather than a transient outage, so it
 * must not look like one: a 502 would invite a client to retry forever.
 */
it('404s for an aerodrome with no ICAO code', function () {
    fakeTaf();

    $this->getJson('/api/taf?aerodromo=AGR')
        ->assertNotFound()
        ->assertJsonPath('aerodromo', 'AGR');

    Http::assertNothingSent();
});

it('serves the relayed forecast when the SMN is blocking', function () {
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('tafs.0.fuente', 'noaa');
});

it('reports the source that answered', function () {
    fakeTaf();

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('tafs.0.fuente', 'smn');
});

it('502s only when every source is unreachable', function () {
    fakeTaf(Http::response('down', 503), Http::response('down', 503));

    $this->getJson('/api/taf?aerodromo=EZE')->assertStatus(502);
});

it('reports no forecast rather than failing when the SMN has none', function () {
    fakeTaf(Http::response(smnFixture('taf-empty.html')));

    $this->getJson('/api/taf?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('cantidad', 0);
});

it('behaves identically on the versioned and unversioned paths', function () {
    fakeTaf();

    $versioned = $this->getJson('/api/v1/taf?aerodromo=EZE')->assertOk();
    $legacy = $this->getJson('/api/taf?aerodromo=EZE')->assertOk();

    expect($legacy->json())->toBe($versioned->json());
});

/**
 * The two weather endpoints must not be able to answer each other's question. A
 * forecast served as an observation would read as current conditions that were
 * never observed.
 */
it('does not confuse the forecast with the observation', function () {
    fakeMetar();
    fakeTaf();

    $metar = $this->getJson('/api/metar?aerodromo=EZE')->assertOk();
    $taf = $this->getJson('/api/taf?aerodromo=EZE')->assertOk();

    expect($metar->json('metars.0.metar'))->toStartWith('METAR SAEZ')
        ->and($taf->json('tafs.0.taf'))->toStartWith('TAF SAEZ');
});
