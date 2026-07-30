<?php

namespace App\Support;

use App\DataObjects\RunwayWindComponent;
use App\Models\Runway;

/**
 * Ranks an aerodrome's runway ends against the wind in a METAR.
 *
 * The arithmetic is one right triangle per end:
 *
 *     θ         = wind bearing − runway heading
 *     headwind  = V · cos θ     negative means it is behind you
 *     crosswind = V · sin θ     positive means it comes from your right
 *
 * Both are computed against heading_true, and against the *true* bearing the
 * METAR reports. The magnetic-to-true correction was already applied when the
 * runway was imported, so nothing here has to know two norths exist.
 */
final class RunwayWind
{
    /**
     * Every end, best first.
     *
     * "Best" is most headwind, and between two ends that face the wind equally,
     * least crosswind. Closed ends are ranked with the rest rather than pushed
     * to the bottom — where they sit relative to the wind is still worth
     * seeing — but favoured() never returns one.
     *
     * @param  array<int, Runway>  $runways
     * @return array<int, RunwayWindComponent>
     */
    public static function components(array $runways, int $windDirection, int $windSpeed, ?int $windGust = null): array
    {
        $components = array_map(
            fn (Runway $runway) => self::componentFor($runway, $windDirection, $windSpeed, $windGust),
            array_values($runways),
        );

        usort(
            $components,
            fn (RunwayWindComponent $a, RunwayWindComponent $b) => [$b->headwind, $a->crosswind] <=> [$a->headwind, $b->crosswind],
        );

        return $components;
    }

    /**
     * The end to recommend, or null when every one of them is closed.
     *
     * A closed runway is never the answer to "which one do I use", however
     * well it happens to face the wind.
     *
     * @param  array<int, RunwayWindComponent>  $components
     */
    public static function favoured(array $components): ?RunwayWindComponent
    {
        foreach ($components as $component) {
            if (! $component->isClosed) {
                return $component;
            }
        }

        return null;
    }

    protected static function componentFor(Runway $runway, int $windDirection, int $windSpeed, ?int $windGust): RunwayWindComponent
    {
        $angle = deg2rad($windDirection - $runway->heading_true);

        $crosswind = $windSpeed * sin($angle);

        return new RunwayWindComponent(
            designator: $runway->designator,
            isClosed: $runway->is_closed,
            headwind: (int) round($windSpeed * cos($angle)),
            crosswind: (int) round(abs($crosswind)),
            // sin θ is positive when the wind sits clockwise of the runway
            // heading, which is the pilot's right-hand side. Exactly 0 is the
            // wind straight down the runway either way, and calling that side
            // anything would be inventing a distinction.
            side: $crosswind >= 0 ? 'der' : 'izq',
            gustHeadwind: $windGust === null ? null : (int) round($windGust * cos($angle)),
            gustCrosswind: $windGust === null ? null : (int) round(abs($windGust * sin($angle))),
        );
    }
}
