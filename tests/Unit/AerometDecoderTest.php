<?php

use App\Services\AerometDecoder;

/**
 * Every case here is a real observation captured from the SMN's AEROMET
 * screen (station JUNIN, MAR DEL PLATA, NEUQUEN), confirmed group by group
 * against the SMN's own "Decodificado" breakdown of the same SYNOP report —
 * see AerometDecoder's docblock. The station name is stripped before it
 * reaches the decoder, same as AerometEnricher does.
 */
function aerometDecoder(): AerometDecoder
{
    return app(AerometDecoder::class);
}

it('decodes a real junin observation end to end', function () {
    expect(aerometDecoder()->explain('090/06KT 12KM 4Ci19800FT 16/07 Q1018.4'))->toBe([
        'Viento del 090° a 6 nudos.',
        'Visibilidad 12 km.',
        'Nubes: 4/8 Cirrus a 19.800 ft.',
        'Temperatura 16 °C, punto de rocío 7 °C.',
        'Presión QNH 1018.4 hPa.',
    ]);
});

it('decodes two cloud layers', function () {
    $lines = aerometDecoder()->explain('200/06KT 10KM 6Sc2500FT 3Ac9900FT 11/07 Q1019.7');

    expect($lines)->toContain('Nubes: 6/8 Stratocumulus a 2.500 ft.')
        ->and($lines)->toContain('Nubes: 3/8 Altocumulus a 9.900 ft.');
});

/**
 * Weather phenomena ("FBL RA CONS") are deliberately left undecoded here —
 * the SMN's own gloss for them is folded in by AerometEnricher instead, from
 * the div.tip SmnAerometSource captures separately.
 */
it('leaves weather-phenomenon tokens out rather than guessing at them', function () {
    $lines = aerometDecoder()->explain('110/04KT 10KM FBL RA CONS 3St3000FT 5Sc4900FT 05/03 Q1017.5');

    expect($lines)->toBe([
        'Viento del 110° a 4 nudos.',
        'Visibilidad 10 km.',
        'Nubes: 3/8 Stratus a 3.000 ft.',
        'Nubes: 5/8 Stratocumulus a 4.900 ft.',
        'Temperatura 5 °C, punto de rocío 3 °C.',
        'Presión QNH 1017.5 hPa.',
    ]);
});

it('reports calm wind rather than a zero bearing', function () {
    expect(aerometDecoder()->explain('000/00KT 10KM 16/07 Q1018.4'))
        ->toContain('Viento: calma.');
});

it('reads a negative temperature the same way METAR does', function () {
    expect(aerometDecoder()->explain('M03/M07'))
        ->toBe(['Temperatura -3 °C, punto de rocío -7 °C.']);
});
