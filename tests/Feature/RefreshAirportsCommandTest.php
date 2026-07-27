<?php

use App\Models\Airport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('records which aerodromes currently have active notams', function () {
    fakeAnac();

    $this->artisan('notams:refresh-airports')->assertSuccessful();

    expect(Airport::where('anac_code', 'AER')->value('last_seen_active_at'))->not->toBeNull();
});

it('accrues aerodromes that were not in the seed', function () {
    Airport::where('anac_code', 'AER')->delete();
    fakeAnac();

    $this->artisan('notams:refresh-airports')->assertSuccessful();

    expect(Airport::where('anac_code', 'AER')->exists())->toBeTrue();
});

/**
 * ANAC's selector doesn't publish ICAO codes, so a refresh must never
 * blank out the mappings the seed established.
 */
it('preserves ICAO codes across a refresh', function () {
    fakeAnac();

    $this->artisan('notams:refresh-airports')->assertSuccessful();

    expect(Airport::where('anac_code', 'AER')->value('icao_code'))->toBe('SABE')
        ->and(Airport::where('anac_code', 'EZE')->value('icao_code'))->toBe('SAEZ');
});

it('flags FIR advisory pseudo-codes as non-aerodromes', function () {
    fakeAnac();

    $this->artisan('notams:refresh-airports')->assertSuccessful();

    // "---" (all FIRs) and "-EF" (Ezeiza FIR) appear in the real fixture.
    expect(Airport::where('anac_code', '---')->value('is_aerodrome'))->toBeFalse()
        ->and(Airport::where('anac_code', 'AER')->value('is_aerodrome'))->toBeTrue();
});

it('fails gracefully when ANAC is unreachable', function () {
    Http::fake(['*/notam' => Http::response('down', 503)]);

    $this->artisan('notams:refresh-airports')->assertFailed();

    // The existing registry survives the failed refresh.
    expect(Airport::where('anac_code', 'EZE')->exists())->toBeTrue();
});
