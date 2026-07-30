<?php

namespace App\Services;

use App\Contracts\AviationReportSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads reports from the Servicio Meteorológico Nacional — the authoritative
 * publisher for Argentine aerodromes, and therefore the source tried first.
 *
 * www.smn.gob.ar/metar and /taf are shell pages whose content is an iframe onto
 * the SMN's legacy "mensajes" application, so that application is what this
 * talks to — same data, same origin, one fewer redirect to parse. Observations
 * and forecasts come off the same screen with the same markup, differing only
 * in the "observacion" parameter, which is why one parser serves both.
 *
 * Caching and failover live in AviationReportService, not here: a source's only
 * job is to answer with the SMN's reports or admit it could not reach them.
 */
abstract class SmnReportSource implements AviationReportSource
{
    protected string $baseUrl;

    protected int $attempts;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.smn.base_url'), '/');
        $this->attempts = (int) config('services.smn.attempts');
        $this->timeout = (int) config('services.smn.timeout');
    }

    public function name(): string
    {
        return 'smn';
    }

    /**
     * The value the legacy application expects in "observacion": 'metar', 'taf'.
     */
    abstract protected function observation(): string;

    /**
     * The ICAO code as it appears inside the report itself, which is the
     * authoritative one — the station queried and the station reported can
     * differ when the SMN relays a neighbouring aerodrome.
     */
    abstract protected function stationFrom(string $raw): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $body = $this->get([
            'observacion' => $this->observation(),
            'operacion' => 'consultar',
            'tipoEstacion' => 'OACI',
            'CODIGO' => strtoupper(trim($icaoCode)),
        ]);

        return $this->parseResultTables($body);
    }

    /**
     * The SMN sits behind Cloudflare, which answers with an interstitial
     * instead of the page for stretches at a time.
     *
     * The retries here only cover the isolated challenge. They deliberately do
     * not try to outlast a sustained block: the block tightens the more it is
     * hit, so hammering it keeps it alive rather than clearing it. Getting past
     * a real block is AviationReportService's job — it stops asking for a while
     * and falls through to the next source.
     *
     * @param  array<string, string>  $query
     */
    protected function get(array $query, ?int $timeout = null): string
    {
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $response = Http::timeout($timeout ?? $this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'es-AR,es;q=0.9',
                    'Referer' => "{$this->baseUrl}/mensajes/index.php?operacion=seleccion&observacion={$this->observation()}",
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

        throw new RuntimeException(sprintf(
            'No se pudo obtener el %s del SMN tras %d intentos (HTTP %d).',
            strtoupper($this->observation()),
            $this->attempts,
            $lastStatus,
        ));
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
     * aerodrome name and one result row per report.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseResultTables(string $html): array
    {
        $crawler = new Crawler($html);
        $reports = [];

        $crawler->filter('tr.headerResult')->each(function (Crawler $header) use (&$reports) {
            $airportName = $this->airportName($header->text());

            // Reports are the sibling rows of the header, inside the same
            // table — walking up to the table keeps two stations in one
            // response from bleeding into each other.
            $header->closest('table')?->filter('tr.result')->each(
                function (Crawler $row) use (&$reports, $airportName) {
                    $cells = $row->filter('td');

                    if ($cells->count() < 2) {
                        return;
                    }

                    $raw = $this->cleanText($cells->eq(1)->text());

                    if ($raw === '') {
                        return;
                    }

                    $reports[] = [
                        'station' => $this->stationFrom($raw),
                        'airport_name' => $airportName,
                        'issued_at' => $this->cleanText($cells->eq(0)->text()),
                        'raw' => $raw,
                    ];
                }
            );
        });

        return $reports;
    }

    /**
     * The header cell reads "Aeropuerto EZEIZA" on the METAR screen and
     * "Estacion EZEIZA" on the TAF one; both labels are dropped so the two
     * screens yield the same aerodrome name.
     */
    protected function airportName(string $text): string
    {
        $text = $this->cleanText($text);

        return trim(preg_replace('/^(?:Aeropuerto|Estaci[oó]n)\s+/iu', '', $text) ?? $text);
    }

    protected function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
