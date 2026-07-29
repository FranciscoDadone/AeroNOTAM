<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\MadhelService;
use Illuminate\Console\Command;
use Throwable;

class ImportMadhelAirports extends Command
{
    protected $signature = 'notams:import-madhel
                            {--seed-file : Also rewrite the committed snapshot the seeder reads}
                            {--seed-path= : Where to write it, when not database/seeders/data/airports.php}';

    protected $description = 'Import ANAC\'s full aerodrome registry (MADHEL) into the local airports table.';

    /**
     * SQLite counts every bound value against one statement limit, so 712 rows
     * of eight columns go in batches rather than one enormous INSERT.
     */
    protected const CHUNK = 200;

    public function handle(MadhelService $madhel): int
    {
        try {
            $airports = $madhel->airports();
        } catch (Throwable $e) {
            report($e);

            $this->error('No se pudo importar el registro MADHEL: '.$e->getMessage());

            return self::FAILURE;
        }

        $before = Airport::query()->count();

        $this->releaseReassignedIcaoCodes($airports);

        foreach (array_chunk($this->rows($airports), self::CHUNK) as $chunk) {
            // last_seen_active_at is deliberately absent: that is ANAC's NOTAM
            // service talking, not MADHEL, and an import must not erase it.
            Airport::upsert(
                $chunk,
                ['anac_code'],
                ['icao_code', 'name', 'is_aerodrome', 'kind', 'access', 'is_controlled', 'is_closed', 'updated_at'],
            );
        }

        $added = Airport::query()->count() - $before;

        $this->info(sprintf(
            '%d aeródromos en MADHEL (%d nuevos). Total conocidos: %d.',
            count($airports),
            $added,
            Airport::query()->count(),
        ));

        if ($this->option('seed-file')) {
            $path = $this->option('seed-path') ?: database_path('seeders/data/airports.php');
            file_put_contents($path, $this->seedFile($airports));

            $this->info("Snapshot escrito en {$path}.");
        }

        return self::SUCCESS;
    }

    /**
     * MADHEL is the authority on which aerodrome owns an OACI code, and it does
     * not always agree with what we recorded before: SAWG was mapped here to
     * RGR (Río Gallegos / Río Chico) when it belongs to GAL (Piloto Civil N.
     * Fernández), and SATU to CZU when it belongs to CCA.
     *
     * icao_code is unique, so the previous holder has to let go before the
     * rightful one can claim it — otherwise the whole import fails on a
     * constraint violation.
     *
     * @param  array<int, array<string, mixed>>  $airports
     */
    protected function releaseReassignedIcaoCodes(array $airports): void
    {
        $owners = [];

        foreach ($airports as $airport) {
            if ($airport['icao_code'] !== null) {
                $owners[$airport['icao_code']] = $airport['anac_code'];
            }
        }

        if ($owners === []) {
            return;
        }

        $stale = Airport::query()
            ->whereIn('icao_code', array_keys($owners))
            ->get(['id', 'anac_code', 'icao_code'])
            ->filter(fn (Airport $airport) => $owners[$airport->icao_code] !== $airport->anac_code);

        foreach ($stale as $airport) {
            $this->line("  {$airport->icao_code}: {$airport->anac_code} → {$owners[$airport->icao_code]}");
        }

        if ($stale->isNotEmpty()) {
            Airport::query()->whereKey($stale->pluck('id'))->update(['icao_code' => null]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $airports
     * @return array<int, array<string, mixed>>
     */
    protected function rows(array $airports): array
    {
        return array_map(fn (array $airport) => $airport + [
            'is_aerodrome' => Airport::isAerodromeCode($airport['anac_code']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $airports);
    }

    /**
     * @param  array<int, array<string, mixed>>  $airports
     */
    protected function seedFile(array $airports): string
    {
        $rows = '';

        foreach ($airports as $airport) {
            $rows .= sprintf(
                "    ['anac_code' => %s, 'icao_code' => %s, 'name' => %s, 'kind' => %s, 'access' => %s, 'is_controlled' => %s, 'is_closed' => %s],\n",
                ...array_map($this->literal(...), [
                    $airport['anac_code'],
                    $airport['icao_code'],
                    $airport['name'],
                    $airport['kind'],
                    $airport['access'],
                    $airport['is_controlled'],
                    $airport['is_closed'],
                ]),
            );
        }

        return <<<PHP
        <?php

        /**
         * ANAC's aerodrome registry (MADHEL), snapshotted so a fresh install and
         * the test suite have the full list without depending on the network.
         *
         * Generated — do not edit by hand. Regenerate with:
         *
         *     php artisan notams:import-madhel --seed-file
         *
         * Aerodromes MADHEL publishes without an OACI/ICAO code carry null rather
         * than a guess: pointing at the wrong airport is far worse than admitting
         * we do not know.
         */

        return [
        {$rows}];

        PHP;
    }

    /**
     * var_export() renders null as "NULL", which Pint would then rewrite on the
     * next run and leave the generated file perpetually dirty.
     */
    protected function literal(string|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }
}
