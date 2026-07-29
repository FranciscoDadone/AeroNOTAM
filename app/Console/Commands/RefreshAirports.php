<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\AnacNotamService;
use Illuminate\Console\Command;
use Throwable;

class RefreshAirports extends Command
{
    protected $signature = 'notams:refresh-airports';

    protected $description = 'Accrue newly observed aerodromes from ANAC and record which ones have active NOTAMs.';

    public function handle(AnacNotamService $anac): int
    {
        try {
            $indicators = $anac->getValidIndicators();
        } catch (Throwable $e) {
            report($e);

            $this->error('No se pudo obtener el listado de aeródromos de ANAC: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($indicators === []) {
            $this->warn('ANAC no devolvió ningún aeródromo.');

            return self::SUCCESS;
        }

        $before = Airport::query()->count();

        $rows = collect($indicators)
            ->map(fn (string $name, string $code) => [
                'anac_code' => $code,
                'name' => $name,
                'is_aerodrome' => Airport::isAerodromeCode($code),
                'last_seen_active_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        // Only last_seen_active_at is updated. What this command actually
        // learns is *which* aerodromes have a NOTAM today; everything else
        // about them — name, OACI code, classification — comes from MADHEL,
        // whose entries are fuller and often differ from the truncated labels
        // in the selector ("SAN LUIS/BRIGADIER MAYOR D. CESAR RAUL"). Letting
        // the hourly refresh write them back would make the registry oscillate.
        // The name on the INSERT still stands for codes MADHEL doesn't list.
        Airport::upsert($rows, ['anac_code'], ['is_aerodrome', 'last_seen_active_at', 'updated_at']);

        $added = Airport::query()->count() - $before;

        $this->info(sprintf(
            '%d aeródromos con NOTAM activos (%d nuevos). Total conocidos: %d.',
            count($rows),
            $added,
            Airport::query()->count(),
        ));

        return self::SUCCESS;
    }
}
