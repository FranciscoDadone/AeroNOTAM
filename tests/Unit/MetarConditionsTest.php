<?php

use App\Support\MetarConditions;

/**
 * The whole value of a METAR watch is in what it does *not* send. A report goes
 * out every hour and almost always differs from the last one, so the cases that
 * assert silence carry as much weight here as the ones that assert an alert.
 */

/**
 * @return array<int, string>
 */
function changesBetween(string $before, string $after): array
{
    return MetarConditions::fromRaw($after)->changesFrom(MetarConditions::fromRaw($before));
}

it('stays quiet when only the temperature and the clock moved', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18010KT 9999 SCT020 23/15 Q1013',
    );

    expect($changes)->toBe([]);
});

it('reports a wind that picked up ten knots', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18018KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toContain('Viento: 18008KT → 18018KT');
});

it('reports a gust appearing even when the steady wind barely moved', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18015KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18017G32KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toContain('Viento');
});

it('ignores a wind that swung round while barely blowing', function () {
    // Forty degrees at three knots is the wind wandering, not changing.
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18003KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 22003KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toBe([]);
});

it('reports the same swing once the wind is strong enough for it to matter', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18014KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 22014KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toContain('Viento');
});

it('measures direction the short way round the compass', function () {
    // 350° to 010° is twenty degrees apart, not three hundred and forty.
    $changes = changesBetween(
        'METAR SAEZ 271200Z 35015KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 01015KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toBe([]);
});

it('reports a wind going variable', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z VRB05KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toContain('Viento');
});

it('reports visibility crossing a band', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 4000 SCT020 22/14 Q1013',
    );

    expect($changes)->toContain('Categoría de vuelo: VFR → IFR.')
        ->and($changes)->toContain('Visibilidad: 10 km o más → 4000 m.');
});

it('ignores visibility drifting inside one band', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 6000 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 7000 SCT020 22/14 Q1013',
    );

    expect($changes)->toBe([]);
});

it('reports a ceiling forming below a scattered layer', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 BKN008 22/14 Q1013',
    );

    expect($changes)->toContain('Categoría de vuelo: VFR → IFR.')
        ->and($changes)->toContain('Techo de nubes: sin techo → 800 ft.');
});

it('does not count scattered or few as a ceiling', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 FEW005 SCT008 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 FEW005 SCT008 22/14 Q1013',
    );

    expect($changes)->toBe([])
        ->and(MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT 9999 FEW005 SCT008 22/14 Q1013')->ceiling)
        ->toBeNull();
});

it('takes the lowest broken or overcast layer as the ceiling', function () {
    $conditions = MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT 9999 BKN014 OVC017 17/14 Q1007');

    expect($conditions->ceiling)->toBe(1400);
});

it('treats an obscured sky as a ceiling', function () {
    $conditions = MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT 0300 FG VV002 14/14 Q1013');

    expect($conditions->ceiling)->toBe(200)
        ->and($conditions->category())->toBe('LIFR');
});

it('reports weather starting', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 -RA SCT020 22/14 Q1013',
    );

    expect($changes)->toContain('Fenómenos: ninguno → -RA.');
});

it('reports weather intensifying', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 -RA SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 +TSRA SCT020 22/14 Q1013',
    );

    expect($changes)->toContain('Fenómenos: -RA → +TSRA.');
});

it('reports weather clearing', function () {
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 BR SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toContain('Fenómenos: BR → ninguno.');
});

it('ignores a one hectopascal drift but reports two', function () {
    expect(changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1012',
    ))->toBe([]);

    expect(changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1011',
    ))->toContain('QNH: 1013 → 1011 hPa.');
});

it('always reports the arrival of a special observation', function () {
    // Same conditions in both, so only the SPECI itself can raise the alert.
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'SPECI SAEZ 271222Z 18008KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toContain('SPECI');
});

it('does not re-announce a special observation that follows another', function () {
    $changes = changesBetween(
        'SPECI SAEZ 271222Z 18008KT 9999 SCT020 22/14 Q1013',
        'SPECI SAEZ 271235Z 18008KT 9999 SCT020 22/14 Q1013',
    );

    expect($changes)->toBe([]);
});

it('reads CAVOK as good visibility and no ceiling', function () {
    $conditions = MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT CAVOK 22/14 Q1013');

    expect($conditions->visibility)->toBe(10000)
        ->and($conditions->ceiling)->toBeNull()
        ->and($conditions->category())->toBe('VFR');

    // And compares equal to the spelled-out equivalent.
    expect(changesBetween(
        'METAR SAEZ 271200Z 18008KT CAVOK 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 22/14 Q1013',
    ))->toBe([]);
});

it('stops reading at the trend group', function () {
    // Everything after BECMG is a forecast; folding it into the observation
    // would compare what the weather will do against what it is doing.
    $conditions = MetarConditions::fromRaw(
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1013 BECMG 4000 BR BKN008'
    );

    expect($conditions->visibility)->toBe(10000)
        ->and($conditions->ceiling)->toBeNull()
        ->and($conditions->phenomena)->toBe([]);
});

it('stops reading at the remarks group', function () {
    $conditions = MetarConditions::fromRaw(
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1013 RMK PP000'
    );

    expect($conditions->qnh)->toBe(1013)
        ->and($conditions->phenomena)->toBe([]);
});

it('does not mistake recent weather for present weather', function () {
    // RERA means rain that has already stopped.
    $conditions = MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT 9999 RERA SCT020 22/14 Q1013');

    expect($conditions->phenomena)->toBe([]);
});

it('keeps the prevailing visibility rather than a directional minimum', function () {
    $conditions = MetarConditions::fromRaw('METAR SAEZ 271300Z 18008KT 6000 3000NE SCT020 22/14 Q1013');

    expect($conditions->visibility)->toBe(6000);
});

it('normalizes wind speed to knots', function () {
    expect(MetarConditions::fromRaw('METAR SAEZ 271300Z 18010MPS 9999 22/14 Q1013')->windSpeed)->toBe(19)
        ->and(MetarConditions::fromRaw('METAR SAEZ 271300Z 18010KT 9999 22/14 Q1013')->windSpeed)->toBe(10);
});

it('bands the flight categories the standard way', function () {
    $category = fn (string $raw) => MetarConditions::fromRaw($raw)->category();

    expect($category('METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14 Q1013'))->toBe('VFR')
        ->and($category('METAR SAEZ 271300Z 18008KT 7000 BKN025 22/14 Q1013'))->toBe('MVFR')
        ->and($category('METAR SAEZ 271300Z 18008KT 3000 BKN008 22/14 Q1013'))->toBe('IFR')
        ->and($category('METAR SAEZ 271300Z 18008KT 0800 FG OVC002 14/14 Q1013'))->toBe('LIFR');
});

it('does not claim good conditions for a report it could not read', function () {
    expect(MetarConditions::fromRaw('METAR SAEZ 271300Z NIL')->category())->toBe('sin datos');
});

it('says nothing about a group that is missing from one of the two reports', function () {
    // A report with no QNH is not a report of a changed QNH.
    $changes = changesBetween(
        'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013',
        'METAR SAEZ 271300Z 18008KT 9999 SCT020 22/14',
    );

    expect($changes)->toBe([]);
});
