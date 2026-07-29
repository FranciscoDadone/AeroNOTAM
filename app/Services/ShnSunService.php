<?php

namespace App\Services;

use App\DataObjects\SunTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sunrise, sunset and civil twilight from the Servicio de Hidrografía Naval —
 * the Argentine authority on the matter, and the same table its Astronomía page
 * shows.
 *
 * The twilight is what makes this worth having: the sun sets half an hour before
 * it is dark, and that half hour is the difference between arriving legally and
 * arriving at night. The SHN publishes civil twilight, which is the definition
 * the local rules use.
 */
class ShnSunService
{
    /**
     * The localities the SHN publishes, spelled exactly as its <select> does —
     * no accents, uppercase. These strings are the wire format: the form
     * rejects anything else with a 500.
     */
    public const CITIES = [
        'AZUL',
        'BAHIA BLANCA',
        'BUENOS AIRES',
        'COMODORO RIVADAVIA',
        'CORDOBA',
        'CORRIENTES',
        'DESTACAMENTO NAVAL ESPERANZA',
        'FORMOSA',
        'LA PLATA',
        'LA QUIACA',
        'LA RIOJA',
        'MALARGUE',
        'MAR DEL PLATA',
        'MENDOZA',
        'NEUQUEN',
        'PARANA',
        'PERITO MORENO',
        'POSADAS',
        'RAWSON',
        'RESISTENCIA',
        'RIO GALLEGOS',
        'ROSARIO',
        'SALTA',
        'SAN CARLOS DE BARILOCHE',
        'SAN FERNANDO DEL VALLE DE CATAMARCA',
        'SAN JUAN',
        'SAN LUIS',
        'SAN MIGUEL DE TUCUMAN',
        'SAN SALVADOR DE JUJUY',
        'SANTA FE',
        'SANTA ROSA',
        'SANTIAGO DEL ESTERO',
        'USHUAIA',
        'VIEDMA',
    ];

    /**
     * The hours the SHN prints are Argentine official time, which has been a
     * fixed UTC-3 with no daylight saving since 2009. A named zone would be
     * the safer habit, but here the offset is the published one — the page
     * itself says "Huso Horario 3 horas al Oeste de Greenwich" — and pinning it
     * means a future zone change cannot silently shift data we did not compute.
     */
    public const OFFSET = '-03:00';

    /**
     * How the form spells each month.
     */
    protected const MONTHS = [
        1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
        'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic',
    ];

    /**
     * What the SHN prints instead of an hour when there is none to print, and
     * what each one means. Only the high-latitude localities ever hit these —
     * Destacamento Naval Esperanza is the one on the list.
     */
    protected const SYMBOLS = ['***', '----', '////'];

    protected string $baseUrl;

    protected int $ttl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.shn.base_url'), '/');
        $this->ttl = (int) config('services.shn.ttl');
    }

    /**
     * The sun's schedule for one day, or null if the SHN's table has no row for
     * it (a day the month does not have).
     */
    public function forDate(string $city, CarbonImmutable $date): ?SunTimes
    {
        $rows = $this->monthRows($city, (int) $date->year, (int) $date->month);
        $row = $rows[$date->day] ?? null;

        if ($row === null) {
            return null;
        }

        $symbols = [];
        $moments = [];

        foreach (['dawn', 'sunrise', 'sunset', 'dusk'] as $moment) {
            $value = $row[$moment] ?? null;

            if ($value === null || in_array($value, self::SYMBOLS, true)) {
                $moments[$moment] = null;

                if ($value !== null) {
                    $symbols[$moment] = $value;
                }

                continue;
            }

            $moments[$moment] = CarbonImmutable::createFromFormat(
                'Y-m-d H:i',
                $date->format('Y-m-d')." {$value}",
                self::OFFSET,
            )->utc();
        }

        return new SunTimes(
            city: $city,
            date: $date,
            dawn: $moments['dawn'],
            sunrise: $moments['sunrise'],
            sunset: $moments['sunset'],
            dusk: $moments['dusk'],
            symbols: $symbols,
        );
    }

    /**
     * A whole month's table, keyed by day of the month.
     *
     * The SHN answers one request with the entire month, and a month of
     * astronomy is published in advance and never changes — so this is cached
     * hard. Asking a government service twice for a table we already have is
     * the only way this feature could become a nuisance to anyone.
     *
     * @return array<int, array{dawn: ?string, sunrise: ?string, sunset: ?string, dusk: ?string}>
     */
    public function monthRows(string $city, int $year, int $month): array
    {
        return Cache::remember(
            sprintf('shn_sun:%s:%04d-%02d', $city, $year, $month),
            $this->ttl,
            fn () => $this->fetchMonth($city, $year, $month),
        );
    }

    /**
     * @return array<int, array{dawn: ?string, sunrise: ?string, sunset: ?string, dusk: ?string}>
     */
    protected function fetchMonth(string $city, int $year, int $month): array
    {
        // The form on Astronomia.asp carries a CSRFToken and this request does
        // not send one: the endpoint ignores it, and requiring it would mean
        // fetching and parsing the form page before every query. Verified, not
        // assumed — do not "fix" this by adding the token back without checking
        // that it is now enforced.
        $response = Http::asForm()
            ->timeout(15)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->post("{$this->baseUrl}/observatorio/REsol.asp", [
                'Localidad' => $city,
                'Mes' => self::MONTHS[$month],
                'Fanio' => $year,
            ]);

        // An unknown locality comes back as a 500, the same way ANAC's NOTAM
        // backend reports "nothing here".
        if ($response->failed()) {
            throw new RuntimeException("No se pudo obtener la tabla del sol del SHN (HTTP {$response->status()}).");
        }

        $rows = $this->parseTable($response->body());

        if ($rows === []) {
            throw new RuntimeException("El SHN devolvió una tabla vacía para {$city}.");
        }

        return $rows;
    }

    /**
     * Pull the day rows out of the SHN's table.
     *
     * The columns are day, morning twilight, sunrise, sunrise azimuth, sunset,
     * sunset azimuth, evening twilight. The two azimuths are read past: they
     * are bearings to the sun on the horizon, useful on a chart and noise in a
     * WhatsApp message.
     *
     * @return array<int, array{dawn: ?string, sunrise: ?string, sunset: ?string, dusk: ?string}>
     */
    protected function parseTable(string $html): array
    {
        $days = [];

        (new Crawler($html))->filter('table.interlineado tbody tr')->each(function (Crawler $row) use (&$days) {
            $cells = $row->filter('td')->each(fn (Crawler $cell) => trim($cell->text()));

            if (count($cells) < 7 || ! ctype_digit($cells[0])) {
                return;
            }

            $days[(int) $cells[0]] = [
                'dawn' => $this->hour($cells[1]),
                'sunrise' => $this->hour($cells[2]),
                'sunset' => $this->hour($cells[4]),
                'dusk' => $this->hour($cells[6]),
            ];
        });

        return $days;
    }

    /**
     * An "HH:MM" cell, the symbol it carries instead, or null if it is neither —
     * an unrecognised cell is dropped rather than guessed at.
     */
    protected function hour(string $cell): ?string
    {
        if (preg_match('/^\d{1,2}:\d{2}$/', $cell) === 1) {
            return $cell;
        }

        foreach (self::SYMBOLS as $symbol) {
            if (str_contains($cell, $symbol)) {
                return $symbol;
            }
        }

        return null;
    }
}
