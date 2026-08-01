<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\AipService;
use App\Support\AipAdDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Fills in what the AIP publishes for the aerodromes MADHEL delegates to it —
 * fuel, telephone, hours and, new to the ficha, the tower/approach frequency
 * and the radio navigation aids — rather than leaving the ficha to say "sin
 * dato publicado en MADHEL" about a fact the AIP has had all along.
 *
 * Companion to notams:import-airport-details, not a replacement: that one
 * still owns elevation, location and the rest, which MADHEL publishes for
 * every aerodrome including the delegated ones. This walks only the ~40 that
 * are_aip_delegated, one PDF each, which is why — unlike the MADHEL imports —
 * it does not need a concurrent pool to finish in reasonable time.
 */
class ImportAipDetails extends Command
{
    protected $signature = 'notams:import-aip-details
                            {--only= : Sólo estos códigos ANAC, separados por coma}';

    protected $description = "Import each AIP-delegated aerodrome's fuel, telephone, horario, frecuencia ATS y radioayudas desde la AIP.";

    public function handle(AipService $aip, Parser $parser): int
    {
        $airports = $this->airports();

        if ($airports->isEmpty()) {
            $this->warn('No hay aeródromos delegados a la AIP en el registro. Corré notams:import-airport-details primero.');

            return self::SUCCESS;
        }

        try {
            $documents = $aip->adDocuments();
        } catch (Throwable $e) {
            report($e);
            $this->error('No se pudo leer el listado de la AIP: '.$e->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $withoutDocument = [];

        foreach ($airports as $airport) {
            $url = $airport->icao_code === null ? null : ($documents[$airport->icao_code] ?? null);

            if ($url === null) {
                $withoutDocument[] = $airport->anac_code;

                continue;
            }

            $details = $this->fetchDetails($aip, $parser, $url);

            if ($details === null) {
                continue;
            }

            $rows[] = [
                'anac_code' => $airport->anac_code,
                'name' => $airport->name,
                'aip_fuel' => $details['fuel'],
                'aip_telephone' => $details['telephone'] === null
                    ? null
                    : json_encode($details['telephone'], JSON_UNESCAPED_UNICODE),
                'aip_service_schedule' => $details['service_schedule'],
                'aip_ats_frequency' => $details['ats_frequency'],
                'aip_navaids' => $details['navaids'] === null
                    ? null
                    : json_encode($details['navaids'], JSON_UNESCAPED_UNICODE),
                'aip_details_updated_at' => now(),
                'updated_at' => now(),
            ];
        }

        Airport::upsert($rows, ['anac_code'], [
            'aip_fuel', 'aip_telephone', 'aip_service_schedule', 'aip_ats_frequency',
            'aip_navaids', 'aip_details_updated_at', 'updated_at',
        ]);

        $this->info(sprintf(
            '%d fichas AIP importadas de %d aeródromos delegados (%d sin documento AD-2.0 publicado).',
            count($rows),
            $airports->count(),
            count($withoutDocument),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Airport>
     */
    protected function airports(): Collection
    {
        $query = Airport::query()->realAerodromes()->where('is_aip_delegated', true)->orderBy('anac_code');

        if ($only = $this->option('only')) {
            $query->whereIn('anac_code', array_map(
                fn (string $code) => strtoupper(trim($code)),
                explode(',', $only),
            ));
        }

        return $query->get();
    }

    /**
     * One aerodrome's fields off its AIP PDF.
     *
     * A PDF that fails to download or parse is skipped rather than retried,
     * same reasoning as a MADHEL request that comes back empty: the ficha
     * stays a week stale for that one aerodrome rather than the whole import
     * failing over a single bad document.
     *
     * @return array{fuel: string|null, telephone: array<int, string>|null, service_schedule: string|null, ats_frequency: string|null, navaids: array<int, array{type: string, id: string|null, frequency: string, unit: string, hours: string|null}>|null}|null
     */
    protected function fetchDetails(AipService $aip, Parser $parser, string $url): ?array
    {
        try {
            $bytes = $aip->download($url);
            $text = $parser->parseContent($bytes)->getText();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return AipAdDetails::parse($text);
    }
}
