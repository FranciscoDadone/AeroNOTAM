<?php

namespace App\Services;

use App\Contracts\AviationReportSource;
use App\DataObjects\AerometObservation;
use App\Models\AerometStationObservation;
use App\Support\AerometStationResolver;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The single entry point for reading AEROMET observations.
 *
 * The cache and per-source cooldown come from AviationReportService, same as
 * METAR/TAF — there is only one source registered (OgimetAerometSource; see
 * its own docblock for why the SMN, tried here first for a while, is not one
 * of them any more).
 *
 * That source fetches one of AerometStationResolver::FIR_GROUPS at a time
 * rather than one station at a time, so currentRows() is called once per FIR
 * group a request actually touches — the requested WMO code is only ever
 * used to work out which group(s) that is, via groupIndexFor(). Two stations
 * in the same group share one fetch and one TTL; two in different groups
 * cost one fetch each, not five, since a live reply has no reason to warm
 * groups nobody asked about.
 *
 * Caching per group rather than as one combined fetch is also what lets
 * RefreshAerometCache retry a single failed group without its retry being
 * wasted the moment any other group answers — this mattered even more when
 * the SMN was still a source here: a first attempt that got Córdoba and
 * Resistencia back was otherwise indistinguishable from "done" if success
 * were judged on "got anything at all", leaving Ezeiza — the group with by
 * far the most commonly asked-about stations — unwarmed for a full TTL.
 *
 * There being only one source is why this also keeps its own last-good
 * store, one row per station in AerometStationObservation — a database table
 * rather than the generic cache, since it is meant to survive a
 * Cache::flush() aimed at everything else the application caches, and to
 * always hold exactly the latest reading rather than expire on a TTL. It
 * gets used more often than a plain "unreachable" fallback: a group fetch
 * that answers just fine can still leave one of its own stations out of it —
 * a station with nothing published for that round, or NIL for that hour on
 * OGIMET's own side — and there is no way to tell that apart from a real gap
 * in coverage from the response alone, so a station missing from the current
 * round always falls back to its own last reading — marked stale — rather
 * than the absence being read as "nothing published right now".
 */
class AerometService extends AviationReportService
{
    protected int $staleTtl;

    /**
     * @param  array<int, AviationReportSource>  $sources
     */
    public function __construct(array $sources)
    {
        parent::__construct($sources);

        $this->staleTtl = (int) config('services.aeromet.stale_ttl');
    }

    protected function kind(): string
    {
        return 'aeromet';
    }

    /**
     * The current observation for a WMO/OMM station code (or several,
     * space-separated), one per station — falling back, station by station,
     * to the last one fetched successfully — marked stale — when a station
     * is missing from its group's current fetch, whether that is because
     * OGIMET could not be reached at all or because this round of it left
     * that one station out.
     *
     * @return array<int, AerometObservation>
     *
     * @throws RuntimeException when OGIMET cannot be reached and nothing was
     *                          ever fetched successfully for any of the
     *                          requested stations.
     */
    public function getObservations(string $wmoCode): array
    {
        $codes = array_values(array_filter(explode(' ', trim($wmoCode))));

        $groupIndexes = array_unique(array_filter(
            array_map($this->groupIndexFor(...), $codes),
            fn (?int $index) => $index !== null,
        ));

        $rows = [];
        $groupError = null;

        foreach ($groupIndexes as $index) {
            try {
                $rows = [...$rows, ...$this->currentRows((string) $index)];
            } catch (Throwable $e) {
                $groupError ??= $e;
            }
        }

        $byCode = $this->warmLastGood($rows);

        $observations = [];

        foreach ($codes as $code) {
            if (isset($byCode[$code])) {
                $observations[] = AerometObservation::fromArray($byCode[$code]);

                continue;
            }

            $lastGood = $this->lastGood($code);

            if ($lastGood !== null) {
                $observations[] = $lastGood->withStale(true);
            }
        }

        if ($groupError !== null) {
            if ($observations === []) {
                throw $groupError;
            }

            report($groupError);
        }

        return $observations;
    }

    /**
     * Fetch and warm one of AerometStationResolver::FIR_GROUPS, without
     * asking about any station in particular. What RefreshAerometCache
     * retries, one group at a time, on a schedule — so a live WhatsApp reply
     * is rarely the first thing to try OGIMET, and so a group that failed
     * can be retried without discarding what another group already warmed.
     *
     * @return array<int, array<string, mixed>> what this group warmed, by
     *                                          WMO/OMM code.
     *
     * @throws RuntimeException when this group could not be reached.
     */
    public function refreshGroup(int $index): array
    {
        return $this->warmLastGood($this->currentRows((string) $index));
    }

    /**
     * Which AerometStationResolver::FIR_GROUPS entry publishes a station, or
     * null for a code none of them name — an unknown code, which is a valid
     * thing to be asked about (see AerometStationResolver::codeFromText())
     * and simply never has anything to fetch.
     */
    protected function groupIndexFor(string $code): ?int
    {
        foreach (AerometStationResolver::FIR_GROUPS as $index => $codes) {
            if (in_array($code, $codes, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Index this round's rows by WMO/OMM code — via the station name every
     * row already carries, the same lookup AerometStationResolver::nameFor()
     * inverts — and overwrite each one's own last-good row so it is what
     * answers the next time that station drops out of a round.
     *
     * A WMO/OMM code is an all-digit string, so PHP itself normalizes it to
     * an int array key here (the usual "numeric string key" coercion) — the
     * return type reflects that rather than fighting it, since every lookup
     * against it normalizes the same way.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function warmLastGood(array $rows): array
    {
        $byCode = [];
        $fetchedAt = Carbon::now();

        foreach ($rows as $row) {
            $code = AerometStationResolver::STATIONS[$row['airport_name']] ?? null;

            if ($code === null) {
                continue;
            }

            $byCode[$code] = $row;

            AerometStationObservation::updateOrCreate(
                ['wmo_code' => $code],
                [
                    'airport_name' => $row['airport_name'],
                    'issued_at' => $row['issued_at'],
                    'raw' => $row['raw'],
                    'phenomenon_note' => $row['phenomenon_note'] ?? null,
                    'fetched_at' => $fetchedAt,
                ],
            );
        }

        return $byCode;
    }

    /**
     * A station's own last reading, or null when there has never been one —
     * or it is older than staleTtl, which counts as the same thing here: a
     * reading old enough to no longer say anything true about the weather is
     * no better than not having one.
     */
    protected function lastGood(string $wmoCode): ?AerometObservation
    {
        $row = AerometStationObservation::query()
            ->where('wmo_code', $wmoCode)
            ->where('fetched_at', '>=', Carbon::now()->subSeconds($this->staleTtl))
            ->first();

        if ($row === null) {
            return null;
        }

        return AerometObservation::fromArray([
            'airport_name' => $row->airport_name,
            'issued_at' => $row->issued_at,
            'raw' => $row->raw,
            'phenomenon_note' => $row->phenomenon_note,
        ]);
    }

    /**
     * @param  array<int, string>  $failures
     */
    protected function unreachable(array $failures): RuntimeException
    {
        return new RuntimeException(
            'No se pudo obtener el AEROMET de ninguna fuente ('.implode(', ', $failures).').'
        );
    }
}
