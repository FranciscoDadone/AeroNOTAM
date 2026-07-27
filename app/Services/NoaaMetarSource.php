<?php

namespace App\Services;

use App\Contracts\MetarSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the same observations from NOAA's Aviation Weather Center.
 *
 * This is not a second opinion on the weather. Aerodrome METARs are exchanged
 * internationally over the WMO's OPMET circuits, so the report NOAA serves for
 * SAEZ is the one the SMN issued, relayed verbatim — the standard exists
 * precisely so that a report means the same thing wherever it is read.
 *
 * It exists here purely as a way around the bot challenge in front of the SMN,
 * which blocks us outright for stretches at a time. The one difference worth
 * knowing about is that national remark groups (the SMN's "RMK PP000") are not
 * always carried across the exchange, so a relayed report can be slightly
 * shorter than the original.
 */
class NoaaMetarSource implements MetarSource
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
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $icaoCode): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get("{$this->baseUrl}/api/data/metar", [
                'ids' => strtoupper(trim($icaoCode)),
                'format' => 'json',
                'hours' => (int) config('services.noaa.hours'),
            ]);

        // A station with nothing to report answers 204 No Content, which is an
        // answer rather than a failure.
        if ($response->status() === 204) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException("Error al consultar METAR en NOAA (HTTP {$response->status()}).");
        }

        return array_values(array_filter(array_map(
            $this->toRow(...),
            $response->json() ?? [],
        )));
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return array<string, mixed>|null
     */
    protected function toRow(array $observation): ?array
    {
        $raw = trim((string) ($observation['rawOb'] ?? ''));

        if ($raw === '') {
            return null;
        }

        return [
            'station' => (string) ($observation['icaoId'] ?? ''),
            'airport_name' => (string) ($observation['name'] ?? ''),
            'observed_at' => $this->observedAt($raw),
            'raw' => $raw,
        ];
    }

    /**
     * The observation time in the "DD - HH:MM" shape the SMN prints, taken from
     * the report's own day/time group rather than from NOAA's metadata — that
     * way the timestamp shown always agrees with the report text beside it, and
     * both sources render identically.
     */
    protected function observedAt(string $raw): string
    {
        return preg_match('/\b(\d{2})(\d{2})(\d{2})Z\b/', $raw, $m) === 1
            ? "{$m[1]} - {$m[2]}:{$m[3]}"
            : '';
    }
}
