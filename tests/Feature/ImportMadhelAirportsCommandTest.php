<?php

use App\Models\Airport;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The fixture is a 15-entry slice, far under the production floor.
    config(['services.madhel.minimum_count' => 10]);
});

it('registers aerodromes ANAC only ever lists when they have an active notam', function () {
    Airport::where('anac_code', 'ELP')->delete();
    fakeMadhel();

    $this->artisan('notams:import-madhel')->assertSuccessful();

    $elp = Airport::where('anac_code', 'ELP')->first();

    expect($elp->name)->toBe('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO')
        ->and($elp->icao_code)->toBeNull()
        ->and($elp->access)->toBe('publico');
});

it('is idempotent', function () {
    fakeMadhel();

    $this->artisan('notams:import-madhel')->assertSuccessful();
    $count = Airport::count();

    $this->artisan('notams:import-madhel')->assertSuccessful();

    expect(Airport::count())->toBe($count);
});

/**
 * icao_code is unique, so an OACI code MADHEL now assigns elsewhere has to be
 * released by its previous holder or the whole import dies on a constraint.
 */
it('hands a reassigned OACI code to the aerodrome MADHEL says owns it', function () {
    Airport::where('anac_code', 'GAL')->delete();
    Airport::where('anac_code', 'RGR')->update(['icao_code' => 'SAWG']);

    fakeMadhel();

    $this->artisan('notams:import-madhel')->assertSuccessful();

    expect(Airport::where('anac_code', 'GAL')->value('icao_code'))->toBe('SAWG')
        ->and(Airport::where('anac_code', 'RGR')->value('icao_code'))->toBeNull();
});

it('does not touch last_seen_active_at', function () {
    fakeMadhel();
    Airport::where('anac_code', 'EZE')->update(['last_seen_active_at' => now()->subHour()]);

    $this->artisan('notams:import-madhel')->assertSuccessful();

    expect(Airport::where('anac_code', 'EZE')->value('last_seen_active_at'))->not->toBeNull();
});

it('refuses a truncated response rather than shrinking the registry', function () {
    config(['services.madhel.minimum_count' => 500]);
    fakeMadhel();

    $this->artisan('notams:import-madhel')->assertFailed();

    expect(Airport::where('anac_code', 'EZE')->exists())->toBeTrue();
});

it('fails gracefully when MADHEL is unreachable', function () {
    Http::fake(['*/madhel/api/v2/airports/*' => Http::response('down', 503)]);

    $this->artisan('notams:import-madhel')->assertFailed();

    expect(Airport::where('anac_code', 'EZE')->exists())->toBeTrue();
});

it('writes a seed snapshot that reproduces the registry', function () {
    fakeMadhel();
    $path = tempnam(sys_get_temp_dir(), 'airports').'.php';

    $this->artisan("notams:import-madhel --seed-file --seed-path={$path}")->assertSuccessful();

    $rows = collect(require $path)->keyBy('anac_code');

    expect($rows)->toHaveCount(15)
        ->and($rows['ELP']['name'])->toBe('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO')
        ->and($rows['EZE']['icao_code'])->toBe('SAEZ')
        ->and($rows['ACB']['icao_code'])->toBeNull();

    unlink($path);
});
