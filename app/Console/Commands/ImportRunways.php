<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\Runway;
use App\Services\MadhelService;
use App\Services\MagneticVariationService;
use App\Services\OurAirportsRunwaySource;
use App\Support\MadhelRunways;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fills the runways table, so the bot can answer which end of an aerodrome the
 * wind favours and how much of it lands across the runway.
 *
 * Two sources, because neither is enough on its own. MADHEL is ANAC's own
 * registry and the one to believe, but it publishes an empty runway list for
 * the aerodromes that get asked about most — Ezeiza, Aeroparque, Córdoba,
 * Mendoza, Rosario, Tucumán all defer to the AIP. OurAirports covers exactly
 * those, and misses exactly the small ones MADHEL has. Together they reach
 * effectively the whole public registry.
 *
 * The expensive part is that MADHEL only lists runways on its per-aerodrome
 * record, so this costs one request per aerodrome where importing the registry
 * itself costs one in total. That is why it is a command of its own, scheduled
 * weekly rather than folded into notams:import-madhel, and why the requests go
 * out in pools.
 */
class ImportRunways extends Command
{
    protected $signature = 'notams:import-runways
                            {--only= : Sólo estos códigos ANAC, separados por coma}';

    protected $description = 'Import runway ends and their true headings, so the bot can compute wind components.';

    /**
     * Concurrent MADHEL requests. Eight walks the whole registry in about five
     * minutes without reading as an attack on a government service.
     */
    protected const POOL = 8;

    /**
     * How far a published true heading may sit from where the designator says
     * the runway points before it is disbelieved.
     *
     * A designator is the magnetic heading rounded to ten degrees, so once the
     * magnetic variation is added back the two should agree within a handful of
     * degrees; twenty is slack. What this rejects is not imprecision but
     * nonsense — OurAirports has SAOC's runway 05 at a true heading of 178°,
     * which is 128° from anywhere a runway numbered 05 can point.
     */
    protected const HEADING_TOLERANCE = 20;

    public function handle(
        MadhelService $madhel,
        OurAirportsRunwaySource $ourAirports,
        MagneticVariationService $variations,
    ): int {
        $airports = $this->airports();

        if ($airports->isEmpty()) {
            $this->warn('No hay aeródromos en el registro. Corré notams:import-madhel primero.');

            return self::SUCCESS;
        }

        try {
            $openEnds = $ourAirports->endsByIcao();
        } catch (Throwable $e) {
            report($e);

            // Not fatal. MADHEL alone still covers most of the registry, and a
            // partial import beats none — the aerodromes that need OurAirports
            // simply keep whatever rows they already had.
            $this->warn('No se pudo leer OurAirports: '.$e->getMessage());
            $openEnds = [];
        }

        $imported = 0;
        $withoutData = [];

        foreach ($airports->chunk(self::POOL) as $chunk) {
            foreach ($this->fetchRunwayEntries($madhel, $chunk) as $anacCode => $entries) {
                /** @var Airport $airport */
                $airport = $chunk->firstWhere('anac_code', $anacCode);

                $ends = MadhelRunways::parse($entries);
                $source = 'madhel';

                if ($ends === [] && $airport->icao_code !== null) {
                    $ends = $openEnds[$airport->icao_code] ?? [];
                    $source = 'ourairports';
                }

                if ($ends === []) {
                    $withoutData[] = $anacCode;

                    continue;
                }

                $this->store($airport, $ends, $source, $variations->for($airport));
                $imported++;
            }
        }

        $this->info(sprintf(
            '%d aeródromos con pistas (%d sin datos). Total de cabeceras: %d.',
            $imported,
            count($withoutData),
            Runway::query()->count(),
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
     * One pool's worth of MADHEL records, reduced to just the runway lists.
     *
     * A record that fails to come back is skipped rather than retried: the
     * command runs weekly, and a runway that is missing for seven days is a far
     * smaller problem than an import that stalls on one bad response.
     *
     * @param  Collection<int, Airport>  $airports
     * @return array<string, array<int, mixed>>
     */
    protected function fetchRunwayEntries(MadhelService $madhel, Collection $airports): array
    {
        $entries = [];

        foreach ($airports as $airport) {
            try {
                $record = $madhel->airport($airport->anac_code);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $rwy = data_get($record, 'data.rwy');
            $entries[$airport->anac_code] = is_array($rwy) ? $rwy : [];
        }

        return $entries;
    }

    /**
     * Write one aerodrome's ends, and drop the ones that are no longer listed.
     *
     * The delete is what lets a renumbering land. Magnetic drift eventually
     * forces ANAC to renumber a runway — 05/23 becomes 06/24 — and without
     * this the old pair would linger forever alongside the new one, offering a
     * pilot a cabecera that no longer exists.
     *
     * @param  array<int, array{designator: string, is_closed: bool, heading_true?: int|null}>  $ends
     */
    protected function store(Airport $airport, array $ends, string $source, float $variation): void
    {
        $rows = [];

        foreach ($ends as $end) {
            $designator = $end['designator'];

            $rows[$designator] = [
                'anac_code' => $airport->anac_code,
                'designator' => $designator,
                'heading_true' => $this->headingFor($designator, $end['heading_true'] ?? null, $variation),
                'is_closed' => $end['is_closed'],
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Runway::upsert(
            array_values($rows),
            ['anac_code', 'designator'],
            ['heading_true', 'is_closed', 'source', 'updated_at'],
        );

        Runway::query()
            ->where('anac_code', $airport->anac_code)
            ->whereNotIn('designator', array_keys($rows))
            ->delete();
    }

    /**
     * The end's heading in degrees true.
     *
     * The designator gives the magnetic heading to the nearest ten degrees, and
     * adding the variation turns it into a true one — that alone is accurate
     * enough, since a crosswind is a cosine and five degrees of slop in the
     * angle is worth well under a knot at ordinary speeds.
     *
     * A published true heading is better still, because it is not rounded, so
     * it wins whenever it exists and agrees with the designator. Whenever it
     * does not agree, it is the published figure that is wrong.
     */
    protected function headingFor(string $designator, ?int $published, float $variation): int
    {
        $magnetic = ((int) preg_replace('/\D/', '', $designator) % 36) * 10;
        $derived = (int) round(fmod($magnetic + $variation + 360, 360));

        if ($published === null) {
            return $derived;
        }

        $difference = abs($published - $derived) % 360;

        return min($difference, 360 - $difference) <= self::HEADING_TOLERANCE
            ? $published
            : $derived;
    }
}
