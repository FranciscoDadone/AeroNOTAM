<?php

namespace App\Services;

use App\Contracts\AviationReportSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the same reports from NOAA's Aviation Weather Center.
 *
 * This is not a second opinion on the weather. Aerodrome METARs and TAFs are
 * exchanged internationally over the WMO's OPMET circuits, so the report NOAA
 * serves for SAEZ is the one the SMN issued, relayed verbatim — the standard
 * exists precisely so that a report means the same thing wherever it is read.
 *
 * It exists here purely as a way around the bot challenge in front of the SMN,
 * which blocks us outright for stretches at a time. The one difference worth
 * knowing about is that national remark groups (the SMN's "RMK PP000") are not
 * always carried across the exchange, so a relayed report can be slightly
 * shorter than the original.
 */
abstract class NoaaReportSource implements AviationReportSource
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.noaa.base_url'), '/');
    }

    public function name(): string
    {
        return 'noaa';
    }

    /**
     * The data endpoint under /api/data: 'metar', 'taf'.
     */
    abstract protected function endpoint(): string;

    /**
     * The field carrying the report verbatim: 'rawOb' for METAR, 'rawTAF' for
     * TAF. The raw text is the only thing taken from the response — NOAA's own
     * decoded fields are ignored, since the whole point of relaying is to hand
     * on the SMN's report unaltered.
     */
    abstract protected function rawField(): string;

    /**
     * How far back to look for reports, in hours.
     */
    abstract protected function hours(): int;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get("{$this->baseUrl}/api/data/{$this->endpoint()}", [
                'ids' => strtoupper(trim($icaoCode)),
                'format' => 'json',
                'hours' => $this->hours(),
            ]);

        // A station with nothing to report answers 204 No Content, which is an
        // answer rather than a failure.
        if ($response->status() === 204) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Error al consultar %s en NOAA (HTTP %d).',
                strtoupper($this->endpoint()),
                $response->status(),
            ));
        }

        return array_values(array_filter(array_map(
            $this->toRow(...),
            $response->json() ?? [],
        )));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    protected function toRow(array $report): ?array
    {
        $raw = trim((string) ($report[$this->rawField()] ?? ''));

        if ($raw === '') {
            return null;
        }

        return [
            'station' => (string) ($report['icaoId'] ?? ''),
            'airport_name' => (string) ($report['name'] ?? ''),
            'issued_at' => $this->issuedAt($raw),
            'raw' => $raw,
        ];
    }

    /**
     * The issue time in the "DD - HH:MM" shape the SMN prints, taken from the
     * report's own day/time group rather than from NOAA's metadata — that way
     * the timestamp shown always agrees with the report text beside it, and
     * both sources render identically.
     */
    protected function issuedAt(string $raw): string
    {
        return preg_match('/\b(\d{2})(\d{2})(\d{2})Z\b/', $raw, $m) === 1
            ? "{$m[1]} - {$m[2]}:{$m[3]}"
            : '';
    }
}
