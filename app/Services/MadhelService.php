<?php

namespace App\Services;

use App\Support\MadhelIdentifier;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MADHEL — ANAC's Manual de Aeródromos y Helipuertos — is the official registry
 * of every Argentine aerodrome, published as a plain JSON list.
 *
 * It is the answer to the problem the airports table was created for: ANAC's
 * NOTAM selector only shows places with an active NOTAM right now, so it can
 * never tell us that a quiet aerodrome exists. MADHEL always can.
 */
class MadhelService
{
    protected string $baseUrl;

    /**
     * A truncated response must never be mistaken for aerodromes disappearing.
     * MADHEL has held ~712 entries for years; anything far below that is a
     * broken response, not a registry that shrank.
     */
    protected int $minimumCount;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.madhel.base_url'), '/');
        $this->minimumCount = (int) config('services.madhel.minimum_count');
    }

    /**
     * The whole registry, ready to be written to the airports table.
     *
     * @return array<int, array{anac_code: string, icao_code: string|null, name: string, kind: string, access: string|null, is_controlled: bool, is_closed: bool}>
     */
    public function airports(): array
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get("{$this->baseUrl}/madhel/api/v2/airports/");

        if ($response->failed()) {
            throw new RuntimeException("No se pudo obtener el registro MADHEL de ANAC (HTTP {$response->status()}).");
        }

        $results = $response->json('results');

        if (! is_array($results) || count($results) < $this->minimumCount) {
            throw new RuntimeException(sprintf(
                'MADHEL devolvió %d aeródromos, menos que el mínimo plausible de %d.',
                is_array($results) ? count($results) : 0,
                $this->minimumCount,
            ));
        }

        $airports = [];

        foreach ($results as $row) {
            $code = strtoupper(trim((string) ($row['local_identifier'] ?? '')));
            $label = (string) ($row['human_readable_identifier'] ?? '');

            if ($code === '' || $label === '') {
                continue;
            }

            $airports[] = ['anac_code' => $code] + MadhelIdentifier::parse($label);
        }

        usort($airports, fn (array $a, array $b) => $a['anac_code'] <=> $b['anac_code']);

        return $airports;
    }
}
