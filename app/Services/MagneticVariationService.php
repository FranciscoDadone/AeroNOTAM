<?php

namespace App\Services;

use App\Models\Airport;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How far magnetic north is from true north at an aerodrome, in degrees, east
 * positive — so that true = magnetic + variation, with no case analysis.
 *
 * This exists because a runway designator is magnetic and the wind in a METAR
 * is true. In most of the world one could almost get away with ignoring the
 * difference; in Argentina one cannot. The variation runs from about −10° in
 * Buenos Aires to +11.7° in Ushuaia, crossing zero somewhere over Patagonia,
 * so the correction does not even have a consistent sign across the country.
 * Skipping it would put up to 20° of error into the angle the whole crosswind
 * calculation turns on.
 *
 * The value is cached on the airport row rather than fetched per query, because
 * it drifts by roughly a tenth of a degree a year: a year-old number is still
 * far more accurate than the ten-degree granularity of the designator it
 * corrects. That cache is also what keeps the weekly import from calling NOAA
 * at all once it has run through the registry once.
 */
class MagneticVariationService
{
    /**
     * How long a stored value stays good. Generous on purpose — see above.
     */
    protected const TTL_DAYS = 365;

    /** @var array<int, array{latitude: float, longitude: float, variation: float}>|null */
    protected ?array $known = null;

    public function __construct(
        protected string $url,
        protected string $key,
    ) {}

    /**
     * Resolve and persist the variation for one aerodrome.
     *
     * Never throws and never returns null. A geomagnetic model being briefly
     * unreachable is not a reason to abandon a runway import: the fallbacks
     * walk down from "what we already had" to "what the nearest aerodrome we do
     * know reports", and only reach zero when the registry has nothing at all
     * to interpolate from — which is a first run with no network, and is
     * flagged by the caller rather than hidden.
     */
    public function for(Airport $airport): float
    {
        if ($this->isFresh($airport)) {
            return (float) $airport->magnetic_variation;
        }

        if ($airport->latitude === null || $airport->longitude === null) {
            return $airport->magnetic_variation ?? $this->nearest($airport) ?? 0.0;
        }

        $declination = $this->fetch($airport->latitude, $airport->longitude);

        if ($declination === null) {
            return $airport->magnetic_variation ?? $this->nearest($airport) ?? 0.0;
        }

        $airport->forceFill([
            'magnetic_variation' => round($declination, 2),
            'magnetic_variation_at' => now(),
        ])->save();

        $this->known = null;

        return round($declination, 2);
    }

    protected function isFresh(Airport $airport): bool
    {
        return $airport->magnetic_variation !== null
            && $airport->magnetic_variation_at !== null
            && $airport->magnetic_variation_at->diffInDays(now()) < self::TTL_DAYS;
    }

    protected function fetch(float $latitude, float $longitude): ?float
    {
        try {
            $response = Http::timeout(20)->get($this->url, [
                'lat1' => $latitude,
                'lon1' => $longitude,
                'key' => $this->key,
                'resultFormat' => 'json',
                'model' => 'WMM',
                'startYear' => now()->year,
                'startMonth' => now()->month,
                'startDay' => now()->day,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $declination = $response->successful() ? $response->json('result.0.declination') : null;

        return is_numeric($declination) ? (float) $declination : null;
    }

    /**
     * The variation of the closest aerodrome that already has one.
     *
     * Crude — a plain squared distance in degrees, no great-circle — but it is
     * only ever a stand-in for a failed lookup, and the field it is sampling
     * varies smoothly enough over a few hundred kilometres that the nearest
     * neighbour is worth far more than a zero.
     */
    protected function nearest(Airport $airport): ?float
    {
        if ($airport->latitude === null || $airport->longitude === null) {
            return null;
        }

        $this->known ??= Airport::query()
            ->whereNotNull('magnetic_variation')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude', 'magnetic_variation'])
            ->map(fn (Airport $known) => [
                'latitude' => (float) $known->latitude,
                'longitude' => (float) $known->longitude,
                'variation' => (float) $known->magnetic_variation,
            ])
            ->all();

        $best = null;
        $bestDistance = INF;

        foreach ($this->known as $known) {
            $distance = ($known['latitude'] - $airport->latitude) ** 2
                + ($known['longitude'] - $airport->longitude) ** 2;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $known['variation'];
            }
        }

        return $best;
    }
}
