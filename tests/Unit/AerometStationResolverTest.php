<?php

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

it('returns null for an aerodrome name AEROMET does not cover', function () {
    expect(aerometStations()->codeForName('EL PALOMAR / HELIPUERTO'))->toBeNull();
});
