<?php

use App\Models\Runway;
use App\Support\RunwayWind;

/**
 * The arithmetic behind every runway-wind answer. It is four lines of
 * trigonometry, but they are four lines a pilot is going to act on, so the
 * cases that matter are pinned rather than assumed: a sign error in the
 * crosswind would name the wrong side of the runway, and one in the headwind
 * would recommend landing downwind.
 */
function runway(string $designator, int $headingTrue, bool $closed = false): Runway
{
    return new Runway([
        'anac_code' => 'TST',
        'designator' => $designator,
        'heading_true' => $headingTrue,
        'is_closed' => $closed,
        'source' => 'madhel',
    ]);
}

it('reports a wind straight down the runway as all headwind', function () {
    $components = RunwayWind::components([runway('18', 180)], 180, 20);

    expect($components[0]->headwind)->toBe(20)
        ->and($components[0]->crosswind)->toBe(0);
});

it('reports a wind straight up the runway as all tailwind', function () {
    $components = RunwayWind::components([runway('18', 180)], 0, 20);

    expect($components[0]->headwind)->toBe(-20)
        ->and($components[0]->isTailwind())->toBeTrue()
        ->and($components[0]->crosswind)->toBe(0);
});

it('reports a wind square to the runway as all crosswind', function () {
    $components = RunwayWind::components([runway('18', 180)], 270, 20);

    expect($components[0]->headwind)->toBe(0)
        ->and($components[0]->crosswind)->toBe(20);
});

/**
 * Facing 180°, a wind from 270° arrives over the right shoulder and one from
 * 090° over the left. Getting this backwards is the failure that would have a
 * pilot lower the wrong wing.
 */
it('names the side the crosswind comes from', function (int $windDirection, string $side) {
    $components = RunwayWind::components([runway('18', 180)], $windDirection, 15);

    expect($components[0]->side)->toBe($side);
})->with([
    'from the west, facing south' => [270, 'der'],
    'from the east, facing south' => [90, 'izq'],
]);

it('splits a 45-degree wind evenly between the two components', function () {
    $components = RunwayWind::components([runway('18', 180)], 225, 20);

    // 20 · cos 45° = 14.1
    expect($components[0]->headwind)->toBe(14)
        ->and($components[0]->crosswind)->toBe(14);
});

/**
 * The two ends of one runway see the same wind as mirror images: what is a
 * headwind on 05 is a tailwind of the same size on 23, and the crosswind keeps
 * its magnitude while swapping shoulders.
 */
it('mirrors the components between the two ends of a runway', function () {
    $components = RunwayWind::components([runway('05', 50), runway('23', 230)], 100, 18);

    $byDesignator = collect($components)->keyBy('designator');

    expect($byDesignator['05']->headwind)->toBe(-$byDesignator['23']->headwind)
        ->and($byDesignator['05']->crosswind)->toBe($byDesignator['23']->crosswind)
        ->and($byDesignator['05']->side)->not->toBe($byDesignator['23']->side);
});

it('computes the components for the gust as well as the steady wind', function () {
    $components = RunwayWind::components([runway('18', 180)], 270, 15, 30);

    expect($components[0]->crosswind)->toBe(15)
        ->and($components[0]->gustCrosswind)->toBe(30)
        ->and($components[0]->gustHeadwind)->toBe(0);
});

it('leaves the gust components null when the wind is not gusting', function () {
    $components = RunwayWind::components([runway('18', 180)], 180, 15);

    expect($components[0]->gustHeadwind)->toBeNull()
        ->and($components[0]->gustCrosswind)->toBeNull();
});

it('ranks the ends by headwind, most first', function () {
    $components = RunwayWind::components(
        [runway('11', 110), runway('29', 290), runway('35', 350), runway('17', 170)],
        350,
        15,
    );

    expect(array_map(fn ($c) => $c->designator, $components))->toBe(['35', '29', '11', '17']);
});

/**
 * Two ends facing the wind equally are separated by which one takes less of it
 * across.
 */
it('breaks a headwind tie by the smaller crosswind', function () {
    // Wind from 360°: both ends sit 45° off it, so both see the same headwind.
    $components = RunwayWind::components([runway('05', 45), runway('01', 0)], 0, 20);

    expect($components[0]->designator)->toBe('01');
});

it('never favours a closed runway, however well it faces the wind', function () {
    $components = RunwayWind::components(
        [runway('18', 180, closed: true), runway('27', 270)],
        180,
        20,
    );

    // It still ranks first — where it sits relative to the wind is worth
    // seeing — but it is not what gets recommended.
    expect($components[0]->designator)->toBe('18')
        ->and(RunwayWind::favoured($components)->designator)->toBe('27');
});

it('has nothing to favour when every runway is closed', function () {
    $components = RunwayWind::components([runway('18', 180, closed: true)], 180, 20);

    expect(RunwayWind::favoured($components))->toBeNull();
});
