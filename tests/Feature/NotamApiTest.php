<?php

use App\Models\Airport;
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
 * ANAC's PIB answers a quiet aerodrome and an invented code identically — a
 * 500 either way — so a small aerodrome like ELP used to be reported as
 * unrecognised. The MADHEL registry is what tells the two apart.
 */
it('serves a small aerodrome that has no active notams', function () {
    fakeAnac(Http::response('error', 500));

    $this->getJson('/api/notams?aerodromo=ELP')
        ->assertOk()
        ->assertJsonPath('aerodromo', 'ELP')
        ->assertJsonPath('nombre', 'CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO')
        ->assertJsonPath('cantidad', 0);
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

/**
 * The listing is the local registry, not what ANAC happens to be showing:
 * an aerodrome with nothing active today is still an aerodrome you can ask
 * about, and the endpoint no longer goes down when ANAC does.
 */
it('lists every aerodrome, whether or not it has active notams', function () {
    Http::preventStrayRequests();

    $response = $this->getJson('/api/notams/aerodromos')->assertOk();

    expect($response->json('cantidad'))->toBeGreaterThan(700);

    $codes = collect($response->json('aerodromos'))->pluck('nombre', 'codigo');

    expect($codes)->toHaveKey('ELP')
        ->and($codes['EZE'])->toBe('EZEIZA / MINISTRO PISTARINI')
        // FIR-wide advisory pseudo-codes are bulletins, not places.
        ->and($codes)->not->toHaveKey('---');
});

it('reports the OACI code and whether a notam is active', function () {
    Airport::where('anac_code', 'EZE')->update(['last_seen_active_at' => now()]);

    $aerodromos = collect($this->getJson('/api/notams/aerodromos')->json('aerodromos'))->keyBy('codigo');

    expect($aerodromos['EZE'])->toMatchArray([
        'oaci' => 'SAEZ',
        'controlado' => true,
        'notam_activo' => true,
    ])->and($aerodromos['ELP'])->toMatchArray([
        'oaci' => null,
        'notam_activo' => false,
    ]);
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
