<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns the metar for an aerodrome', function () {
    fakeSmn();

    $this->getJson('/api/metar?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'EZE')
        ->assertJsonPath('estacion', 'SAEZ')
        ->assertJsonPath('cantidad', 1)
        ->assertJsonPath('metars.0.metar', 'METAR SAEZ 271400Z 03009KT 9999 BKN008 OVC100 15/14 Q1009 NOSIG =');
});

it('accepts an ICAO code just like the notam endpoint', function () {
    fakeSmn();

    $this->getJson('/api/metar?aerodromo=SAEZ')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'EZE');
});

it('includes the spanish explanation alongside the raw report', function () {
    fakeSmn();

    $response = $this->getJson('/api/metar?aerodromo=EZE')->assertOk();

    expect($response->json('metars.0.explicacion'))
        ->toContain('Viento del 030° (NNE) a 9 nudos.')
        ->toContain('Presión QNH 1009 hPa.');
});

it('skips the explanation with decode=false', function () {
    fakeSmn();

    $response = $this->getJson('/api/metar?aerodromo=EZE&decode=false')->assertOk();

    expect($response->json('metars.0.explicacion'))->toBe([])
        // The raw report is still fully present.
        ->and($response->json('metars.0.metar'))->toContain('METAR SAEZ');
});

it('requires an aerodrome', function () {
    fakeSmn();

    $this->getJson('/api/metar')
        ->assertStatus(422)
        ->assertJsonValidationErrors('aerodromo');
});

it('404s for an unknown aerodrome', function () {
    fakeSmn();

    $this->getJson('/api/metar?aerodromo=ZZZ')
        ->assertNotFound()
        ->assertJsonPath('aerodromo', 'ZZZ');
});

/**
 * Plenty of ANAC aerodromes have no ICAO code, and the SMN indexes
 * observations by that code alone. That is a permanent property of the
 * aerodrome rather than a transient outage, so it must not look like one:
 * a 502 would invite a client to retry forever.
 */
it('404s for an aerodrome with no ICAO code', function () {
    fakeSmn();

    $this->getJson('/api/metar?aerodromo=AGR')
        ->assertNotFound()
        ->assertJsonPath('aerodromo', 'AGR');

    Http::assertNothingSent();
});

it('502s when the SMN is unreachable', function () {
    fakeSmn(Http::response('down', 503));

    $this->getJson('/api/metar?aerodromo=EZE')->assertStatus(502);
});

it('reports no observations rather than failing when the SMN has none', function () {
    fakeSmn(Http::response(smnFixture('metar-empty.html')));

    $this->getJson('/api/metar?aerodromo=EZE')
        ->assertOk()
        ->assertJsonPath('cantidad', 0);
});

it('behaves identically on the versioned and unversioned paths', function () {
    fakeSmn();

    $versioned = $this->getJson('/api/v1/metar?aerodromo=EZE')->assertOk();
    $legacy = $this->getJson('/api/metar?aerodromo=EZE')->assertOk();

    expect($legacy->json())->toBe($versioned->json());
});
