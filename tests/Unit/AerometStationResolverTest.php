<?php

use App\Models\Airport;
use App\Support\AerometStationResolver;

function aerometStations(): AerometStationResolver
{
    return app(AerometStationResolver::class);
}

it('matches a plain station name', function () {
    expect(aerometStations()->codeFromText('aeromet junin'))->toBe('87548');
});

it('is accent and case insensitive', function () {
    expect(aerometStations()->codeFromText('AEROMET NEUQUÉN'))->toBe('87715');
});

it('prefers the primary station over its secondary observatory entry', function () {
    // CORDOBA (87344) and CORDOBA - OBSERVATORIO (87345) would both contain
    // "cordoba" as a substring of their own name, but only the primary one
    // is a bare alias — the observatory needs its qualifier spelled out.
    expect(aerometStations()->codeFromText('aeromet cordoba'))->toBe('87344');
});

it('matches a qualified station by its short alias', function () {
    expect(aerometStations()->codeFromText('aeromet rafaela'))->toBe('87360')
        ->and(aerometStations()->codeFromText('aeromet pilar'))->toBe('87349');
});

it('returns null for a station AEROMET does not cover', function () {
    expect(aerometStations()->codeFromText('aeromet marte'))->toBeNull();
});

it('resolves a code back to its station name', function () {
    expect(aerometStations()->nameFor('87548'))->toBe('JUNIN');
});

it('falls back to the code itself for an unknown one', function () {
    expect(aerometStations()->nameFor('00000'))->toBe('00000');
});

it('matches an aerodrome name regardless of accents', function () {
    // ANAC prints "JUNÍN"; this class's own list has "JUNIN" — the same
    // accent mismatch codeFromText() already normalizes past.
    expect(aerometStations()->codeForName('JUNÍN'))->toBe('87548');
});

/**
 * ANAC names an aerodrome "LOCALIDAD / NOMBRE"; AEROMET names its stations
 * after the locality alone. CORONEL SUÁREZ / LA PISTA has no ICAO code and so
 * will never have a METAR — the station covering its locality is the only wind
 * there is for it.
 */
it('matches an aerodrome by the locality half of its name', function () {
    expect(aerometStations()->codeForName('CORONEL SUÁREZ / LA PISTA'))->toBe('87637')
        ->and(aerometStations()->codeForName('EL PALOMAR / HELIPUERTO'))->toBe('87571');
});

/**
 * The locality half is still compared whole, never as a substring: "SAN CARLOS
 * DE BARILOCHE" contains AEROMET's "SAN CARLOS", which is a station in Mendoza.
 */
it('returns null for an aerodrome name AEROMET does not cover', function () {
    expect(aerometStations()->codeForName('SAN CARLOS DE BARILOCHE / ARELAUQUEN'))->toBeNull()
        ->and(aerometStations()->codeForName('JUNÍN DE LOS ANDES / CASA DE LATA'))->toBeNull();
});

/**
 * CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO names its own club
 * and its own building on both halves — neither is "SANTA ROSA" — so the name
 * match alone misses it the same way it misses SunCityResolver::cityFor().
 * MADHEL's city_reference is the only thing that says where it actually is.
 */
it('falls back to the registry city_reference when neither half of the name matches', function () {
    Airport::where('anac_code', 'ELP')->update(['city_reference' => 'Santa Rosa']);

    expect(aerometStations()->codeForName('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO', 'ELP'))
        ->toBe('87623');
});

it('does not fall back to city_reference without an ANAC code to look it up by', function () {
    Airport::where('anac_code', 'ELP')->update(['city_reference' => 'Santa Rosa']);

    expect(aerometStations()->codeForName('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO'))
        ->toBeNull();
});
