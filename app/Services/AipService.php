<?php

namespace App\Services;

use App\DataObjects\AipDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The AIP itself — what MADHEL points to for the aerodromes it delegates
 * rather than publishing fuel, telephone and hours on its own record, and
 * where every chart an aerodrome has is published.
 *
 * Unlike MADHEL there is no per-aerodrome API: the AIP's "Ad" tab is one
 * listing of every AD document it publishes, and an aerodrome's documents have
 * to be picked out of it by ICAO code. The listing has to be re-read on every
 * import rather than cached by URL, because the download link embeds a hash
 * that changes with each AIRAC amendment.
 */
class AipService
{
    protected string $baseUrl;

    /**
     * Below this the listing is treated as truncated and the import is
     * abandoned, same reasoning as MadhelService's minimum_count.
     */
    protected int $minimumAdDocuments;

    /**
     * The parsed listing, kept for the life of this instance.
     *
     * One reply can ask for it twice — the charts to send, then the list of
     * everything else to offer underneath — and it is one response either way.
     *
     * @var array<string, array<int, AipDocument>>|null
     */
    protected ?array $listing = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.aip.base_url'), '/');
        $this->minimumAdDocuments = (int) config('services.aip.minimum_ad_documents');
    }

    /**
     * Every "Datos del AD" document currently published, keyed by ICAO code.
     *
     * The one row notams:import-aip-details reads, out of the dozens each
     * aerodrome has. Its shape is what that import expects and is deliberately
     * unchanged — a bare URL per aerodrome — even though the listing behind it
     * now carries everything else too.
     *
     * @return array<string, string> ICAO code => absolute download URL.
     */
    public function adDocuments(): array
    {
        $documents = [];

        foreach ($this->listing() as $icaoCode => $rows) {
            foreach ($rows as $row) {
                if ($row->code === 'AD-2.0') {
                    $documents[$icaoCode] = $row->url;

                    break;
                }
            }
        }

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
     * Every AD-2 document the AIP publishes for one aerodrome, in the order the
     * listing gives them.
     *
     * That order is the aerodrome's own AD-2 numbering, so it is stable enough
     * to point at a document by position — which is what a tapped list row
     * does, since the download URL is too long-lived-looking to put on a button
     * and changes with every amendment anyway.
     *
     * An aerodrome the AIP publishes nothing for comes back empty rather than
     * failing: most of the registry is not delegated to the AIP at all.
     *
     * @return array<int, AipDocument>
     */
    public function documentsFor(string $icaoCode): array
    {
        return $this->listing()[strtoupper(trim($icaoCode))] ?? [];
    }

    /**
     * Whether the AIP publishes anything at all for an aerodrome.
     *
     * Having an ICAO code is not enough: the AIP only carries the aerodromes
     * ANAC delegates to it, and Junín (SAAJ) has a code and no documents. The
     * question is asked before offering the charts, so that the offer is only
     * made where there is something behind it.
     */
    public function hasDocuments(string $icaoCode): bool
    {
        return $this->documentsFor($icaoCode) !== [];
    }

    /**
     * The raw bytes of one AIP PDF.
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
     * The whole "Ad" listing, parsed and grouped by aerodrome.
     *
     * The listing route only answers a request that looks like the SPA's own
     * XHR — a plain GET 404s — which is why the header is not optional the way
     * MADHEL's User-Agent is.
     *
     * @return array<string, array<int, AipDocument>>
     */
    protected function listing(): array
    {
        if ($this->listing !== null) {
            return $this->listing;
        }

        // Cached across replies, not only within one. Every answer about an
        // aerodrome now asks whether the AIP has anything for it, so without
        // this the listing would be fetched from ANAC once per message. The
        // window is kept short because a download URL embeds a hash that
        // changes with each AIRAC amendment, and a stale one is a link that
        // WhatsApp will fail to fetch.
        //
        // Plain arrays into the cache, hydrated on the way out, same as
        // AnacNotamService: the cache refuses to unserialize objects
        // (cache.serializable_classes), so an AipDocument put in here comes
        // back as __PHP_Incomplete_Class.
        $rows = Cache::remember(
            'aip:ad-listing',
            (int) config('services.aip.listing_ttl'),
            function (): array {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->get("{$this->baseUrl}/aip/ad");

                if ($response->failed()) {
                    throw new RuntimeException("No se pudo obtener el listado de la AIP (HTTP {$response->status()}).");
                }

                return $this->parseListing($response->body());
            },
        );

        return $this->listing = array_map(
            fn (array $documents) => array_map(AipDocument::fromArray(...), $documents),
            $rows,
        );
    }

    /**
     * Every "{OACI}-AD-2.x" row of the AIP's document table, grouped by
     * aerodrome.
     *
     * The table is hundreds of rows: for each aerodrome the "Datos del AD"
     * record, the aerodrome plot, and one chart per instrument procedure it
     * publishes. Only the AD-2 sections are read — AD-1 is the country-wide
     * preamble and carries no aerodrome code to group it under.
     *
     * Titles come out of the AIP HTML-encoded ("Aer&oacute;dromos"), and are
     * decoded here rather than at each place that shows or matches one: a
     * half-decoded title is the kind of thing that reads fine in a list and
     * silently fails a keyword match.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function parseListing(string $html): array
    {
        $pattern = '/<td class="col-xs-9">([A-Z]{4})-(AD-2[^&<]*)&nbsp;([^<]*)<\/td>\s*'
            .'<td[^>]*>\s*<span[^>]*>\s*<a href="([^"]+)"/s';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $documents = [];

        foreach ($matches as $match) {
            $documents[$match[1]][] = [
                'icao_code' => $match[1],
                'code' => trim($match[2]),
                'title' => trim(html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5)),
                'url' => str_starts_with($match[4], 'http') ? $match[4] : $this->baseUrl.$match[4],
            ];
        }

        return $documents;
    }
}
