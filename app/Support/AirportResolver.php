<?php

namespace App\Support;

use App\Models\Airport;
use Illuminate\Support\Str;

/**
 * The single place that turns whatever a caller supplies — an ANAC code, an
 * OACI/ICAO code, or free text from a WhatsApp message — into an ANAC place
 * indicator.
 *
 * Previously the API resolved ICAO codes one way (AnacNotamService) and the
 * bot re-implemented the same walk another way (WhatsappBotService), which
 * meant the two channels could disagree about what "SAEZ" means.
 */
class AirportResolver
{
    /**
     * Known aerodromes keyed by ANAC code, e.g. ['EZE' => 'EZEIZA/...'].
     *
     * @return array<string, string>
     */
    public function known(): array
    {
        return Airport::query()->pluck('name', 'anac_code')->all();
    }

    /**
     * Resolve a user-supplied code to ANAC's own indicator: passes through
     * unchanged if it's already an ANAC code (or unrecognized), translates it
     * if it's a 4-letter OACI/ICAO code we have a mapping for ("SAEZ" -> "EZE").
     */
    public function resolve(string $code): string
    {
        $code = strtoupper(trim($code));

        return Airport::query()->where('icao_code', $code)->value('anac_code') ?? $code;
    }

    public function exists(string $anacCode): bool
    {
        return Airport::query()->where('anac_code', $anacCode)->exists();
    }

    public function nameFor(string $anacCode): ?string
    {
        return Airport::query()->where('anac_code', $anacCode)->value('name');
    }

    /**
     * The OACI/ICAO code for an ANAC indicator — the inverse of resolve().
     *
     * Null for the aerodromes ANAC lists without one: they can still be looked
     * up for NOTAMs, but the SMN indexes METARs by ICAO code only, so there is
     * no observation to fetch for them.
     */
    public function icaoFor(string $anacCode): ?string
    {
        return Airport::query()->where('anac_code', $anacCode)->value('icao_code');
    }

    /**
     * Best-effort airport match from free text, using only cheap deterministic
     * rules — no AI. Returns null when nothing matches confidently, leaving
     * the caller free to escalate to a model.
     *
     * Only real aerodromes are considered: FIR-wide advisory pseudo-codes
     * ("---", "-EF") carry city names too, and matching "cordoba" against the
     * region-wide bulletin instead of Córdoba's airport would be wrong.
     */
    public function matchFromText(string $message): ?string
    {
        $aerodromes = Airport::query()->realAerodromes()->get();

        // Direct ANAC code mention, e.g. "notams EZE" or "eze".
        foreach ($aerodromes as $airport) {
            if ($this->mentions($message, $airport->anac_code)) {
                return $airport->anac_code;
            }
        }

        // Direct OACI/ICAO code mention, e.g. "notams SAEZ" or "saez".
        foreach ($aerodromes as $airport) {
            if ($airport->icao_code !== null && $this->mentions($message, $airport->icao_code)) {
                return $airport->anac_code;
            }
        }

        // Airport/city name mention, e.g. "ezeiza", "bariloche", "aeroparque".
        // A name can be shared by several aerodromes — Córdoba alone has three
        // — and picking whichever the database returned first would hand a
        // pilot a military airfield when they meant the international airport.
        // Ambiguity is reported as "no match" so the caller can escalate to the
        // AI matcher or simply ask, rather than guess.
        $candidates = $this->candidatesFromText($message);

        return count($candidates) === 1 ? array_key_first($candidates) : null;
    }

    /**
     * Every aerodrome whose name matches the given free text, keyed by ANAC
     * code. Used to tell "nothing matched" apart from "several matched", and
     * to offer the user a choice in the latter case.
     *
     * @return array<string, string>
     */
    public function candidatesFromText(string $message): array
    {
        $normalized = $this->normalize($message);
        $candidates = [];

        foreach (Airport::query()->realAerodromes()->get() as $airport) {
            foreach (preg_split('/[\s\/]+/', $airport->name) as $word) {
                $word = $this->normalize($word);

                // Short words ("DEL", "SAN") match far too much to be trusted.
                if (mb_strlen($word) >= 4 && str_contains($normalized, $word)) {
                    $candidates[$airport->anac_code] = $airport->name;

                    break;
                }
            }
        }

        return $candidates;
    }

    protected function mentions(string $message, string $code): bool
    {
        return preg_match('/\b'.preg_quote($code, '/').'\b/i', $message) === 1;
    }

    protected function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower($text));
    }
}
