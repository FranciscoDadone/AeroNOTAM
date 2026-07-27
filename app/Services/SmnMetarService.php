<?php

namespace App\Services;

use App\DataObjects\Metar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Fetches METAR/SPECI observations from the Servicio Meteorológico Nacional.
 *
 * www.smn.gob.ar/metar is a shell page whose content is an iframe onto the
 * SMN's legacy "mensajes" application, so that application is what this talks
 * to — same data, same origin, one fewer redirect to parse.
 *
 * Returns raw observations. The Spanish explanation is applied separately by
 * MetarDecoder, mirroring how AnacNotamService keeps NOTAM scraping apart from
 * NOTAM decoding: the observation is the safety-relevant payload and must
 * survive anything going wrong downstream of it.
 */
class SmnMetarService
{
    protected string $baseUrl;

    protected int $ttl;

    protected int $attempts;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.smn.base_url'), '/');
        $this->ttl = (int) config('services.smn.metar_ttl');
        $this->attempts = (int) config('services.smn.attempts');
    }

    /**
     * Current observations for an ICAO station code, most recent first as the
     * SMN orders them.
     *
     * @return array<int, Metar>
     */
    public function getMetars(string $icaoCode): array
    {
        $icaoCode = strtoupper(trim($icaoCode));

        // Plain arrays into the cache, hydrated on the way out — a serialized
        // object would break on the next deploy that changes the class shape.
        $rows = Cache::remember(
            "smn_metar:{$icaoCode}",
            $this->ttl,
            fn () => $this->fetchMetars($icaoCode),
        );

        return array_map(Metar::fromArray(...), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchMetars(string $icaoCode): array
    {
        $body = $this->get([
            'observacion' => 'metar',
            'operacion' => 'consultar',
            'tipoEstacion' => 'OACI',
            'CODIGO' => $icaoCode,
        ]);

        return $this->parseMetarTables($body);
    }

    /**
     * The SMN sits behind Cloudflare, which intermittently answers with an
     * interstitial challenge instead of the page. The challenge is not tied to
     * this request being wrong — the identical request succeeds moments later —
     * so a small number of spaced retries clears it.
     *
     * Deliberately few, and deliberately spaced: Cloudflare tightens as request
     * volume rises, so retrying hard makes the block worse rather than better.
     * The real defence is the cache above, which keeps steady-state traffic to
     * a handful of requests per station per hour.
     *
     * @param  array<string, string>  $query
     */
    protected function get(array $query): string
    {
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'es-AR,es;q=0.9',
                    'Referer' => "{$this->baseUrl}/mensajes/index.php?operacion=seleccion&observacion=metar",
                ])
                ->get("{$this->baseUrl}/mensajes/index.php", $query);

            $lastStatus = $response->status();
            $body = $response->body();

            if ($response->successful() && ! $this->isChallenge($body)) {
                return $body;
            }

            if ($attempt < $this->attempts) {
                usleep(500_000 * $attempt);
            }
        }

        throw new RuntimeException(
            "No se pudo obtener el METAR del SMN tras {$this->attempts} intentos (HTTP {$lastStatus})."
        );
    }

    /**
     * Cloudflare's interstitial answers with either a 403 or a 200 carrying the
     * challenge page, so the body is what actually identifies it.
     */
    protected function isChallenge(string $body): bool
    {
        return str_contains($body, '_cf_chl_opt')
            || str_contains($body, '<title>Just a moment...</title>');
    }

    /**
     * One bordered table per station, each holding a header row with the
     * airport name and one result row per observation.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseMetarTables(string $html): array
    {
        $crawler = new Crawler($html);
        $metars = [];

        $crawler->filter('tr.headerResult')->each(function (Crawler $header) use (&$metars) {
            $airportName = $this->cleanText($header->text(), 'Aeropuerto');

            // Observations are the sibling rows of the header, inside the same
            // table — walking up to the table keeps two stations in one
            // response from bleeding into each other.
            $header->closest('table')?->filter('tr.result')->each(
                function (Crawler $row) use (&$metars, $airportName) {
                    $cells = $row->filter('td');

                    if ($cells->count() < 2) {
                        return;
                    }

                    $raw = $this->cleanText($cells->eq(1)->text());

                    if ($raw === '') {
                        return;
                    }

                    $metars[] = [
                        'station' => $this->stationFrom($raw),
                        'airport_name' => $airportName,
                        'observed_at' => $this->cleanText($cells->eq(0)->text()),
                        'raw' => $raw,
                    ];
                }
            );
        });

        return $metars;
    }

    /**
     * The ICAO code as it appears inside the report itself, which is the
     * authoritative one — the station queried and the station reported can
     * differ when the SMN relays a neighbouring aerodrome.
     */
    protected function stationFrom(string $raw): string
    {
        return preg_match('/^(?:METAR|SPECI)\s+(?:COR\s+)?([A-Z]{4})\b/', $raw, $m) === 1
            ? $m[1]
            : '';
    }

    protected function cleanText(string $text, string $prefix = ''): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if ($prefix !== '') {
            $text = str_ireplace($prefix, '', $text);
        }

        return trim($text);
    }
}
