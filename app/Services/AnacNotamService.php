<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class AnacNotamService
{
    /**
     * ANAC's own place selector only ever lists airports with a currently
     * active NOTAM ("Los lugares sin novedades activas no se incluyen en la
     * lista" — stated on the page itself), so it can't tell "unknown code"
     * apart from "known airport, nothing active right now". This seed —
     * codes/names observed directly from that selector — plus the accrual
     * in getKnownAirports() below give us a broader, persistent registry to
     * check real airports against regardless of today's NOTAM activity.
     *
     * @var array<string, string>
     */
    protected const KNOWN_AIRPORTS_SEED = [
        'AER' => 'AEROPARQUE J. NEWBERY',
        'AGR' => 'ALTA GRACIA/COMANDANTE EDMUNDO OLSVALDO WEISS',
        'BCA' => 'BAHIA BLANCA/CTE ESPORA',
        'BAL' => 'BALCARCE',
        'CAT' => 'CATAMARCA',
        'IGU' => 'CATARATAS DEL IGUAZU / M. C. E. KRAUSE',
        'CRV' => 'COMODORO RIVADAVIA/GRAL. E. MOSCONI',
        'CDU' => 'CONCEPCIÓN DEL URUGUAY',
        'FMA' => 'CORDOBA/CAPITAN D.OMAR DARIO GELARDI',
        'ESC' => 'CORDOBA/ESCUELA DE AVIACION MILITAR',
        'CBA' => 'CORDOBA/ING. AER. A. L. V. TARAVELLA',
        'CRR' => 'CORRIENTES',
        'CZU' => 'CURUZU CUATIA/AEROCLUB',
        'ECA' => 'EL CALAFATE',
        'PAL' => 'EL PALOMAR',
        'ESQ' => 'ESQUEL/BRIGADIER GENERAL ANTONIO PARODI',
        'EZE' => 'EZEIZA/MINISTRO PISTARINI',
        'GPI' => 'GENERAL PICO',
        'GOY' => 'GOYA',
        'GUA' => 'GUALEGUAYCHU',
        'JUJ' => 'JUJUY/GOBERNADOR GUZMAN',
        'PTA' => 'LA PLATA',
        'LAR' => 'LA RIOJA/CAP. VICENTE A. ALMONACID',
        'FLO' => 'LAS FLORES',
        'MLG' => 'MALARGÜE',
        'MDP' => 'MAR DEL PLATA/ASTOR PIAZZOLLA',
        'ENO' => 'MARIANO MORENO',
        'MAT' => 'MATANZA',
        'DOZ' => 'MENDOZA/EL PLUMERILLO',
        'NEU' => 'NEUQUEN/PRESIDENTE PERON',
        'LIO' => 'NUEVE DE JULIO',
        'LIB' => 'PASO DE LOS LIBRES',
        'PEH' => 'PEHUAJO/COM. PEDRO ZANNI',
        'PTM' => 'PERITO MORENO',
        'POS' => 'POSADAS',
        'DRY' => 'PUERTO MADRYN/EL TEHUELCHE',
        'PDI' => 'PUNTA INDIO',
        'ILM' => 'QUILMES',
        'RAI' => 'RANCHOS/LA IGUALDAD',
        'RTA' => 'RECONQUISTA',
        'SIS' => 'RESISTENCIA',
        'RGR' => 'RIO GALLEGOS/RIO CHICO',
        'GRA' => 'RIO GRANDE',
        'ROS' => 'ROSARIO/ ISLAS MALVINAS',
        'SAL' => 'SALTA',
        'GBL' => 'SALTA/GENERAL BELGRANO',
        'SLT' => 'SALTO',
        'BAR' => 'SAN CARLOS DE BARILOCHE',
        'FDO' => 'SAN FERNANDO',
        'SJR' => 'SAN JAVIER/AEROCLUB',
        'JUA' => 'SAN JUAN',
        'UIS' => 'SAN LUIS/BRIGADIER MAYOR D. CESAR RAUL OJEDA',
        'SMM' => 'SAN MIGUEL DEL MONTE/BAHIA AGRADABLE',
        'SRA' => 'SAN RAFAEL/S. A. SANTIAGO GERMANO',
        'SCZ' => 'SANTA CRUZ',
        'SVO' => 'SANTA FE/SAUCE VIEJO',
        'OSA' => 'SANTA ROSA',
        'TRH' => 'TERMAS DE RIO HONDO',
        'TRE' => 'TRELEW/ALMIRANTE ZAR',
        'TUC' => 'TUCUMAN/TEN. BENJAMIN MATIENZO',
        'USU' => 'USHUAIA/MALVINAS ARGENTINAS',
        'SRC' => 'VALLE DEL CONLARA',
        'VIE' => 'VIEDMA/GOBERNADOR CASTELLO',
        'RYD' => 'VILLA REYNOLDS',
    ];

    /**
     * OACI/ICAO 4-letter code (e.g. "SAEZ") -> ANAC's own 3-letter code
     * (e.g. "EZE"), for the airports above we have a confirmed ICAO code
     * for. Cross-referenced against Wikipedia's Argentina airport list, not
     * guessed — a handful of smaller aerodromes in the seed above (AGR, BAL,
     * CDU, GBL, SLT, RAI, SJR, SMM, RYD) have no confirmed mapping and are
     * intentionally left out rather than risk pointing at the wrong airport.
     *
     * @var array<string, string>
     */
    protected const OACI_TO_LOCAL = [
        'SABE' => 'AER',
        'SAZB' => 'BCA',
        'SANC' => 'CAT',
        'SARI' => 'IGU',
        'SAVC' => 'CRV',
        'SACA' => 'FMA',
        'SACE' => 'ESC',
        'SACO' => 'CBA',
        'SARC' => 'CRR',
        'SATU' => 'CZU',
        'SAWC' => 'ECA',
        'SADP' => 'PAL',
        'SAVE' => 'ESQ',
        'SAEZ' => 'EZE',
        'SAZG' => 'GPI',
        'SATG' => 'GOY',
        'SAAG' => 'GUA',
        'SASJ' => 'JUJ',
        'SADL' => 'PTA',
        'SANL' => 'LAR',
        'SAEL' => 'FLO',
        'SAMM' => 'MLG',
        'SAZM' => 'MDP',
        'SADJ' => 'ENO',
        'SADZ' => 'MAT',
        'SAME' => 'DOZ',
        'SAZN' => 'NEU',
        'SAZX' => 'LIO',
        'SARL' => 'LIB',
        'SAZP' => 'PEH',
        'SAWP' => 'PTM',
        'SARP' => 'POS',
        'SAVY' => 'DRY',
        'SAAI' => 'PDI',
        'SADQ' => 'ILM',
        'SATR' => 'RTA',
        'SARE' => 'SIS',
        'SAWG' => 'RGR',
        'SAWE' => 'GRA',
        'SAAR' => 'ROS',
        'SASA' => 'SAL',
        'SAZS' => 'BAR',
        'SADF' => 'FDO',
        'SANU' => 'JUA',
        'SAOU' => 'UIS',
        'SAMR' => 'SRA',
        'SAWU' => 'SCZ',
        'SAAV' => 'SVO',
        'SAZR' => 'OSA',
        'SANR' => 'TRH',
        'SAVT' => 'TRE',
        'SANT' => 'TUC',
        'SAWH' => 'USU',
        'SAOS' => 'SRC',
        'SAVV' => 'VIE',
    ];

    protected string $baseUrl;

    protected int $indicatorsTtl;

    public function __construct(protected AiNotamDecoderService $aiDecoder)
    {
        $this->baseUrl = rtrim(config('services.anac.base_url'), '/');
        $this->indicatorsTtl = (int) config('services.anac.indicators_ttl');
    }

    /**
     * Places (airports/FIRs) ANAC currently lists as having active NOTAMs,
     * keyed by indicator code (e.g. "EZE") with the display name as the value.
     *
     * @return array<string, string>
     */
    public function getValidIndicators(): array
    {
        return Cache::remember('anac_notam_indicators', $this->indicatorsTtl, function () {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get("{$this->baseUrl}/notam");

            if ($response->failed()) {
                throw new RuntimeException("No se pudo obtener el listado de aeródromos de ANAC (HTTP {$response->status()}).");
            }

            $crawler = new Crawler($response->body());
            $indicators = [];

            $crawler->filter('select#locations option')->each(function (Crawler $node) use (&$indicators) {
                $value = trim($node->attr('value') ?? '');

                if ($value !== '') {
                    $indicators[$value] = trim(preg_replace('/\s+/', ' ', $node->text()));
                }
            });

            return $indicators;
        });
    }

    /**
     * All airports we currently know to be real, regardless of whether they
     * have an active NOTAM right now — the seed above, plus every code ever
     * observed in a live getValidIndicators() fetch, accrued permanently.
     * This is what lets us tell "PEH doesn't exist" apart from "PEH exists,
     * nothing active today", which ANAC's own selector cannot.
     *
     * @return array<string, string>
     */
    public function getKnownAirports(): array
    {
        $known = Cache::get('anac_known_airports') ?? self::KNOWN_AIRPORTS_SEED;

        try {
            $current = $this->getValidIndicators();
        } catch (Throwable) {
            return $known;
        }

        $merged = $known + $current;

        if ($merged !== $known) {
            Cache::forever('anac_known_airports', $merged);
        }

        return $merged;
    }

    /**
     * Resolve a user-supplied code to ANAC's own indicator: passes through
     * unchanged if it's already an ANAC code (or unrecognized), translates
     * it if it's a 4-letter OACI/ICAO code we have a mapping for (e.g.
     * "SAEZ" -> "EZE").
     */
    public function resolveIndicator(string $code): string
    {
        $code = strtoupper(trim($code));

        return self::OACI_TO_LOCAL[$code] ?? $code;
    }

    /**
     * @return array<string, string>
     */
    public function oaciCodes(): array
    {
        return self::OACI_TO_LOCAL;
    }

    /**
     * Fetch and parse the active NOTAMs for a given ANAC place indicator.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNotams(string $indicator): array
    {
        $response = Http::asForm()
            ->timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => "{$this->baseUrl}/notam",
            ])
            ->post("{$this->baseUrl}/notam/pib", [
                'indicador' => $indicator,
            ]);

        // ANAC's backend throws a 500 when a place has no active NOTAMs.
        if ($response->status() === 500) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException("Error al consultar NOTAMs en ANAC (HTTP {$response->status()}).");
        }

        return $this->parseNotamTable($response->body());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseNotamTable(string $html): array
    {
        $crawler = new Crawler($html);
        $notams = [];

        $crawler->filter('table#datatable tbody tr')->each(function (Crawler $row) use (&$notams) {
            $place = $row->filter('td#place p');
            $info = $row->filter('td#info p');

            if ($place->count() < 3 || $info->count() < 3) {
                return;
            }

            $validUntilRaw = $this->cleanText($info->eq(1)->text(), 'Hasta:');
            $isPermanent = strcasecmp($validUntilRaw, 'Perm') === 0;

            [$textEn, $textEs] = $this->splitBilingualText($info->eq(2));

            $notams[] = [
                'number' => $this->cleanText($place->eq(0)->text()),
                'location_code' => trim($this->cleanText($place->eq(2)->text()), '()'),
                'location_name' => $this->cleanText($place->eq(1)->text()),
                'valid_from' => $this->cleanText($info->eq(0)->text(), 'Desde:'),
                'valid_until' => $isPermanent ? null : $validUntilRaw,
                'permanent' => $isPermanent,
                'text_en' => $textEn,
                'text_es' => $textEs,
                'decoded_es' => $this->aiDecoder->decode($textEn, $textEs, $isPermanent ? null : $validUntilRaw),
            ];
        });

        return $notams;
    }

    protected function cleanText(string $text, string $prefix = ''): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if ($prefix !== '') {
            $text = str_ireplace($prefix, '', $text);
        }

        return trim($text);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function splitBilingualText(Crawler $node): array
    {
        $innerHtml = $node->html();
        $parts = preg_split('/<span[^>]*id=["\']versionbreak["\'][^>]*>.*?<\/span>/is', $innerHtml, 2);

        $textEn = $this->stripAndDecode($parts[0] ?? '');
        $textEs = isset($parts[1]) ? $this->stripAndDecode($parts[1]) : null;

        return [$textEn, $textEs !== null && $textEs !== '' ? $textEs : null];
    }

    protected function stripAndDecode(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
