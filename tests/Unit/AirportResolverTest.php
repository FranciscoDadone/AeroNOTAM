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
        ->and(resolver()->nameFor('EZE'))->toBe('EZEIZA / MINISTRO PISTARINI')
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
    // A small aerodrome, not an airport: it exists in MADHEL and is now
    // reachable by code even though ANAC only lists it when it has a NOTAM.
    'small aerodrome code' => ['notam ELP', 'ELP'],
]);

/**
 * With the full registry loaded, plenty of ANAC codes are ordinary Spanish
 * words: VER, CON, DAR, SAL. A bare word-boundary match would read "quiero ver
 * los notams de eze" as a request for Ver — so those only count in capitals,
 * the way somebody typing an indicator actually writes it.
 */
it('does not mistake a Spanish word for the aerodrome that shares its code', function () {
    expect(resolver()->matchFromText('quiero ver los notams de eze'))->toBe('EZE')
        ->and(resolver()->matchFromText('dame el metar VER'))->toBe('VER')
        // Lower case still reaches Salta and Bariloche by name.
        ->and(resolver()->matchFromText('notams de salta'))->toBe('SAL')
        ->and(resolver()->matchFromText('clima en bariloche'))->toBe('BAR');
});

/**
 * PCRE without /u works on bytes, and both bytes of "ñ" count as word
 * boundaries — which made "mañana" a mention of ANA, Paraná's aeroclub.
 */
it('does not find codes inside accented words', function () {
    expect(resolver()->matchFromText('como va a estar el tiempo mañana en ezeiza?'))->toBe('EZE');
});

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
 * A city name is almost never unique in the full MADHEL registry: "córdoba"
 * matches seven places, "santa rosa" more than twenty. What separates them is
 * not which row the database yielded first but what kind of aerodrome each one
 * is — public before private, open before closed, towered before untowered.
 */
it('ranks an ambiguous name instead of taking whatever came first', function () {
    $candidates = resolver()->candidatesFromText('cordoba');

    expect(count($candidates))->toBeGreaterThan(1)
        // Taravella, Córdoba's international airport, over the FADEA factory
        // airfield, the military flight school and four private helipads.
        ->and(array_key_first($candidates))->toBe('CBA')
        ->and(resolver()->matchFromText('cordoba'))->toBe('CBA');
});

/**
 * Ranking only breaks ties it can justify. Two aerodromes of the same kind
 * sharing a name stay ambiguous, and the caller is left to ask.
 */
it('still reports a genuine tie as no match', function () {
    foreach (['TWA', 'TWB'] as $code) {
        Airport::create([
            'anac_code' => $code,
            'name' => 'VILLA ZURUMBAMBA',
            'access' => 'publico',
        ]);
    }

    expect(resolver()->candidatesFromText('zurumbamba'))->toHaveCount(2)
        ->and(resolver()->matchFromText('zurumbamba'))->toBeNull();
});

it('lets an explicit code win over the ambiguous name', function () {
    expect(resolver()->matchFromText('notams CBA'))->toBe('CBA')
        ->and(resolver()->matchFromText('SACO'))->toBe('CBA');
});

/**
 * The AI matcher's prompt is built from this list. The full registry is 712
 * rows of mostly private agricultural strips — pasting all of it into every
 * message would cost thousands of tokens to describe places nobody names.
 */
it('offers only the aerodromes someone might name in free text', function () {
    $known = resolver()->known();

    expect($known)->toHaveKey('EZE', 'EZEIZA / MINISTRO PISTARINI')
        ->and($known)->toHaveCount(Airport::query()->publiclyKnown()->count())
        ->and($known)->not->toHaveKey('ACB')   // private agricultural strip
        ->and($known)->not->toHaveKey('---');  // FIR-wide advisory bulletin
});
