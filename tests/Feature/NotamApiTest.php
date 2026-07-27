<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    withoutAi();
});

it('returns the notams for an aerodrome', function () {
    fakeAnac();

    $this->getJson('/api/notams?aerodromo=AER')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'AER')
        ->assertJsonPath('cantidad', 3)
        ->assertJsonPath('notams.0.number', 'A2187/2026');
});

it('accepts an ICAO code', function () {
    fakeAnac();

    $this->getJson('/api/notams?aerodromo=SAEZ')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'EZE');
});

it('requires an aerodrome', function () {
    fakeAnac();

    $this->getJson('/api/notams')
        ->assertStatus(422)
        ->assertJsonValidationErrors('aerodromo');
});

it('404s for an unknown aerodrome', function () {
    fakeAnac();

    $this->getJson('/api/notams?aerodromo=ZZZ')
        ->assertNotFound()
        ->assertJsonPath('aerodromo', 'ZZZ');
});

/**
 * The whole point of splitting decoding out of scraping: with no AI
 * available the endpoint must still serve the NOTAMs. It used to 502.
 */
it('still serves notams when the AI is unavailable', function () {
    fakeAnac();

    $response = $this->getJson('/api/notams?aerodromo=AER')->assertOk();

    expect($response->json('notams.0.decoded_by'))->toBe('dictionary')
        ->and($response->json('notams.0.decoded_es'))->toContain('Pista 13/31 cerrada');
});

it('skips enrichment entirely with decode=false', function () {
    fakeAnac();

    $response = $this->getJson('/api/notams?aerodromo=AER&decode=false')->assertOk();

    expect($response->json('notams.0.decoded_es'))->toBeNull()
        ->and($response->json('notams.0.decoded_by'))->toBeNull()
        // The raw payload is still fully present.
        ->and($response->json('notams.0.text_en'))->toBe('RWY 13/31 CLSD WIP MAINT');
});

it('502s when ANAC itself is unreachable', function () {
    fakeAnac(Http::response('down', 503));

    $this->getJson('/api/notams?aerodromo=AER')->assertStatus(502);
});

it('lists the aerodromes with active notams', function () {
    fakeAnac();

    $this->getJson('/api/notams/aerodromos')
        ->assertOk()
        ->assertJsonPath('aerodromos.0.codigo', 'AER')
        ->assertJsonPath('aerodromos.0.nombre', 'AEROPARQUE J. NEWBERY');
});

it('behaves identically on the versioned and unversioned paths', function () {
    fakeAnac();

    $versioned = $this->getJson('/api/v1/notams?aerodromo=AER')->assertOk();
    $legacy = $this->getJson('/api/notams?aerodromo=AER')->assertOk();

    expect($legacy->json())->toBe($versioned->json());

    $this->getJson('/api/v1/notams/aerodromos')->assertOk();
});

it('rate limits the notam endpoints', function () {
    fakeAnac();

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/notams?aerodromo=AER')->assertOk();
    }

    $this->getJson('/api/notams?aerodromo=AER')->assertStatus(429);
});
