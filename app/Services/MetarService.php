<?php

namespace App\Services;

use App\Contracts\MetarSource;
use App\DataObjects\Metar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The single entry point for reading METARs, wrapping the sources with the
 * caching and failover that keep the feature answering.
 *
 * Sources are tried in the order they are registered — the SMN first, since it
 * is the authority for Argentine aerodromes — and the first one to answer wins.
 *
 * The part that matters operationally is the cooldown. The SMN's bot challenge
 * tightens the more it is hit, so a service that retried on every incoming
 * message would hold its own block open indefinitely. After a source fails, it
 * is left alone for a while and the next source answers instead, which both
 * keeps replies flowing and gives the block a chance to expire.
 */
class MetarService
{
    /** @var array<int, MetarSource> */
    protected array $sources;

    protected int $ttl;

    protected int $cooldown;

    /**
     * @param  array<int, MetarSource>  $sources  In order of preference.
     */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
        $this->ttl = (int) config('services.metar.ttl');
        $this->cooldown = (int) config('services.metar.source_cooldown');
    }

    /**
     * The current observation for an ICAO station code.
     *
     * Returns one observation per station: a METAR is superseded the moment the
     * next one is issued, and the sources disagree about how much history to
     * hand back — the SMN returns only the latest, NOAA a couple of hours of
     * them. Passing that difference through would mean the answer depended on
     * which source happened to reply, and would put a stale reading next to the
     * current one with nothing marking which is which.
     *
     * @return array<int, Metar>
     */
    public function getMetars(string $icaoCode): array
    {
        $icaoCode = strtoupper(trim($icaoCode));

        // Plain arrays into the cache, hydrated on the way out — a serialized
        // object would break on the next deploy that changes the class shape.
        $rows = Cache::remember(
            "metar:{$icaoCode}",
            $this->ttl,
            fn () => $this->currentPerStation($this->fetchFromFirstAvailable($icaoCode)),
        );

        return array_map(Metar::fromArray(...), $rows);
    }

    /**
     * Keep only the most recent observation for each station, newest first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function currentPerStation(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => $this->observedTimestamp((string) $b['raw'])
            <=> $this->observedTimestamp((string) $a['raw']));

        $current = [];

        foreach ($rows as $row) {
            // A report we could not read a station out of is malformed; key it
            // by its own text so several of them cannot collapse into one.
            $key = ($row['station'] ?? '') !== '' ? $row['station'] : $row['raw'];

            $current[$key] ??= $row;
        }

        return array_values($current);
    }

    /**
     * When an observation was taken, from the report's own day/time group.
     *
     * A METAR carries the day of the month but not the month itself, so the
     * missing part is filled in as "the most recent past moment that matches".
     * Comparing the raw DDHHMM digits instead would be right all month and then
     * wrong on the 1st, where a report from the 31st sorts above one from the
     * 1st — and picking an eight-hour-old reading as the current weather is
     * exactly the kind of quiet error this whole service exists to avoid.
     */
    protected function observedTimestamp(string $raw): int
    {
        if (preg_match('/\b(\d{2})(\d{2})(\d{2})Z\b/', $raw, $m) !== 1) {
            return 0;
        }

        [, $day, $hour, $minute] = array_map(intval(...), $m);

        $now = Carbon::now('UTC');

        foreach ([0, 1] as $monthsBack) {
            $month = $now->copy()->startOfMonth()->subMonths($monthsBack);

            if ($day > $month->daysInMonth) {
                continue;
            }

            $observed = $month->addDays($day - 1)->setTime($hour, $minute);

            // A small tolerance for clock skew between us and the issuer.
            if ($observed->lessThanOrEqualTo($now->copy()->addHour())) {
                return $observed->getTimestamp();
            }
        }

        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchFromFirstAvailable(string $icaoCode): array
    {
        $failures = [];

        foreach ($this->available() as $source) {
            try {
                $rows = $source->fetch($icaoCode);
            } catch (Throwable $e) {
                report($e);

                $this->startCooldown($source);
                $failures[] = $source->name();

                continue;
            }

            $this->endCooldown($source);

            // Stamp the provenance on every row so the reply can say where the
            // observation came from — the two sources are not quite identical,
            // and a reader deserves to know which one they are looking at.
            return array_map(
                fn (array $row) => $row + ['source' => $source->name()],
                $rows,
            );
        }

        throw new RuntimeException(
            'No se pudo obtener el METAR de ninguna fuente ('.implode(', ', $failures).').'
        );
    }

    /**
     * The sources worth trying right now.
     *
     * If every source is cooling down we try them all anyway: a stale cooldown
     * must never be the reason nobody gets an answer, and the alternative is
     * failing without having asked anyone.
     *
     * @return array<int, MetarSource>
     */
    protected function available(): array
    {
        $ready = array_values(array_filter(
            $this->sources,
            fn (MetarSource $source) => ! $this->isCoolingDown($source),
        ));

        return $ready === [] ? $this->sources : $ready;
    }

    protected function isCoolingDown(MetarSource $source): bool
    {
        return $this->cooldown > 0 && Cache::has($this->cooldownKey($source));
    }

    protected function startCooldown(MetarSource $source): void
    {
        if ($this->cooldown > 0) {
            Cache::put($this->cooldownKey($source), true, $this->cooldown);
        }
    }

    protected function endCooldown(MetarSource $source): void
    {
        Cache::forget($this->cooldownKey($source));
    }

    protected function cooldownKey(MetarSource $source): string
    {
        return "metar:cooldown:{$source->name()}";
    }
}
