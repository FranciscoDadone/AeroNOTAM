<?php

namespace App\Services;

use App\Contracts\AviationReportSource;
use App\Support\AerometStationResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads SYNOP surface observations from OGIMET, a public aggregator of the
 * WMO's Global Telecommunication System — AEROMET's only source.
 *
 * The SMN published the same underlying SYNOP reports, decoded into its own
 * compact AEROMET line, and was tried here first for a while — but confirmed
 * live, ssl.smn.gob.ar blocks every automated client regardless of which
 * network it runs from, including a real, non-headless, automated browser
 * from a production server's own network, so a source that can never answer
 * was only adding a wasted round of retries (and, worse, the odd multi-
 * minute timeout) ahead of the one that does. This is that one.
 *
 * getsynop?block=87 asks for every Argentine WMO block-87 station's SYNOP at
 * once — the same "whole country, one request" idea the SMN's own "Todo
 * Argentina" sector attempted and could not sustain, except here it actually
 * works: confirmed live, well under a second, no challenge. So this ignores
 * AerometStationResolver::FIR_GROUPS' station lists for the request itself —
 * one request answers for every group — and only uses them to decide which
 * of that one response's rows to hand back for a given group index,
 * honouring the same $icaoCode-as-group-index contract AerometService
 * expects. A row's raw text is genuine SYNOP, which is why AerometEnricher
 * reaches for SynopDecoder rather than a simpler AEROMET-line grammar.
 */
class OgimetAerometSource implements AviationReportSource
{
    protected string $baseUrl;

    protected int $timeout;

    protected int $lookbackHours;

    public function __construct(protected AerometStationResolver $stations)
    {
        $this->baseUrl = rtrim(config('services.ogimet.base_url'), '/');
        $this->timeout = (int) config('services.ogimet.timeout');
        $this->lookbackHours = (int) config('services.ogimet.lookback_hours');
    }

    public function name(): string
    {
        return 'ogimet';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $groups = AerometStationResolver::FIR_GROUPS;
        $index = (int) $icaoCode;

        if (! isset($groups[$index])) {
            return [];
        }

        $wanted = array_flip($groups[$index]);
        $rows = [];

        foreach ($this->latestByStation() as $wmoCode => $row) {
            if (isset($wanted[$wmoCode])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function latestByStation(): array
    {
        $end = now('UTC');
        $begin = $end->copy()->subHours($this->lookbackHours);

        $response = Http::timeout($this->timeout)->get("{$this->baseUrl}/cgi-bin/getsynop", [
            'block' => '87',
            'begin' => $begin->format('YmdHi'),
            'end' => $end->format('YmdHi'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Error al consultar OGIMET (HTTP {$response->status()}).");
        }

        $latest = [];

        foreach (explode("\n", trim($response->body())) as $line) {
            $row = $this->parseLine($line);

            if ($row === null) {
                continue;
            }

            // Lines come oldest first per station — a later one for the same
            // station simply overwrites, leaving whichever is most recent.
            $latest[$row['wmo_code']] = $row;
        }

        return $latest;
    }

    /**
     * One line: "{wmo_code},{year},{month},{day},{hour},{minute},{raw SYNOP}"
     * — confirmed live against getsynop's own output. A station with nothing
     * to report for that slot still gets a line, but its SYNOP body reads
     * "AAXX ddhhi wwnnn NIL=", worth dropping here rather than passing an
     * empty observation on.
     *
     * @return array{wmo_code: string, station: string, airport_name: string, issued_at: string, raw: string}|null
     */
    protected function parseLine(string $line): ?array
    {
        $fields = explode(',', trim($line), 7);

        if (count($fields) < 7) {
            return null;
        }

        [$wmoCode, , , $day, $hour, $minute, $raw] = $fields;
        $raw = trim($raw);

        if ($raw === '' || str_contains($raw, 'NIL')) {
            return null;
        }

        return [
            'wmo_code' => $wmoCode,
            'station' => '',
            'airport_name' => $this->stations->nameFor($wmoCode),
            'issued_at' => sprintf('%02d - %02d:%02d', (int) $day, (int) $hour, (int) $minute),
            'raw' => $raw,
        ];
    }
}
