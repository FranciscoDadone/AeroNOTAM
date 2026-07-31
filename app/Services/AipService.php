<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The AIP itself — what MADHEL points to for the aerodromes it delegates
 * rather than publishing fuel, telephone and hours on its own record.
 *
 * Unlike MADHEL there is no per-aerodrome API: the AIP's "Ad" tab is one
 * listing of every AD document it publishes, and the aerodrome-specific PDF
 * has to be picked out of it by ICAO code. The listing has to be re-read on
 * every import rather than cached by URL, because the download link embeds a
 * hash that changes with each AIRAC amendment.
 */
class AipService
{
    protected string $baseUrl;

    /**
     * Below this the listing is treated as truncated and the import is
     * abandoned, same reasoning as MadhelService's minimum_count.
     */
    protected int $minimumAdDocuments;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.aip.base_url'), '/');
        $this->minimumAdDocuments = (int) config('services.aip.minimum_ad_documents');
    }

    /**
     * Every "Datos del AD" document currently published, keyed by ICAO code.
     *
     * The listing route only answers a request that looks like the SPA's own
     * XHR — a plain GET 404s — which is why the header is not optional the
     * way MADHEL's User-Agent is.
     *
     * @return array<string, string> ICAO code => absolute download URL.
     */
    public function adDocuments(): array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get("{$this->baseUrl}/aip/ad");

        if ($response->failed()) {
            throw new RuntimeException("No se pudo obtener el listado de la AIP (HTTP {$response->status()}).");
        }

        $documents = self::parseListing($response->body());

        // The AIP has published an AD-2.0 for at least 51 aerodromes at the
        // time this was written. Anything far below that is a truncated
        // response, not the AIP having stopped publishing most of its charts.
        if (count($documents) < $this->minimumAdDocuments) {
            throw new RuntimeException(sprintf(
                'La AIP devolvió %d documentos AD-2.0, menos de lo plausible.',
                count($documents),
            ));
        }

        return $documents;
    }

    /**
     * The raw bytes of one "Datos del AD" PDF.
     */
    public function download(string $url): string
    {
        $response = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url);

        if ($response->failed()) {
            throw new RuntimeException("No se pudo descargar el PDF de la AIP (HTTP {$response->status()}): {$url}");
        }

        return $response->body();
    }

    /**
     * Picks the "AD-2.0 ... Datos del AD" row out of the AIP's full document
     * table — hundreds of rows per aerodrome for charts this import has no
     * use for (aerodrome plots, approach charts) — by the one label MADHEL
     * itself points readers at.
     *
     * @return array<string, string>
     */
    protected static function parseListing(string $html): array
    {
        $pattern = '/<td class="col-xs-9">([A-Z]{4})-AD-2\.0&nbsp;[^<]*<\/td>\s*'
            .'<td[^>]*>\s*<span[^>]*>\s*<a href="([^"]+)"/s';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $documents = [];

        foreach ($matches as $match) {
            $documents[$match[1]] = str_starts_with($match[2], 'http')
                ? $match[2]
                : rtrim(config('services.aip.base_url'), '/').$match[2];
        }

        return $documents;
    }
}
