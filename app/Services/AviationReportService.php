<?php

namespace App\Services;

use App\Contracts\AviationReportSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The reading half of every aerodrome weather feature, wrapping the sources
 * with the caching and failover that keep them answering.
 *
 * Sources are tried in the order they are registered — the SMN first, since it
 * is the authority for Argentine aerodromes — and the first one to answer wins.
 *
 * The part that matters operationally is the cooldown. The SMN's bot challenge
 * tightens the more it is hit, so a service that retried on every incoming
 * message would hold its own block open indefinitely. After a source fails, it
 * is left alone for a while and the next source answers instead, which both
 * keeps replies flowing and gives the block a chance to expire.
 *
 * METAR and TAF share that cooldown deliberately (see cooldownKey()), and share
 * everything else here because the two differ only in what they publish: a TAF
 * is fetched, cached, collapsed to the current one and failed over exactly like
 * an observation.
 */
abstract class AviationReportService
{
    /** @var array<int, AviationReportSource> */
    protected array $sources;

    protected int $ttl;

    protected int $cooldown;

    /**
     * @param  array<int, AviationReportSource>  $sources  In order of preference.
     */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
        $this->ttl = (int) config("services.{$this->kind()}.ttl");
        $this->cooldown = (int) config('services.weather.source_cooldown');
    }

    /**
     * What this service reads: 'metar' or 'taf'. Namespaces the cache and picks
     * up the matching TTL, which differ because the reports do — observations
     * are issued hourly, forecasts every six.
     */
    abstract protected function kind(): string;

    /**
     * @param  array<int, string>  $failures  Names of the sources that failed.
     */
    abstract protected function unreachable(array $failures): RuntimeException;

    /**
     * The current report for an ICAO station code, as plain rows.
     *
     * Returns one per station: a report is superseded the moment the next one
     * is issued, and the sources disagree about how much history to hand back —
     * the SMN returns only the latest, NOAA a few hours of them. Passing that
     * difference through would mean the answer depended on which source
     * happened to reply, and would put a superseded report next to the current
     * one with nothing marking which is which.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function currentRows(string $icaoCode): array
    {
        $icaoCode = strtoupper(trim($icaoCode));

        // Plain arrays into the cache, hydrated on the way out — a serialized
        // object would break on the next deploy that changes the class shape.
        return Cache::remember(
            "{$this->kind()}:{$icaoCode}",
            $this->ttl,
            fn () => $this->currentPerStation($this->fetchFromFirstAvailable($icaoCode)),
        );
    }

    /**
     * Keep only the most recent report for each station, newest first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function currentPerStation(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => $this->issuedTimestamp((string) $b['raw'])
            <=> $this->issuedTimestamp((string) $a['raw']));

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
     * When a report was issued, from its own day/time group.
     *
     * Both codes carry the day of the month but not the month itself, so the
     * missing part is filled in as "the most recent past moment that matches".
     * Comparing the raw DDHHMM digits instead would be right all month and then
     * wrong on the 1st, where a report from the 31st sorts above one from the
     * 1st — and picking an eight-hour-old reading as the current weather is
     * exactly the kind of quiet error this whole service exists to avoid.
     */
    protected function issuedTimestamp(string $raw): int
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

            $issued = $month->addDays($day - 1)->setTime($hour, $minute);

            // A small tolerance for clock skew between us and the issuer.
            if ($issued->lessThanOrEqualTo($now->copy()->addHour())) {
                return $issued->getTimestamp();
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
            // report came from — the two sources are not quite identical, and a
            // reader deserves to know which one they are looking at.
            return array_map(
                fn (array $row) => $row + ['source' => $source->name()],
                $rows,
            );
        }

        throw $this->unreachable($failures);
    }

    /**
     * The sources worth trying right now.
     *
     * If every source is cooling down we try them all anyway: a stale cooldown
     * must never be the reason nobody gets an answer, and the alternative is
     * failing without having asked anyone.
     *
     * @return array<int, AviationReportSource>
     */
    protected function available(): array
    {
        $ready = array_values(array_filter(
            $this->sources,
            fn (AviationReportSource $source) => ! $this->isCoolingDown($source),
        ));

        return $ready === [] ? $this->sources : $ready;
    }

    protected function isCoolingDown(AviationReportSource $source): bool
    {
        return $this->cooldown > 0 && Cache::has($this->cooldownKey($source));
    }

    protected function startCooldown(AviationReportSource $source): void
    {
        if ($this->cooldown > 0) {
            Cache::put($this->cooldownKey($source), true, $this->cooldown);
        }
    }

    protected function endCooldown(AviationReportSource $source): void
    {
        Cache::forget($this->cooldownKey($source));
    }

    /**
     * Keyed by publisher and not by kind of report, so a rest earned on the
     * METAR endpoint is also honoured on the TAF one.
     *
     * That is the whole point of backing off: the SMN's challenge is aimed at
     * us, not at one of its pages, so asking it for a forecast seconds after it
     * refused us an observation would keep the block alive exactly as retrying
     * would.
     */
    protected function cooldownKey(AviationReportSource $source): string
    {
        return "weather:cooldown:{$source->name()}";
    }
}
