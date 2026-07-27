<?php

use App\Services\MetarDecoder;

/**
 * The decoder is what turns a positional code into something a person can
 * read, so these tests are written against real reports captured from the SMN
 * (and, for the US-format groups, the worked example in the NWS decode key the
 * abbreviation tables come from).
 *
 * Every assertion here is about a number or a term a pilot would act on. A
 * mistranslated bearing or a dropped cloud layer is the failure mode that
 * matters — not prose style.
 */
function metarDecoder(): MetarDecoder
{
    return app(MetarDecoder::class);
}

function explained(string $raw): string
{
    return implode(' ', metarDecoder()->explain($raw));
}

it('decodes a real ezeiza observation end to end', function () {
    $lines = metarDecoder()->explain('METAR SAEZ 271400Z 03009KT 9999 BKN008 OVC100 15/14 Q1009 NOSIG =');

    expect($lines)->toBe([
        'Informe meteorológico rutinario de aeródromo (METAR).',
        'Estación informante: SAEZ.',
        'Observación del día 27 a las 14:00 UTC.',
        'Viento del 030° (NNE) a 9 nudos.',
        'Visibilidad 10 km o más.',
        'Nubes: nubosidad rota (5 a 7 octavos) a 800 ft; cielo cubierto (8 octavos) a 10.000 ft.',
        'Temperatura 15 °C, punto de rocío 14 °C (humedad relativa 94 %).',
        'Presión QNH 1009 hPa.',
        'Sin cambios significativos previstos en las próximas 2 horas.',
    ]);
});

it('names the wind bearing in spanish and reports gusts', function () {
    expect(explained('METAR SAAR 271530Z 18015G28KT'))
        ->toContain('Viento del 180° (S) a 15 nudos, con ráfagas de 28 nudos.');

    // Spanish compass points use O for oeste, never W.
    expect(explained('METAR SAAR 271530Z 27010KT'))->toContain('(O)');
    expect(explained('METAR SAAR 271530Z 22010KT'))->toContain('(SO)');
});

/**
 * 00000KT is the ICAO encoding for calm. Decoded literally it reads as "from
 * 000 degrees at 0 knots", which is nonsense rather than merely clumsy.
 */
it('reports calm wind rather than a zero bearing', function () {
    expect(explained('METAR SAEZ 271400Z 00000KT'))
        ->toContain('Viento: calma.')
        ->not->toContain('000°');
});

it('reports a variable wind without inventing a direction', function () {
    expect(explained('METAR SACO 271400Z VRB09KT'))
        ->toContain('Viento de dirección variable a 9 nudos.');
});

it('decodes the directional spread reported alongside the wind', function () {
    expect(explained('METAR SABE 271200Z 02007KT 340V050'))
        ->toContain('Dirección del viento variando entre 340° y 050°.');
});

/**
 * 9999 means "10 km or more", not a literal 9,999 metres.
 */
it('decodes visibility including the 9999 sentinel', function (string $group, string $expected) {
    expect(explained("METAR SAEZ 271400Z 03009KT {$group}"))->toContain($expected);
})->with([
    'ten km or more' => ['9999', 'Visibilidad 10 km o más.'],
    'whole kilometres' => ['7000', 'Visibilidad 7 km.'],
    'sub-kilometre' => ['0400', 'Visibilidad 400 m.'],
    'fractional km' => ['1500', 'Visibilidad 1,5 km.'],
    'sector minimum' => ['2000NE', 'Visibilidad 2 km hacia el noreste.'],
]);

it('expands CAVOK rather than leaving the acronym bare', function () {
    expect(explained('METAR SAME 271400Z 16011KT CAVOK 15/05 Q1005 ='))
        ->toContain('visibilidad de 10 km o más')
        ->toContain('sin fenómenos meteorológicos significativos');
});

it('decodes negative temperatures encoded with M', function () {
    expect(explained('METAR SAWH 271200Z 32005KT 9999 M03/M07 Q1010'))
        ->toContain('Temperatura -3 °C, punto de rocío -7 °C');
});

it('does not thousands-separate the QNH', function () {
    // A QNH is written 1009, never 1.009 — the separator reads as a decimal
    // point to half the world.
    expect(explained('METAR SAEZ 271400Z Q1009'))
        ->toContain('Presión QNH 1009 hPa.')
        ->not->toContain('1.009');
});

it('decodes US inches of mercury as well as hPa', function () {
    expect(explained('METAR KABC 121755Z A2990'))
        ->toContain('Presión QNH 29,90 pulgadas de mercurio.');
});

it('builds weather groups from intensity, descriptor and phenomena', function (string $group, string $expected) {
    expect(explained("METAR SAEZ 271400Z 03009KT 3000 {$group} 15/14"))->toContain($expected);
})->with([
    'light rain' => ['-RA', 'lluvia ligera'],
    'heavy thunderstorm with rain' => ['+TSRA', 'tormenta con lluvia fuerte'],
    'showers in the vicinity' => ['VCSH', 'chaparrones en las proximidades'],
    'mist' => ['BR', 'neblina'],
    'shallow fog' => ['MIFG', 'niebla baja'],
    'freezing drizzle' => ['FZDZ', 'llovizna engelante'],
    'rain and snow showers' => ['SHRASN', 'chaparrones de lluvia y nieve'],
]);

it('groups several cloud layers into one line in report order', function () {
    expect(explained('METAR SAAR 271530Z 3000 BKN012CB OVC030 19/18'))
        ->toContain('Nubes: nubosidad rota (5 a 7 octavos) a 1.200 ft de tipo cumulonimbus; cielo cubierto (8 octavos) a 3.000 ft.');
});

/**
 * Cloud height is reported in hundreds of feet — "OVC100" is 10,000 ft, not
 * 100 ft. Getting this wrong understates a ceiling by two orders of magnitude.
 */
it('reads cloud height as hundreds of feet', function () {
    expect(explained('METAR SAEZ 271400Z OVC100'))->toContain('a 10.000 ft');
    expect(explained('METAR SAEZ 271400Z OVC003'))->toContain('a 300 ft');
});

it('decodes vertical visibility as an obscured sky', function () {
    expect(explained('METAR SAWH 271200Z 0400 FG VV003 M03/M07'))
        ->toContain('cielo oculto, visibilidad vertical a 300 ft');
});

it('decodes runway visual range with its qualifiers and tendency', function () {
    expect(explained('METAR KABC 121755Z 1SM R11/P6000FT'))
        ->toContain('Alcance visual en pista: pista 11, más de 6.000 ft.');

    expect(explained('METAR SAEZ 271400Z R25L/M0050V0200U'))
        ->toContain('pista 25L, menos de 50 m, variando hasta 200 m y en aumento');
});

it('flags recent weather as already finished', function () {
    expect(explained('METAR SAAR 271530Z RESHRA'))
        ->toContain('Fenómeno reciente, ya finalizado: chaparrones de lluvia.');
});

it('decodes wind shear over its multi-word target', function () {
    expect(explained('METAR SAAR 271530Z WS ALL RWY'))
        ->toContain('Cizalladura del viento en todas las pistas.');
});

it('decodes the SMN precipitation remark', function () {
    expect(explained('METAR SABE 271200Z RMK PP000'))
        ->toContain('sin precipitación registrada desde la observación anterior');

    expect(explained('METAR SABE 271200Z RMK PP012'))
        ->toContain('1,2 mm de precipitación desde la observación anterior');
});

it('translates remark abbreviations from the NWS key', function () {
    expect(explained('METAR KABC 121755Z RMK AO2 PRESFR SLPNO'))
        ->toContain('estación automática con discriminador de precipitación')
        ->toContain('presión descendiendo rápidamente')
        ->toContain('presión a nivel del mar no disponible');
});

it('distinguishes a SPECI from a routine report', function () {
    expect(explained('SPECI SAAR 271530Z 18015G28KT'))
        ->toContain('Informe meteorológico especial (SPECI)');
});

/**
 * An unrecognised group must stay visible. Dropping it silently would leave a
 * reader believing the Spanish is the whole report, which is the one failure
 * this decoder must not have.
 */
it('surfaces an unknown group instead of dropping it', function () {
    expect(explained('METAR SAEZ 271400Z 03009KT XYZ123'))
        ->toContain('Grupo sin decodificar: XYZ123.');
});

it('ignores the terminating equals sign', function () {
    expect(explained('METAR SAEZ 271400Z Q1009 ='))
        ->not->toContain('=');
});

it('returns nothing for an empty report', function () {
    expect(metarDecoder()->explain('   '))->toBe([]);
});
