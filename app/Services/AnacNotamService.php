<?php

namespace App\Services;

use App\DataObjects\Notam;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

class AnacNotamService
{
    protected string $baseUrl;

    protected int $indicatorsTtl;

    protected int $notamsTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.anac.base_url'), '/');
        $this->indicatorsTtl = (int) config('services.anac.indicators_ttl');
        $this->notamsTtl = (int) config('services.anac.notams_ttl');
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
     * Fetch and parse the active NOTAMs for a given ANAC place indicator.
     *
     * Returns raw NOTAMs — the Spanish decoding is applied separately by
     * NotamEnricher, so that a broken AI provider can never take down the
     * safety-critical data this method produces.
     *
     * Cached briefly: NOTAMs change on the order of hours, so re-scraping ANAC
     * on every single request buys nothing and just makes us a bad citizen
     * against a government service.
     *
     * @return array<int, Notam>
     */
    public function getNotams(string $indicator): array
    {
        // Plain arrays go into the cache, not Notam objects: a cached
        // serialized object would break on the next deploy that changes the
        // class shape. Hydration happens on the way out instead.
        $rows = Cache::remember(
            "anac_notams:{$indicator}",
            $this->notamsTtl,
            fn () => $this->fetchNotams($indicator),
        );

        return array_map(Notam::fromArray(...), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchNotams(string $indicator): array
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
