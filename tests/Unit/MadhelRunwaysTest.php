<?php

use App\Support\MadhelRunways;

/**
 * MADHEL's rwy list is prose. Every line that begins with a designator pair is
 * a runway and every other line is a note about one, and the shapes below are
 * the ones the live registry actually contains — the separators disagree, the
 * dash before the dimensions is sometimes there, and one aerodrome writes its
 * thousands with a dot.
 */
it('reads the dimensions and the surface off a runway line', function (string $line, array $expected) {
    $ends = MadhelRunways::parse([$line]);

    expect($ends)->toHaveCount(2)
        ->and($ends[0])->toMatchArray($expected)
        // Both ends of a strip carry its dimensions: the table is one row per
        // end, and 05 is as long as 23 is.
        ->and($ends[1])->toMatchArray(array_diff_key($expected, ['designator' => null]));
})->with([
    'plain' => [
        '05/23 1871x30 M - ASPH - PCN 28/F/A/X/T.',
        ['designator' => '05', 'length_m' => 1871, 'width_m' => 30, 'surface' => 'asfalto'],
    ],
    'unpaved' => [
        '09/27 1500x30 M - Tierra.',
        ['designator' => '09', 'length_m' => 1500, 'width_m' => 30, 'surface' => 'tierra'],
    ],
    'dash before the dimensions' => [
        '16/34 - 822x26 M - Tierra.',
        ['designator' => '16', 'length_m' => 822, 'width_m' => 26, 'surface' => 'tierra'],
    ],
    'en dash as the separator' => [
        '16/34 2100x23 M - ASPH – AUW 13t/1 16t/2.',
        ['designator' => '16', 'length_m' => 2100, 'width_m' => 23, 'surface' => 'asfalto'],
    ],
    'thousands written with a dot' => [
        '11/29 1.591x30 M - ASPH - PCN 37t/1 48t/4.',
        ['designator' => '11', 'length_m' => 1591, 'width_m' => 30, 'surface' => 'asfalto'],
    ],
    'concrete' => [
        '01 R/19 L 1377x23 M - CONC - AUW 13t/1 20t/2.',
        ['designator' => '01R', 'length_m' => 1377, 'width_m' => 23, 'surface' => 'hormigón'],
    ],
]);

/**
 * Mariano Moreno is the one entry in the whole registry that writes its
 * parallel runways with a space before the side. Without it the aerodrome has
 * no runways at all.
 */
it('still parses parallel runways written with a space before the side', function () {
    $ends = MadhelRunways::parse(['01 R/19 L 1377x23 M - CONC - AUW 13t/1 20t/2.']);

    expect(array_column($ends, 'designator'))->toBe(['01R', '19L']);
});

/**
 * The bearing strength that follows the surface is not a surface. Reading past
 * the first word would print "ASPH - PCN 28/F/A/X/T" where the ficha means to
 * say "asfalto".
 */
it('stops at the surface and does not swallow the bearing strength', function () {
    $ends = MadhelRunways::parse(['05/23 1871x30 M - ASPH - PCN 28/F/A/X/T.']);

    expect($ends[0]['surface'])->toBe('asfalto');
});

/**
 * A vocabulary nobody anticipated is still information. Dropping the word
 * because it is not on the list would lose the only thing the entry said about
 * what the pilot is landing on.
 */
it('passes an unfamiliar surface through as published', function () {
    $ends = MadhelRunways::parse(['05/23 700x15 M - Conchilla.']);

    expect($ends[0]['surface'])->toBe('conchilla');
});

it('reads lighting only where the line mentions it', function () {
    expect(MadhelRunways::parse(['05/23 700x15 M - Tierra - Balizada.'])[0]['is_lighted'])->toBeTrue()
        ->and(MadhelRunways::parse(['05/23 700x15 M - ASPH – ILE.'])[0]['is_lighted'])->toBeTrue()
        // Null and not false: most entries say nothing about lighting, and
        // "no balizada" is a claim about a night landing that silence does not
        // support.
        ->and(MadhelRunways::parse(['05/23 700x15 M - Tierra.'])[0]['is_lighted'])->toBeNull();
});

/**
 * A line that stops at the designator is still a runway. Requiring the
 * dimensions would lose the aerodromes whose entry never had them.
 */
it('keeps a runway whose line carries no dimensions', function () {
    $ends = MadhelRunways::parse(['02/20']);

    expect(array_column($ends, 'designator'))->toBe(['02', '20'])
        ->and($ends[0]['length_m'])->toBeNull()
        ->and($ends[0]['surface'])->toBeNull();
});

/**
 * The rest of the list talks *about* runways — a strip width, a threshold
 * coordinate, a slope — and each would invent a runway that does not exist.
 */
it('still ignores the notes MADHEL keeps in the same list', function (string $line) {
    expect(MadhelRunways::parse([$line]))->toBe([]);
})->with([
    ['Franja de RWY 11/29 1.711x140 M.'],
    ['Extremo RWY 34 343252,36S 0590451,26W - ELEV 23 M AMSL (75 FT).'],
    ['Dispone de una SWY a RWY 02 de 100x30 M.'],
    ['RESA 90x 56 M.'],
    ['16 disponible 500 M por desplazamiento THR.'],
]);

it('still marks a closed runway rather than dropping it', function () {
    $ends = MadhelRunways::parse(['09/27 600x30 M - Tierra (CLSD).']);

    expect($ends)->toHaveCount(2)
        ->and($ends[0]['is_closed'])->toBeTrue()
        ->and($ends[0]['surface'])->toBe('tierra');
});
