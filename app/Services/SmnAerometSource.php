<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The SMN's AEROMET screen — the same legacy "mensajes" application as
 * METAR/TAF, but indexed by WMO/OMM station code rather than ICAO: AEROMET's
 * network also covers towns with no aerodrome at all (Azul, Ceres, Chepes),
 * so an ICAO code cannot name most of it.
 *
 * The result table is the same markup METAR/TAF use, with one difference:
 * the cell also carries "Decodificado" and "Synop" links ahead of the report
 * line (each pointing at the same observation, decoded a different way),
 * which rawTextFrom() strips before the rest is treated as the raw line.
 *
 * Unlike METAR/TAF, fetch() does not take an ICAO code at all: the "code" it
 * receives is really an index into FIR_GROUPS, and it fetches every station
 * in that one group at once — the same request shape the site's own five-FIR
 * "Sector" dropdown issues, one FIR at a time. AerometService is what decides
 * which group(s) a request needs and drives the retrying; this class only
 * knows how to fetch one.
 *
 * A single request naming all 119 stations at once (the "Todo Argentina"
 * sector) was tried first and confirmed live to routinely 522 — the legacy
 * backend timing out under the load of that many stations in one go, close
 * enough to Cloudflare's own upstream timeout to lose the race more often
 * than not. Five smaller requests, one per real FIR, stay comfortably under
 * that ceiling while still being a request shape the site's own UI produces.
 */
class SmnAerometSource extends SmnReportSource
{
    /**
     * The 119 stations AEROMET publishes, split the same way the SMN's own
     * "Sector" dropdown does: FIR EZEIZA, FIR CORDOBA, FIR MENDOZA, FIR
     * RESISTENCIA, FIR C. RIVADAVIA, in that order — read off the real
     * checkbox screen for each sector rather than partitioned by hand, so
     * this always matches what "Todo Argentina" is really the union of.
     *
     * Public: AerometService reads it to work out which group a station
     * belongs to, and RefreshAerometCache to know how many groups to retry.
     *
     * @var array<int, array<int, string>>
     */
    public const FIR_GROUPS = [
        [
            '87582', '87641', '87750', '87765', '87649', '87640', '87585', '87570',
            '87761', '87719', '87395', '87683', '87637', '87648', '87571', '87470',
            '87576', '87532', '87497', '87548', '87593', '87534', '87563', '87692',
            '87572', '87573', '87574', '87715', '87550', '87643', '87374', '87544',
            '87679', '87596', '87736', '87480', '87553', '87623', '87371', '87645',
            '87540', '87688', '87468', '87616', '87663',
        ],
        [
            '87222', '87257', '87320', '87322', '87213', '87344', '87345', '87347',
            '87046', '87007', '87217', '87467', '87050', '87016', '87349', '87360',
            '87453', '87065', '87047', '87444', '87129', '87356', '87022', '87127',
            '87211', '87121', '87043', '87328', '87244',
        ],
        [
            '87454', '87305', '87506', '87418', '87420', '87403', '87412', '87311',
            '87436', '87416', '87509', '87405', '87448',
        ],
        [
            '87163', '87166', '87162', '87097', '87173', '87078', '87281', '87393',
            '87187', '87148', '87289', '87178', '87270', '87155',
        ],
        [
            '87860', '87800', '87904', '87803', '87880', '87774', '87814', '87852',
            '87896', '87823', '87925', '87934', '87784', '87909', '87912', '87828',
            '87938', '87791',
        ],
    ];

    protected int $bulkTimeout;

    public function __construct()
    {
        parent::__construct();

        $this->bulkTimeout = (int) config('services.aeromet.bulk_timeout');
    }

    protected function observation(): string
    {
        return 'aeromet';
    }

    /**
     * There is no station indicator inside the line itself the way "METAR
     * SAEZ" carries one — the line opens straight with the station's name
     * (e.g. "JUNIN 090/06KT ..."), which is already captured as airport_name
     * from the header row.
     */
    protected function stationFrom(string $raw): string
    {
        return '';
    }

    /**
     * $icaoCode is really an index into FIR_GROUPS here, not a station code —
     * AerometService is the only caller, and it always passes one (see its
     * own docblock for why fetching is organized around groups rather than
     * individual stations or all of them at once). An unknown index fetches
     * nothing, which cannot happen through AerometService, only through a
     * caller that bypasses it.
     *
     * Confirmed live: even a group's worth still occasionally leaves a
     * handful of its own stations out with "Error: El código [X] es
     * erroneo" rather than a genuine absence of data. That is not a bug to
     * work around here — AerometService treats a station missing from a
     * group's response exactly like a station nobody asked about, falling
     * back to that station's own last-good reading instead of reading
     * anything into the gap.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $codes = self::FIR_GROUPS[(int) $icaoCode] ?? [];

        $query = [
            'observacion' => $this->observation(),
            'operacion' => 'consultar',
        ];

        foreach ($codes as $code) {
            $query[$code] = 'on';
        }

        return $this->parseResultTables($this->get($query, $this->bulkTimeout));
    }

    /**
     * The cell holds "Decodificado"/"Synop" links, an <input type=hidden>
     * duplicate of the line (same convention as METAR/TAF), and — only when
     * the observation includes a weather phenomenon — a "div.tip" holding the
     * SMN's own plain-Spanish gloss of it (e.g. "Lluvia. Continua, no
     * congelandose, debil..." for "FBL RA CONS"). The report line itself is
     * whatever text is left over: a bare text node when there is no
     * phenomenon, or wrapped in its own <div> when there is — confirmed
     * against a live response for both shapes.
     */
    protected function rawTextFrom(Crawler $cell): string
    {
        $clone = $cell->getNode(0)?->cloneNode(true);

        if ($clone === null) {
            return '';
        }

        // Everything before the <br> is the "Decodificado / Synop" links and
        // the " / " between them — never part of the line — so it is dropped
        // rather than filtered node by node, which would still leave that
        // separator text behind.
        $afterBr = false;

        foreach (iterator_to_array($clone->childNodes) as $child) {
            if (! $afterBr) {
                $isBr = $child instanceof \DOMElement && $child->tagName === 'br';
                $clone->removeChild($child);

                if ($isBr) {
                    $afterBr = true;
                }

                continue;
            }

            $isTip = $child instanceof \DOMElement && str_contains($child->getAttribute('class'), 'tip');

            if (in_array($child->nodeName, ['a', 'input'], true) || $isTip) {
                $clone->removeChild($child);
            }
        }

        return $this->cleanText($clone->textContent ?? '');
    }

    /**
     * The SMN's own plain-Spanish gloss of a weather phenomenon, e.g. "Lluvia.
     * Continua, no congelandose, debil..." for a line carrying "FBL RA CONS".
     * Trusting the SMN's own text here beats guessing at a translation table
     * for every abbreviation it might use, the same way showing the raw code
     * verbatim beats reformatting it.
     *
     * @return array{phenomenon_note?: string}
     */
    protected function extraFields(Crawler $cell): array
    {
        $tip = $cell->filter('div.tip');

        return $tip->count() > 0
            ? ['phenomenon_note' => $this->cleanText($tip->first()->text())]
            : [];
    }
}
