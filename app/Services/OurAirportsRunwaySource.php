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
 *
 * The same complementarity holds for the dimensions the ficha prints. MADHEL
 * writes "05/23 1871x30 M - ASPH" for the small aerodromes and nothing at all
 * for the delegated ones; this file has length, width, surface and lighting
 * for 225 of the 233 Argentine runways, Ezeiza and Santa Rosa included.
 */
class OurAirportsRunwaySource
{
    /**
     * Only real runway numbers. OurAirports also lists helipads ("H1") and
     * compass-point strips ("N", "S"), which have no number to derive a
     * heading from and are not what this feature is about.
     */
    protected const DESIGNATOR = '/^(\d{1,2})([LCR])?$/';

    /**
     * Its surface codes, mapped to the same Spanish words MadhelRunways
     * normalises to, so the ficha reads the same whichever source filled it in.
     *
     * The Argentine rows use eleven spellings for what are really five
     * surfaces: ASP/Asphalt/ASPHALT/PEM are all pavement, GRE ("graded or
     * rolled earth") is what MADHEL would call Tierra, and CON/Concrete is
     * hormigón. UNK and the blanks are absent on purpose — they are the file
     * saying it does not know, and null carries that honestly where "unknown"
     * printed in the ficha would not.
     *
     * @var array<string, string>
     */
    protected const SURFACES = [
        'ASP' => 'asfalto',
        'ASPH' => 'asfalto',
        'ASPHALT' => 'asfalto',
        'PEM' => 'asfalto',
        'BIT' => 'asfalto',
        'CON' => 'hormigón',
        'CONC' => 'hormigón',
        'CONCRETE' => 'hormigón',
        'GRE' => 'tierra',
        'DIRT' => 'tierra',
        'EARTH' => 'tierra',
        'CLAY' => 'tierra',
        'GRS' => 'pasto',
        'GRASS' => 'pasto',
        'TURF' => 'pasto',
        'GVL' => 'ripio',
        'GRVL' => 'ripio',
        'GRAVEL' => 'ripio',
        'SAND' => 'arena',
        'SNO' => 'nieve',
        'WATER' => 'agua',
    ];

    /**
     * Feet to metres. The file publishes both dimensions in feet; the ficha
     * quotes metres, which is what MADHEL publishes and what Argentine charts
     * print, so the conversion happens once here rather than on every read.
     */
    protected const FEET_TO_METRES = 0.3048;

    public function __construct(protected string $url) {}

    /**
     * Every runway end it knows, grouped by OACI code.
     *
     * Read as a stream and reduced row by row. The file is some 48,000 runways
     * worldwide, and holding it parsed — 48,000 arrays of twenty string fields
     * — costs more memory than PHP is given by default, for the sake of the
     * few hundred Argentine rows that are actually wanted.
     *
     * @return array<string, array<int, array{designator: string, heading_true: int|null, is_closed: bool, length_m: int|null, width_m: int|null, surface: string|null, is_lighted: bool|null}>>
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

            // Length, width, surface and lighting are published once for the
            // whole strip, so both ends carry the same figures — which is also
            // how they are stored, one row per end.
            $strip = $this->strip($line, $column);

            foreach ([['le_ident', 'le_heading_degT'], ['he_ident', 'he_heading_degT']] as [$identKey, $headingKey]) {
                $end = $this->end($line[$column[$identKey]] ?? '', $line[$column[$headingKey]] ?? '', $closed);

                if ($end !== null) {
                    $ends[$icao][] = $end + $strip;
                }
            }
        }

        fclose($handle);

        return $ends;
    }

    /**
     * The strip both ends of one CSV row share.
     *
     * A zero length or width is the file's way of saying it does not have the
     * figure, not a runway with no length, so it comes back null rather than
     * as a 0 m runway.
     *
     * @param  array<int, string|null>  $line
     * @param  array<string, int>  $column
     * @return array{length_m: int|null, width_m: int|null, surface: string|null, is_lighted: bool|null}
     */
    protected function strip(array $line, array $column): array
    {
        $lighted = $line[$column['lighted']] ?? '';

        return [
            'length_m' => $this->metres($line[$column['length_ft']] ?? ''),
            'width_m' => $this->metres($line[$column['width_ft']] ?? ''),
            'surface' => self::SURFACES[strtoupper(trim($line[$column['surface']] ?? ''))] ?? null,
            // Only "1" and "0" are statements; a blank is the file not saying,
            // and claiming a runway is unlit would be a claim about a night
            // landing that silence does not support.
            'is_lighted' => match ($lighted) {
                '1' => true,
                '0' => false,
                default => null,
            },
        ];
    }

    protected function metres(string $feet): ?int
    {
        return is_numeric($feet) && (float) $feet > 0
            ? (int) round((float) $feet * self::FEET_TO_METRES)
            : null;
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
