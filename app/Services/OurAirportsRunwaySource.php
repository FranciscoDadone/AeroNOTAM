<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runway ends from OurAirports, the open registry, keyed by OACI code.
 *
 * It is here because MADHEL — which is the official source and the one we would
 * rather use for everything — publishes an empty rwy list for exactly the
 * aerodromes that matter most. Ezeiza, Aeroparque, Córdoba, Mendoza, Rosario
 * and Tucumán all defer to the AIP instead, and a crosswind feature that cannot
 * answer for Ezeiza is not a feature. The two sources turn out to be almost
 * perfectly complementary: MADHEL covers the small aerodromes OurAirports has
 * never heard of, and OurAirports covers the large ones MADHEL leaves blank.
 *
 * Its headings are already referenced to true north, which is what the wind in
 * a METAR is measured against, so where they exist they are better than
 * anything derivable from a designator — a designator is the magnetic heading
 * rounded to ten degrees, and this is not rounded. They are not trusted
 * blindly, though: at least one Argentine record is plainly wrong (SAOC's
 * runway 05 is published as heading 178°, which is 128° from where a runway
 * numbered 05 can point), so the caller checks each one against the designator
 * before believing it.
 */
class OurAirportsRunwaySource
{
    /**
     * Only real runway numbers. OurAirports also lists helipads ("H1") and
     * compass-point strips ("N", "S"), which have no number to derive a
     * heading from and are not what this feature is about.
     */
    protected const DESIGNATOR = '/^(\d{1,2})([LCR])?$/';

    public function __construct(protected string $url) {}

    /**
     * Every runway end it knows, grouped by OACI code.
     *
     * Read as a stream and reduced row by row. The file is some 48,000 runways
     * worldwide, and holding it parsed — 48,000 arrays of twenty string fields
     * — costs more memory than PHP is given by default, for the sake of the
     * few hundred Argentine rows that are actually wanted.
     *
     * @return array<string, array<int, array{designator: string, heading_true: int|null, is_closed: bool}>>
     */
    public function endsByIcao(): array
    {
        $handle = $this->download();
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('El CSV de OurAirports vino vacío.');
        }

        $column = array_flip($header);
        $ends = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $icao = strtoupper(trim($line[$column['airport_ident']] ?? ''));

            if ($icao === '') {
                continue;
            }

            $closed = ($line[$column['closed']] ?? '') === '1';

            foreach ([['le_ident', 'le_heading_degT'], ['he_ident', 'he_heading_degT']] as [$identKey, $headingKey]) {
                $end = $this->end($line[$column[$identKey]] ?? '', $line[$column[$headingKey]] ?? '', $closed);

                if ($end !== null) {
                    $ends[$icao][] = $end;
                }
            }
        }

        fclose($handle);

        return $ends;
    }

    /**
     * @return array{designator: string, heading_true: int|null, is_closed: bool}|null
     */
    protected function end(string $ident, string $heading, bool $closed): ?array
    {
        if (preg_match(self::DESIGNATOR, strtoupper(trim($ident)), $m) !== 1) {
            return null;
        }

        $number = (int) $m[1];

        if ($number < 1 || $number > 36) {
            return null;
        }

        return [
            'designator' => sprintf('%02d', $number).($m[2] ?? ''),
            'heading_true' => is_numeric($heading) ? (int) round((float) $heading) % 360 : null,
            'is_closed' => $closed,
        ];
    }

    /**
     * The CSV, on a rewound temp stream ready to be read line by line.
     *
     * php://temp rather than php://memory: it spills to disk past a couple of
     * megabytes instead of counting the whole file against the memory limit.
     *
     * @return resource
     */
    protected function download()
    {
        $response = Http::timeout(120)->get($this->url);

        if ($response->failed()) {
            throw new RuntimeException("No se pudo descargar el listado de pistas de OurAirports (HTTP {$response->status()}).");
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir un buffer para leer el CSV de OurAirports.');
        }

        fwrite($handle, $response->body());
        rewind($handle);

        return $handle;
    }
}
