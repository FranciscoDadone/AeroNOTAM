<?php

namespace App\Services;

use App\Contracts\MetarSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads METAR observations from the Servicio Meteorológico Nacional — the
 * authoritative publisher for Argentine aerodromes, and therefore the source
 * MetarService tries first.
 *
 * www.smn.gob.ar/metar is a shell page whose content is an iframe onto the
 * SMN's legacy "mensajes" application, so that application is what this talks
 * to — same data, same origin, one fewer redirect to parse.
 *
 * Caching and failover live in MetarService, not here: this class's only job is
 * to answer with the SMN's observations or admit it could not reach them.
 */
class SmnMetarSource implements MetarSource
{
    protected string $baseUrl;

    protected int $attempts;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.smn.base_url'), '/');
        $this->attempts = (int) config('services.smn.attempts');
    }

    public function name(): string
    {
        return 'smn';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $body = $this->get([
            'observacion' => 'metar',
            'operacion' => 'consultar',
            'tipoEstacion' => 'OACI',
            'CODIGO' => strtoupper(trim($icaoCode)),
        ]);

        return $this->parseMetarTables($body);
    }

    /**
     * The SMN sits behind Cloudflare, which answers with an interstitial
     * instead of the page for stretches at a time.
     *
     * The retries here only cover the isolated challenge. They deliberately do
     * not try to outlast a sustained block: the block tightens the more it is
     * hit, so hammering it keeps it alive rather than clearing it. Getting past
     * a real block is MetarService's job — it stops asking for a while and
     * falls through to the next source.
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
