<?php

namespace App\Support;

use App\Models\Runway;

/**
 * The runway ends of an aerodrome, in the order they are published.
 *
 * Thin on purpose — the ordering that matters is by how well each end suits the
 * wind, and that depends on the wind, so it belongs to RunwayWind and not here.
 *
 * A place with no rows is an ordinary outcome, not a failure: the table is
 * built by notams:import-runways, and MADHEL simply has nothing to say about
 * some aerodromes. Callers are expected to tell the user so rather than treat
 * the gap as an error.
 */
class RunwayResolver
{
    /**
     * @return array<int, Runway>
     */
    public function forAnacCode(string $anacCode): array
    {
        return Runway::query()
            ->where('anac_code', strtoupper(trim($anacCode)))
            ->orderBy('designator')
            ->get()
            ->all();
    }

    public function has(string $anacCode): bool
    {
        return Runway::query()->where('anac_code', strtoupper(trim($anacCode)))->exists();
    }
}
