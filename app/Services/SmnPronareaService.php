<?php

namespace App\Services;

use App\DataObjects\PronareaForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Reads PRONAREA — the SMN's area forecast, issued per FIR rather than per
 * aerodrome — off the same legacy "mensajes" application METAR and TAF use
 * (see SmnReportSource). It is not built on that class: unlike METAR/TAF,
 * which take an ICAO code straight in the query, PRONAREA is queried by an
 * opaque numeric id — the SMN's internal id for the one station that issues
 * each FIR's bulletin (see STATION_IDS) — and a bulletin is one long
 * multi-section text rather than a row per report, so the query shape and the
 * parsing both differ enough that inheriting would mean overriding most of it
 * anyway.
 *
 * Verified against a live browser session (Cloudflare blocks every automated
 * fetch, including from here, so this could not be confirmed any other way):
 * the "seleccion" screen's FIR dropdown posts to an intermediate per-station
 * picker, which — because each FIR has exactly one issuing station — auto-
 * submits itself with that station's id as `{id}=on`. The result screen
 * shares the same `tr.headerResult` / `tr.result` markup as METAR/TAF.
 *
 * There is no second source for PRONAREA the way NOAA relays METAR/TAF over
 * OPMET — it is an SMN-only aeronautical product, and the SMN's own page
 * warns it 502s under load. So instead of failing over to another publisher
 * when it cannot be reached, this keeps the last bulletin fetched
 * successfully and serves that — marked stale — rather than leaving the
 * question unanswered. RefreshPronareaCache keeps that cache warm from the
 * scheduler so a WhatsApp reply is never the first thing to try the SMN.
 */
class SmnPronareaService
{
    /**
     * The SMN's internal id for the one station that issues each FIR's
     * PRONAREA — not documented anywhere public, read off the "mensajes"
     * app's own station-picker screen for observacion=pronarea.
     *
     * @var array<string, string>
     */
    public const STATION_IDS = [
        'EZE' => '87582',
        'CBA' => '87344',
        'DOZ' => '87418',
        'SIS' => '87155',
        'CRV' => '87860',
    ];

    protected string $baseUrl;

    protected int $attempts;

    protected int $ttl;

    protected int $staleTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.smn.base_url'), '/');
        $this->attempts = (int) config('services.smn.attempts');
        $this->ttl = (int) config('services.pronarea.ttl');
        $this->staleTtl = (int) config('services.pronarea.stale_ttl');
    }

    /**
     * The current PRONAREA for a FIR, or the last one fetched successfully —
     * marked $stale — if the SMN cannot be reached right now.
     *
     * @throws RuntimeException when the SMN cannot be reached and nothing was
     *                           ever fetched successfully for this FIR.
     */
    public function forFir(string $fir): PronareaForecast
    {
        $fir = strtoupper(trim($fir));

        $cached = Cache::get($this->freshKey($fir));

        if ($cached !== null) {
            return $this->hydrate($fir, $cached, stale: false);
        }

        try {
            $raw = $this->fetchRaw($fir);
        } catch (Throwable $e) {
            $lastGood = Cache::get($this->lastGoodKey($fir));

            if ($lastGood !== null) {
                report($e);

                return $this->hydrate($fir, $lastGood, stale: true);
            }

            throw $e;
        }

        $entry = [
            'raw' => $raw,
            'fetched_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        Cache::put($this->freshKey($fir), $entry, $this->ttl);
        Cache::put($this->lastGoodKey($fir), $entry, $this->staleTtl);

        return $this->hydrate($fir, $entry, stale: false);
    }

    /**
     * @param  array{raw: string, fetched_at: string}  $entry
     */
    protected function hydrate(string $fir, array $entry, bool $stale): PronareaForecast
    {
        return new PronareaForecast(
            fir: $fir,
            raw: $entry['raw'],
            fetchedAt: CarbonImmutable::parse($entry['fetched_at']),
            stale: $stale,
        );
    }

    protected function freshKey(string $fir): string
    {
        return "pronarea:{$fir}";
    }

    protected function lastGoodKey(string $fir): string
    {
        return "pronarea:last_good:{$fir}";
    }

    /**
     * Fetch and parse one FIR's bulletin. Retry/Cloudflare handling mirrors
     * SmnReportSource::get() — see that class for why it only covers the
     * isolated challenge and not a sustained block. The SMN's own PRONAREA
     * page separately warns that it 502s under load, which the caller — the
     * scheduler-driven RefreshPronareaCache, not a live WhatsApp reply — is
     * meant to absorb by simply trying again on its next run.
     *
     * @throws RuntimeException also when $fir names none of STATION_IDS —
     *                           a programming error, since every caller goes
     *                           through PronareaFirResolver first.
     */
    protected function fetchRaw(string $fir): string
    {
        $stationId = self::STATION_IDS[$fir] ?? null;

        if ($stationId === null) {
            throw new RuntimeException("No conozco la estación que emite el PRONAREA de la FIR {$fir}.");
        }

        $lastStatus = 0;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'es-AR,es;q=0.9',
                    'Referer' => "{$this->baseUrl}/mensajes/index.php?operacion=seleccion&observacion=pronarea",
                ])
                ->get("{$this->baseUrl}/mensajes/index.php", [
                    'observacion' => 'pronarea',
                    'operacion' => 'consultar',
                    'volver2' => 'consultar',
                    $stationId => 'on',
                ]);

            $lastStatus = $response->status();
            $body = $response->body();

            if ($response->successful() && ! $this->isChallenge($body)) {
                $raw = $this->parseRaw($body);

                if ($raw !== null) {
                    return $raw;
                }

                throw new RuntimeException("El SMN no publicó un PRONAREA para la FIR {$fir}.");
            }

            if ($attempt < $this->attempts) {
                usleep(500_000 * $attempt);
            }
        }

        throw new RuntimeException(sprintf(
            'No se pudo obtener el PRONAREA del SMN tras %d intentos (HTTP %d).',
            $this->attempts,
            $lastStatus,
        ));
    }

    protected function isChallenge(string $body): bool
    {
        return str_contains($body, '_cf_chl_opt')
            || str_contains($body, '<title>Just a moment...</title>');
    }

    /**
     * Pull the bulletin's text out of the result screen.
     *
     * Shares the "mensajes" app's usual result table — one tr.headerResult
     * per station, one tr.result row per report inside the same table (see
     * SmnReportSource::parseResultTables()) — confirmed against a live
     * response. Only the first result row is used: a FIR query answers with
     * its one current bulletin, not a history of past ones.
     */
    protected function parseRaw(string $html): ?string
    {
        $crawler = new Crawler($html);
        $raw = null;

        $crawler->filter('tr.result')->first()->each(function (Crawler $row) use (&$raw) {
            $cells = $row->filter('td');

            if ($cells->count() < 2) {
                return;
            }

            $text = trim(preg_replace('/\s+/', ' ', $cells->eq(1)->text()) ?? '');

            if ($text !== '') {
                $raw = $text;
            }
        });

        return $raw;
    }
}
