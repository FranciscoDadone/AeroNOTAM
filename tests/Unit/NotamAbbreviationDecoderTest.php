<?php

use App\Services\NotamAbbreviationDecoder;

function decoder(): NotamAbbreviationDecoder
{
    return app(NotamAbbreviationDecoder::class);
}

it('decodes notam text into spanish', function (string $input, string $expected) {
    expect(decoder()->decodeEs($input))->toBe($expected);
})->with([
    'contractions' => [
        'RWY 11/29 CLSD DUE WIP',
        'Pista 11/29 cerrada debido a trabajos en progreso',
    ],
    'english prose mixed in' => [
        'BIRD ACTIVITY IN THE VICINITY OF THE AD',
        'Actividad de aves en las proximidades del aeródromo',
    ],
    'idiomatic phrase' => [
        'RWY 11 LGT OUT OF SERVICE UNTIL FURTHER NOTICE',
        'Pista 11 luz / iluminación fuera de servicio hasta nuevo aviso',
    ],
    'displaced threshold' => [
        'DTHR 02 100M RWY AVBL 963M',
        'Umbral desplazado 02 100M pista disponible 963M',
    ],
]);

/**
 * The adjective must agree with the subject it modifies — the nearest one
 * to its left — not with whatever noun appears elsewhere in the sentence.
 * "AD CLSD ... ACFT" is about an aeródromo and must read "cerrado".
 */
it('agrees gendered adjectives with the subject they modify', function () {
    expect(decoder()->decodeEs('AD CLSD EXC FOR ACFT WITH PPR'))->toContain('Aeródromo cerrado')
        ->and(decoder()->decodeEs('RWY 05/23 CLSD'))->toContain('Pista 05/23 cerrada');
});

/**
 * Runway and taxiway designators are exactly what a pilot needs to read
 * back unambiguously, so they must keep their form.
 */
it('preserves designators and contact details', function () {
    expect(decoder()->decodeEs('TWY A NOT AVBL'))->toContain('Calle de rodaje A')
        ->and(decoder()->decodeEs('WORK IN PROGRESS ON TWY B AND C'))->toContain('B y C')
        ->and(decoder()->decodeEs('CTC TEL 011-4480-1234'))->toContain('011-4480-1234');
});

it('normalises time ranges and marks them UTC', function () {
    expect(decoder()->decodeEs('TWY A NOT AVBL BTN 1200-1800'))->toContain('12:00-18:00 UTC');
});

/**
 * A lone 4-digit number is as likely to be a year or a NOTAM series number
 * as a clock time, so it must be left alone.
 */
it('leaves standalone four-digit numbers alone', function () {
    expect(decoder()->decodeEs('AIP AMDT 2026'))->not->toContain(':');
});

it('only reports abbreviations present in the text', function () {
    $glossary = decoder()->glossaryFor('RWY CLSD WIP');

    expect($glossary)->toHaveKey('RWY')
        ->and($glossary)->toHaveKey('CLSD')
        ->and($glossary)->not->toHaveKey('OBST');
});

it('returns null for empty input', function () {
    expect(decoder()->decodeEs(null))->toBeNull()
        ->and(decoder()->decodeEs('   '))->toBeNull();
});

it('still decodes to english', function () {
    expect(decoder()->decode('RWY 11/29 CLSD DUE WIP'))
        ->toBe('Runway 11/29 closed due to work in progress');
});
