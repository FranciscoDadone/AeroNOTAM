<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\MadhelService;
use App\Support\MadhelDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fills in what each aerodrome *is*: how far and in which direction from the
 * town it serves, how high, which FIR, and — where MADHEL publishes them —
 * fuel, telephone and opening hours.
 *
 * Same shape and same cost as notams:import-runways, for the same reason:
 * MADHEL keeps all of this on the per-aerodrome record, so it is one request
 * per aerodrome where importing the registry itself is one in total. That is
 * why this is weekly and pooled rather than folded into notams:import-madhel.
 *
 * It is a second crawl of the same 712 endpoints that import-runways already
 * walks, which is a deliberate trade: two commands that each do one thing,
 * against one command that would have the whole record in hand and could read
 * both halves of it in a single pass. If the five extra minutes a week ever
 * matter more than the separation does, folding this into ImportRunways is the
 * change — it already fetches the record and discards everything but data.rwy.
 */
class ImportAirportDetails extends Command
{
    protected $signature = 'notams:import-airport-details
                            {--only= : Sólo estos códigos ANAC, separados por coma}';

    protected $description = 'Import each aerodrome\'s MADHEL details — location, elevation, fuel, telephone — for the ficha.';

    /**
     * Concurrent MADHEL requests, the same eight import-runways settles on:
     * it walks the whole registry in about five minutes without reading as an
     * attack on a government service.
     */
    protected const POOL = 8;

    /**
     * SQLite counts every bound value against one statement limit, so the rows
     * go in batches rather than one enormous INSERT — same reason, same size as
     * notams:import-madhel.
     */
    protected const CHUNK = 200;

    public function handle(MadhelService $madhel): int
    {
        $airports = $this->airports();

        if ($airports->isEmpty()) {
            $this->warn('No hay aeródromos en el registro. Corré notams:import-madhel primero.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($airports->chunk(self::POOL) as $chunk) {
            foreach ($this->fetchDetails($madhel, $chunk) as $anacCode => $record) {
                /** @var Airport $airport */
                $airport = $chunk->firstWhere('anac_code', $anacCode);

                $details = MadhelDetails::parse($record);

                // upsert() goes straight to the query builder, so the model's
                // 'array' cast on telephone never runs — the encoding has to
                // happen here or PDO is handed a PHP array to bind.
                $details['telephone'] = $details['telephone'] === null
                    ? null
                    : json_encode($details['telephone'], JSON_UNESCAPED_UNICODE);

                $rows[] = [
                    'anac_code' => $anacCode,
                    // Carried only so the statement is a legal INSERT: SQLite
                    // checks NOT NULL before it gets as far as the conflict
                    // clause, and airports.name has no default. It is not in
                    // the update list below, so the row that already exists —
                    // which is every row, since this walks that same table —
                    // keeps whatever notams:import-madhel gave it.
                    'name' => $airport->name,
                ] + $details + ['details_updated_at' => now(), 'updated_at' => now()];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $batch) {
            // Only the columns this command owns. name, kind, access and the
            // coordinates belong to notams:import-madhel, and the magnetic
            // variation to notams:import-runways — an import must not undo
            // another one's work just because it has the same table open.
            Airport::upsert($batch, ['anac_code'], [
                'iata_code', 'fir', 'city_reference', 'distance_km', 'direction_reference',
                'elevation_m', 'state', 'traffic', 'is_aip_delegated', 'fuel', 'telephone',
                'service_schedule', 'details_updated_at', 'updated_at',
            ]);
        }

        $this->info(sprintf(
            '%d fichas importadas de %d aeródromos consultados. Con elevación: %d.',
            count($rows),
            $airports->count(),
            Airport::query()->whereNotNull('elevation_m')->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Airport>
     */
    protected function airports(): Collection
    {
        $query = Airport::query()->realAerodromes()->orderBy('anac_code');

        if ($only = $this->option('only')) {
            $query->whereIn('anac_code', array_map(
                fn (string $code) => strtoupper(trim($code)),
                explode(',', $only),
            ));
        }

        return $query->get();
    }

    /**
     * One pool's worth of MADHEL records, keyed by ANAC code.
     *
     * A record that fails to come back is skipped rather than retried, exactly
     * as in notams:import-runways: the command runs weekly, and a ficha that is
     * a week stale is a far smaller problem than an import of 712 aerodromes
     * that stalls on one bad response.
     *
     * @param  Collection<int, Airport>  $airports
     * @return array<string, array<string, mixed>>
     */
    protected function fetchDetails(MadhelService $madhel, Collection $airports): array
    {
        $records = [];

        foreach ($airports as $airport) {
            try {
                $record = $madhel->airport($airport->anac_code);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($record !== null) {
                $records[$airport->anac_code] = $record;
            }
        }

        return $records;
    }
}
