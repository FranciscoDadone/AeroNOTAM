<?php

use App\Models\Airport;
use Illuminate\Support\Facades\Http;

/**
 * The ficha is only as honest as this import. Everything it prints comes off
 * these columns, and the one thing it must never do is turn a field MADHEL
 * left blank into a statement about the aerodrome — so what is pinned here is
 * as much what does *not* get written as what does.
 */
beforeEach(function () {
    // The registry is seeded for every test; the import walks all of it, so
    // each case narrows to the aerodromes it is actually about.
    Airport::query()->whereNotIn('anac_code', ['OSA', 'CIF', 'EZE'])->delete();
});

it('imports the metadata of an aerodrome delegated to the AIP', function () {
    fakeMadhelDetails();

    $this->artisan('notams:import-airport-details --only=OSA')->assertSuccessful();

    $airport = Airport::where('anac_code', 'OSA')->firstOrFail();

    expect($airport->iata_code)->toBe('RSA')
        ->and($airport->fir)->toBe('SAEF')
        ->and($airport->city_reference)->toBe('Santa Rosa')
        ->and((float) $airport->distance_km)->toBe(4.5)
        ->and($airport->direction_reference)->toBe('NNE')
        ->and($airport->elevation_m)->toBe(192)
        ->and($airport->is_aip_delegated)->toBeTrue()
        // Delegated to the AIP, so MADHEL publishes none of this — and null is
        // the only honest way to store "not published".
        ->and($airport->fuel)->toBeNull()
        ->and($airport->telephone)->toBeNull()
        ->and($airport->details_updated_at)->not->toBeNull();
});

it('imports the fuel and telephone of an aerodrome that is not delegated', function () {
    fakeMadhelDetails();

    $this->artisan('notams:import-airport-details --only=CIF')->assertSuccessful();

    $airport = Airport::where('anac_code', 'CIF')->firstOrFail();

    expect($airport->fuel)->toBe('AVGAS 100LL')
        ->and($airport->telephone)->toBe(['(02478) 15-504877'])
        ->and($airport->is_aip_delegated)->toBeFalse();
});

/**
 * 712 aerodromes, one request each. An import that stalled on a single bad
 * response would leave the whole registry a week stale to save one row.
 */
it('imports the rest when one aerodrome cannot be fetched', function () {
    fakeMadhelDetails(['CIF'], ['OSA' => Http::response('down', 503)]);

    $this->artisan('notams:import-airport-details --only=OSA,CIF')->assertSuccessful();

    expect(Airport::where('anac_code', 'CIF')->value('elevation_m'))->toBe(43)
        ->and(Airport::where('anac_code', 'OSA')->value('details_updated_at'))->toBeNull();
});

/**
 * The columns each import owns are disjoint on purpose: this one walks the
 * same table notams:import-madhel filled and notams:import-runways annotated,
 * and an upsert that listed a column it does not own would quietly undo the
 * other command's work.
 */
it('leaves the columns the other imports own alone', function () {
    Airport::where('anac_code', 'OSA')->update([
        'name' => 'SANTA ROSA',
        'magnetic_variation' => -7.5,
        'latitude' => -36.5883333,
    ]);

    fakeMadhelDetails();

    $this->artisan('notams:import-airport-details --only=OSA')->assertSuccessful();

    $airport = Airport::where('anac_code', 'OSA')->firstOrFail();

    expect($airport->name)->toBe('SANTA ROSA')
        ->and($airport->magnetic_variation)->toEqual(-7.5)
        ->and($airport->latitude)->toEqual(-36.5883333);
});

it('says so rather than failing when the registry is empty', function () {
    Airport::query()->delete();
    fakeMadhelDetails();

    $this->artisan('notams:import-airport-details')
        ->expectsOutputToContain('notams:import-madhel')
        ->assertSuccessful();
});
