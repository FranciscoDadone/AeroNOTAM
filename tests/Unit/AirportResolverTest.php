<?php

use App\Models\Airport;
use App\Support\AirportResolver;

function resolver(): AirportResolver
{
    return app(AirportResolver::class);
}

it('translates ICAO codes to ANAC indicators', function () {
    expect(resolver()->resolve('SAEZ'))->toBe('EZE')
        ->and(resolver()->resolve('saez'))->toBe('EZE')
        ->and(resolver()->resolve('SABE'))->toBe('AER');
});

it('passes through ANAC codes and unknown input', function () {
    expect(resolver()->resolve('EZE'))->toBe('EZE')
        ->and(resolver()->resolve('  eze '))->toBe('EZE')
        ->and(resolver()->resolve('xxxx'))->toBe('XXXX');
});

it('knows which aerodromes exist', function () {
    expect(resolver()->exists('EZE'))->toBeTrue()
        ->and(resolver()->nameFor('EZE'))->toBe('EZEIZA/MINISTRO PISTARINI')
        ->and(resolver()->exists('ZZZ'))->toBeFalse()
        ->and(resolver()->nameFor('ZZZ'))->toBeNull();
});

it('matches free text to an aerodrome', function (string $message, string $expected) {
    expect(resolver()->matchFromText($message))->toBe($expected);
})->with([
    'bare anac code' => ['eze', 'EZE'],
    'anac code in a sentence' => ['hay notams en EZE?', 'EZE'],
    'icao code' => ['SAEZ', 'EZE'],
    'lowercase icao code' => ['notams saez', 'EZE'],
    'city name' => ['ezeiza', 'EZE'],
    'city name with accent' => ['tucumán', 'TUC'],
    'airport nickname' => ['aeroparque', 'AER'],
    'multi-word name' => ['bariloche', 'BAR'],
]);

it('gives up rather than guessing', function () {
    expect(resolver()->matchFromText('cual es la capital de francia'))->toBeNull()
        ->and(resolver()->matchFromText(''))->toBeNull();
});

/**
 * FIR-wide advisory pseudo-codes are bulletins, not places. Matching
 * "cordoba" against one would hand the user the region-wide advisory
 * instead of Córdoba's airport.
 */
it('never matches a FIR-wide advisory pseudo-code', function () {
    Airport::create([
        'anac_code' => '-CF',
        'name' => 'AVISOS FIR CORDOBA',
        'is_aerodrome' => false,
    ]);

    expect(resolver()->candidatesFromText('cordoba'))->not->toHaveKey('-CF');
});

/**
 * Córdoba has three aerodromes in ANAC's list (Taravella, Gelardi and the
 * military flight school). Returning whichever the database happened to
 * yield first would send a pilot the wrong aerodrome's NOTAMs.
 */
it('reports an ambiguous name as no match rather than a guess', function () {
    $candidates = resolver()->candidatesFromText('cordoba');

    expect(count($candidates))->toBeGreaterThan(1)
        ->and($candidates)->toHaveKey('CBA')
        ->and(resolver()->matchFromText('cordoba'))->toBeNull();
});

it('lets an explicit code win over the ambiguous name', function () {
    expect(resolver()->matchFromText('notams CBA'))->toBe('CBA')
        ->and(resolver()->matchFromText('SACO'))->toBe('CBA');
});

it('returns every aerodrome keyed by ANAC code', function () {
    $known = resolver()->known();

    expect($known)->toHaveKey('EZE', 'EZEIZA/MINISTRO PISTARINI')
        ->and($known)->toHaveCount(Airport::count());
});
