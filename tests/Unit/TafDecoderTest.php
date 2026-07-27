<?php

use App\Services\TafDecoder;

/**
 * Written against real forecasts captured from the SMN and from NOAA's relay of
 * them.
 *
 * The assertions are about numbers and terms a pilot would act on. The one that
 * matters most in a TAF, and has no equivalent in an observation, is which
 * period a condition belongs to: "TEMPO 2808/2812 0500 FGDZ" tied to the wrong
 * window is a worse answer than no answer.
 */
function tafDecoder(): TafDecoder
{
    return app(TafDecoder::class);
}

function forecast(string $raw): string
{
    return implode(' ', tafDecoder()->explain($raw));
}

it('decodes a real ezeiza forecast end to end', function () {
    $lines = tafDecoder()->explain(
        'TAF SAEZ 271700Z 2718/2818 02005KT 9999 BKN020 TX18/2719Z TN12/2810Z '
        .'BECMG 2802/2804 VRB03KT 4000 BR BKN010 TEMPO 2808/2812 14005KT 0500 '
        .'FGDZ BKN005 BECMG 2814/2816 12010KT 7000 BKN025 ='
    );

    expect($lines)->toBe([
        'Pronóstico de aeródromo (TAF).',
        'Estación informante: SAEZ.',
        'Emitido el día 27 a las 17:00 UTC.',
        'Válido desde el día 27 a las 18:00 hasta el día 28 a las 18:00 UTC.',
        'Viento del 020° (NNE) a 5 nudos.',
        'Visibilidad 10 km o más.',
        'Nubes: nubosidad rota (5 a 7 octavos) a 2.000 ft.',
        'Temperatura máxima prevista 18 °C el día 27 a las 19:00 UTC.',
        'Temperatura mínima prevista 12 °C el día 28 a las 10:00 UTC.',
        'Cambio gradual (BECMG) el día 28 entre las 02:00 y las 04:00 UTC:',
        'Viento de dirección variable a 3 nudos.',
        'Visibilidad 4 km.',
        'Fenómenos presentes: neblina.',
        'Nubes: nubosidad rota (5 a 7 octavos) a 1.000 ft.',
        'Fluctuaciones temporarias (TEMPO) el día 28 entre las 08:00 y las 12:00 UTC, en períodos de menos de una hora cada vez:',
        'Viento del 140° (SE) a 5 nudos.',
        'Visibilidad 500 m.',
        'Fenómenos presentes: niebla y llovizna.',
        'Nubes: nubosidad rota (5 a 7 octavos) a 500 ft.',
        'Cambio gradual (BECMG) el día 28 entre las 14:00 y las 16:00 UTC:',
        'Viento del 120° (ESE) a 10 nudos.',
        'Visibilidad 7 km.',
        'Nubes: nubosidad rota (5 a 7 octavos) a 2.500 ft.',
    ]);
});

/*
|--------------------------------------------------------------------------
| Validity and change groups
|--------------------------------------------------------------------------
|
| This is the whole difference between a TAF and a METAR: the text is a
| sequence of periods, and a group means nothing without the one it sits under.
|
*/

it('states the validity period in full', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 02005KT'))
        ->toContain('Válido desde el día 27 a las 18:00 hasta el día 28 a las 18:00 UTC.');
});

/**
 * The day is always named, even when a period does not cross midnight. A TAF is
 * read hours after it was issued, and "entre las 02:00 y las 04:00" on its own
 * invites the reader to assume today.
 */
it('names the day on a period that stays within one', function () {
    expect(forecast('TAF SAEZ 271700Z 2800/2806 BECMG 2802/2804 VRB03KT'))
        ->toContain('Válido el día 28 entre las 00:00 y las 06:00 UTC.')
        ->toContain('Cambio gradual (BECMG) el día 28 entre las 02:00 y las 04:00 UTC:');
});

it('reads FM as a clean break at one moment', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 02005KT FM280930 27010G25KT 9999'))
        ->toContain('Desde el día 28 a las 09:30 UTC:')
        ->toContain('Viento del 270° (O) a 10 nudos, con ráfagas de 25 nudos.');
});

/**
 * TEMPO does not mean the conditions hold for the whole window — it means they
 * may turn up inside it, under an hour at a time. Read the other way, a TEMPO
 * of fog becomes four solid hours of a closed aerodrome that was never
 * forecast.
 */
it('spells out what TEMPO actually means', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 TEMPO 2808/2812 0500 FG'))
        ->toContain('Fluctuaciones temporarias (TEMPO) el día 28 entre las 08:00 y las 12:00 UTC, en períodos de menos de una hora cada vez:');
});

it('carries the probability through to the group it qualifies', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 PROB30 TEMPO 2805/2812 5000 BR'))
        ->toContain('Probabilidad del 30 % de fluctuaciones temporarias (TEMPO) el día 28 entre las 05:00 y las 12:00 UTC');
});

it('handles a probability that stands on its own', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 PROB40 2812/2815 TSRA'))
        ->toContain('Probabilidad del 40 % de las siguientes condiciones el día 28 entre las 12:00 y las 15:00 UTC:')
        ->toContain('tormenta con lluvia');
});

/*
|--------------------------------------------------------------------------
| Groups specific to a forecast
|--------------------------------------------------------------------------
*/

it('decodes the forecast temperature extremes with their hour', function () {
    expect(forecast('TAF SAWH 271700Z 2718/2818 TX02/2719Z TNM03/2811Z'))
        ->toContain('Temperatura máxima prevista 2 °C el día 27 a las 19:00 UTC.')
        // "M" is the encoding for a negative value, not a separate field.
        ->toContain('Temperatura mínima prevista -3 °C el día 28 a las 11:00 UTC.');
});

/**
 * A forecast names the layer the shear sits at, where an observation names the
 * runways it affects — so this is a different group from the METAR's
 * "WS ALL RWY" despite sharing the letters.
 */
it('decodes low-level wind shear with its height', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 WS020/24045KT'))
        ->toContain('Cizalladura del viento a 2.000 ft: del 240° (OSO) a 45 nudos.')
        // The word "viento" is not said twice.
        ->not->toContain('ft: viento');
});

it('flags an amendment', function () {
    expect(forecast('TAF AMD SAEZ 271900Z 2719/2818 18025KT'))
        ->toContain('Pronóstico enmendado (AMD): reemplaza al difundido previamente.')
        ->toContain('Estación informante: SAEZ.');
});

/**
 * A cancelled TAF leaves the aerodrome with no valid forecast at all, which is
 * a different thing from a quiet one and has to read that way.
 */
it('flags a cancellation', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 CNL'))
        ->toContain('Pronóstico cancelado (CNL): el aeródromo queda sin TAF vigente.');
});

/*
|--------------------------------------------------------------------------
| Shared with the observation decoder
|--------------------------------------------------------------------------
|
| Wind, visibility, weather and cloud groups mean the same thing in both codes,
| so they come from AviationCodeDecoder. These check the wiring, not the rules.
|
*/

it('decodes the groups it shares with a metar', function () {
    expect(forecast('TAF SACO 271700Z 2718/2818 VRB03KT CAVOK'))
        ->toContain('Viento de dirección variable a 3 nudos.')
        ->toContain('CAVOK');

    expect(forecast('TAF SAEZ 271700Z 2718/2818 14005KT 0500 +TSRA BKN005CB OVC020'))
        ->toContain('Viento del 140° (SE) a 5 nudos.')
        ->toContain('Visibilidad 500 m.')
        ->toContain('tormenta con lluvia fuerte')
        ->toContain('de tipo cumulonimbus');
});

it('groups consecutive cloud layers into one line', function () {
    $lines = tafDecoder()->explain('TAF SAEZ 271700Z 2718/2818 SCT010 BKN020 OVC080');

    expect(collect($lines)->filter(fn (string $l) => str_starts_with($l, 'Nubes:')))
        ->toHaveCount(1)
        ->and(implode(' ', $lines))
        ->toContain('nubes dispersas (3 a 4 octavos) a 1.000 ft; nubosidad rota (5 a 7 octavos) a 2.000 ft; cielo cubierto (8 octavos) a 8.000 ft');
});

/*
|--------------------------------------------------------------------------
| What it does not understand
|--------------------------------------------------------------------------
*/

/**
 * The same rule as the observation decoder, and for the same reason: a group
 * dropped from a weather report is worse than one left in the original.
 */
it('passes an unrecognised group through instead of guessing', function () {
    expect(forecast('TAF SAEZ 271700Z 2718/2818 02005KT 6109/2'))
        ->toContain('Grupo sin decodificar: 6109/2.')
        // and the groups around it still decode.
        ->toContain('Viento del 020° (NNE) a 5 nudos.');
});

it('returns nothing for an empty report', function () {
    expect(tafDecoder()->explain('   '))->toBe([]);
});

/**
 * NOAA's relay drops the trailing "=" and the SMN's national remarks, so the
 * same forecast has to decode identically with or without them.
 */
it('decodes a relayed forecast the same as a direct one', function () {
    $raw = 'TAF SAEZ 271700Z 2718/2818 02005KT 9999 BKN020';

    expect(tafDecoder()->explain($raw))->toBe(tafDecoder()->explain($raw.' ='));
});
