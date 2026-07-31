<?php

use App\Support\MadhelDetails;

/**
 * MADHEL publishes two shapes of record and the difference decides what the
 * ficha is allowed to say. `metadata` is filled in for everything; `data` comes
 * back empty for the aerodromes delegated to the AIP — which are the busiest
 * ones, and therefore the ones most often asked about.
 *
 * The parser's whole job is to keep those two apart from a field that is
 * genuinely blank, so that the reply can say "sin dato publicado" without ever
 * saying "no tiene".
 */
it('reads the metadata of an aerodrome delegated to the AIP', function () {
    $details = MadhelDetails::parse(madhelDetailFixture('OSA'));

    expect($details)->toMatchArray([
        'iata_code' => 'RSA',
        'fir' => 'SAEF',
        'city_reference' => 'Santa Rosa',
        'distance_km' => 4.5,
        'direction_reference' => 'NNE',
        'elevation_m' => 192,
        'state' => 'LA PAMPA',
        'traffic' => 'NTL',
        'is_aip_delegated' => true,
    ]);

    // The delegated half: MADHEL has none of it, and null is how that is said.
    expect($details['fuel'])->toBeNull()
        ->and($details['telephone'])->toBeNull()
        ->and($details['service_schedule'])->toBeNull();
});

it('reads the data block of an aerodrome that is not delegated', function () {
    $details = MadhelDetails::parse(madhelDetailFixture('CIF'));

    expect($details['is_aip_delegated'])->toBeFalse()
        ->and($details['fuel'])->toBe('AVGAS 100LL')
        ->and($details['telephone'])->toBe(['(02478) 15-504877'])
        // Published as "", which means the same as not published at all.
        ->and($details['service_schedule'])->toBeNull()
        ->and($details['city_reference'])->toBe('Arrecifes')
        ->and($details['iata_code'])->toBeNull();
});

/**
 * MADHEL is served by a Python application that has, in places, stringified its
 * own null on the way out. Storing that would put the word "None" in front of a
 * pilot as though it were a fuel grade.
 */
it('treats the literal "None" as nothing published', function () {
    $details = MadhelDetails::parse([
        'data' => ['fuel' => 'None', 'telephone' => ['None', ''], 'service_schedule' => 'none'],
        'metadata' => ['traffic' => 'None', 'localization' => ['fir' => 'None']],
    ]);

    expect($details['fuel'])->toBeNull()
        ->and($details['telephone'])->toBeNull()
        ->and($details['service_schedule'])->toBeNull()
        ->and($details['traffic'])->toBeNull()
        ->and($details['fir'])->toBeNull();
});

/**
 * The distance arrives as a string ("6", "4.5"), so it is read as a number
 * rather than trusted to be one.
 */
it('reads the distance whether it is written as a string or a number', function (mixed $published, ?float $expected) {
    $details = MadhelDetails::parse(['metadata' => ['localization' => ['distance_reference' => $published]]]);

    expect($details['distance_km'])->toBe($expected);
})->with([
    'whole number as a string' => ['6', 6.0],
    'decimal as a string' => ['4.5', 4.5],
    'already a number' => [12, 12.0],
    'zero, for an aerodrome that abuts its town' => ['0', 0.0],
    'not published' => [null, null],
    'not a number' => ['Lindando', null],
]);

it('survives a record with neither block', function () {
    $details = MadhelDetails::parse([]);

    expect($details['is_aip_delegated'])->toBeFalse()
        ->and(array_filter($details, fn (mixed $value) => $value !== null && $value !== false))->toBe([]);
});

/**
 * The telephone list comes back null rather than empty, so that downstream
 * never has to tell "published as an empty array" apart from "not published" —
 * MADHEL only ever means the second.
 */
it('reports an empty telephone list as nothing published', function () {
    expect(MadhelDetails::parse(['data' => ['telephone' => []]])['telephone'])->toBeNull()
        ->and(MadhelDetails::parse(['data' => ['telephone' => 'no es una lista']])['telephone'])->toBeNull();
});

it('keeps every number when an aerodrome publishes more than one', function () {
    $details = MadhelDetails::parse(['data' => ['telephone' => [' (011) 4480-0000 ', '(011) 4480-0001']]]);

    expect($details['telephone'])->toBe(['(011) 4480-0000', '(011) 4480-0001']);
});
