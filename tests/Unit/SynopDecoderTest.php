<?php

use App\Services\SynopDecoder;

/**
 * The real-message cases here were captured verbatim from OGIMET's getsynop
 * (stations JUNIN, BARILOCHE, USHUAIA). The WMO code tables this class
 * relies on (0500, 1677, 4377) were confirmed against pymetdecoder
 * (antarctica/pymetdecoder), a tested, actively maintained SYNOP decoder —
 * see SynopDecoder's own docblock for why that mattered: a first attempt at
 * the pressure group, worked out by hand, produced a physically implausible
 * reading.
 */
function synopDecoder(): SynopDecoder
{
    return app(SynopDecoder::class);
}

it('decodes a real junin observation end to end', function () {
    $raw = 'AAXX 30174 87548 42562 50514 10181 20122 30064 40162 57032 83530 333 56160 83630=';

    expect(synopDecoder()->explain($raw))->toBe([
        'Viento del 050° a 14 nudos.',
        'Visibilidad 12 km.',
        'Temperatura 18,1 °C, punto de rocío 12,2 °C.',
        'Presión QNH 1.016,2 hPa.',
        'Nubes: 3/8 Stratocumulus a 3.000 ft.',
    ]);
});

it('decodes two cloud layers from section 3', function () {
    $raw = 'AAXX 30174 87765 41458 81906 10022 20007 39070 48354 57025 76076 887// 333 56599 86714 88820=';

    $lines = synopDecoder()->explain($raw);

    expect($lines)->toContain('Nubes: 6/8 Stratus a 1.400 ft.')
        ->and($lines)->toContain('Nubes: 8/8 Cumulus a 2.000 ft.');
});

it('reads a negative dew point the same way METAR/TAF do', function () {
    $raw = 'AAXX 30174 87938 42680 12510 10024 21042 30037 40108 57001 81500 333 56000 81644 95000=';

    expect(synopDecoder()->explain($raw))->toContain(
        'Temperatura 2,4 °C, punto de rocío -4,2 °C.',
    );
});

it('reports calm wind rather than a zero bearing', function () {
    $raw = 'AAXX 30174 87548 41458 00000 10181 20122 40162=';

    expect(synopDecoder()->explain($raw))->toContain('Viento: calma.');
});

it('reports variable wind direction rather than a literal 990°', function () {
    // Nddff "89903": N=8, dd=99 (variable), ff=03 — iw=4 in "30174" means
    // already in knots, no m/s conversion needed.
    $raw = 'AAXX 30174 87548 41458 89903 10181 20122 40162=';

    expect(synopDecoder()->explain($raw))->toContain('Viento variable a 3 nudos.');
});

it('converts wind speed from m/s to knots when iw says the group is in m/s', function () {
    // iw=1 (the last digit of "30171") means Nddff's ff is metres per
    // second: 10 m/s -> round(10 * 1.94384) = 19 knots. Nddff "00510": N=0,
    // dd=05 (050°), ff=10.
    $raw = 'AAXX 30171 87548 41458 00510 10181 20122 40162=';

    expect(synopDecoder()->explain($raw))->toContain('Viento del 050° a 19 nudos.');
});

it('omits pressure rather than showing an out-of-range reading', function () {
    // 4PPPP "42000" decodes to 200.0 hPa taken at face value — outside the
    // 700-1200 hPa bound, so left out rather than shown wrong.
    $raw = 'AAXX 30174 87548 41458 05010 10181 20122 42000=';

    $pressureLines = array_filter(
        synopDecoder()->explain($raw),
        fn (string $line) => str_starts_with($line, 'Presión'),
    );

    expect($pressureLines)->toBe([]);
});

it('omits a cloud layer height that only names a range rather than picking one end of it', function () {
    // Section 3 "83091": amount 3, genus 0 (Ci), height code 91 — one of the
    // WMO 1677 codes that names an estimated range (used when the height is
    // estimated rather than measured) instead of a point value.
    $raw = 'AAXX 30174 87548 41458 05010 10181 20122 40162 333 83091=';

    expect(synopDecoder()->explain($raw))->toContain('Nubes: 3/8 Cirrus.');
});

it('returns nothing for text that is not a synop report', function () {
    expect(synopDecoder()->explain('not a synop report'))->toBe([]);
});
