<?php

namespace App\Support;

use App\Models\Airport;
use Illuminate\Support\Collection;
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
     * ANAC codes that are also ordinary Spanish words. With the full MADHEL
     * registry loaded these are unavoidable — VER, CON and DAR are real
     * aerodromes — and a bare word-boundary match would read "quiero ver los
     * notams de eze" as a request for VER.
     *
     * A mention of one of these only counts as a code when it is written in
     * capitals, the way someone typing an indicator actually writes it. In
     * lower case the name path still gets there: "salta" reaches SAL,
     * "bariloche" reaches BAR.
     *
     * The one exception is a message that is nothing but the code — see
     * bareCode(), which runs before any of this.
     *
     * @var array<int, string>
     */
    protected const AMBIGUOUS_CODES = [
        'BAR', 'CAL', 'CON', 'DAR', 'DIA', 'DIO', 'GAS', 'HAS', 'IBA', 'LIO',
        'OLA', 'OSA', 'PAN', 'PAR', 'SAL', 'SAN', 'TIO', 'TOS', 'VAN', 'VER', 'VEZ',
    ];

    /**
     * The words a message can carry alongside a code without stopping it from
     * being "just a code" — see bareCode(), the only thing that reads them.
     *
     * Only the words that name a topic, plus the two that glue them to the
     * code. Articles are deliberately absent: with "el" on the list, "el gas"
     * would resolve to GAS. So are the interrogatives, for the same reason —
     * "que" would put "que ver" one word away from resolving to VER.
     *
     * The runway-wind vocabulary is on it because that topic asks about a
     * place like every other one: "viento en pista osa" is the same question
     * as "metar osa", and without these words it was not read as one — worse,
     * "pista" would then match CORONEL SUÁREZ / LA PISTA by name and answer
     * for somewhere else entirely.
     *
     * @var array<int, string>
     */
    protected const QUESTION_WORDS = [
        'info', 'informacion', 'datos', 'ficha', 'aerodromo', 'aeropuerto',
        'notam', 'notams', 'metar', 'speci', 'taf', 'pronarea', 'aeromet',
        'crepusculo', 'viento', 'pista', 'cruzado', 'crosswind', 'componente',
        'cabecera', 'de', 'del', 'en',
    ];

    /**
     * Aerodromes to offer an AI matcher, keyed by ANAC code.
     *
     * Scoped to the ones a person might name in free text: the full registry is
     * 712 rows, and pasting every private agricultural strip into a prompt
     * would cost ~12k tokens a message while making the model's job harder.
     *
     * @return array<string, string>
     */
    public function known(): array
    {
        return Airport::query()->publiclyKnown()->pluck('name', 'anac_code')->all();
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

    /**
     * The whole row, for the one answer that is about the aerodrome itself
     * rather than about something happening at it.
     *
     * Every other caller wants one field and asks for it by name — nameFor(),
     * icaoFor(), isClosed() — because that is all a NOTAM or a METAR reply
     * needs to know. The ficha needs a dozen of them at once, and twelve more
     * single-column lookups would be twelve more queries to say the same thing.
     */
    public function find(string $anacCode): ?Airport
    {
        return Airport::query()->where('anac_code', strtoupper(trim($anacCode)))->first();
    }

    public function nameFor(string $anacCode): ?string
    {
        return Airport::query()->where('anac_code', $anacCode)->value('name');
    }

    /**
     * Whether MADHEL publishes this aerodrome as closed (CLSD).
     *
     * Worth saying out loud even when there is nothing else to report: "no hay
     * NOTAM activos" reads as "todo en orden", and for a closed aerodrome that
     * is exactly the wrong thing to understand.
     */
    public function isClosed(string $anacCode): bool
    {
        return (bool) Airport::query()->where('anac_code', $anacCode)->value('is_closed');
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
        $bare = $this->bareCode($message);

        if ($bare !== null) {
            return $bare;
        }

        $aerodromes = Airport::query()->realAerodromes()->get();

        // Direct ANAC code mention, e.g. "notams EZE" or "eze".
        foreach ($aerodromes as $airport) {
            if ($this->mentionsCode($message, $airport->anac_code)) {
                return $airport->anac_code;
            }
        }

        // Direct OACI/ICAO code mention, e.g. "notams SAEZ" or "saez". Four
        // letters starting with S are not words, so no case rule is needed.
        foreach ($aerodromes as $airport) {
            if ($airport->icao_code !== null && $this->mentions($message, $airport->icao_code)) {
                return $airport->anac_code;
            }
        }

        // Airport/city name mention, e.g. "ezeiza", "bariloche", "aeroparque".
        // A name is rarely unique — "alta gracia" matches Alta Gracia's own
        // aerodrome, its heliport, Cruz Alta and Punta Alta — so the matches
        // are ranked and only a clear winner is taken. Everything else is
        // reported as "no match", leaving the caller free to escalate to the
        // AI matcher or simply ask, rather than hand a pilot the wrong place.
        $matches = $this->rankedMatches($message);

        if ($matches->isEmpty()) {
            return null;
        }

        $best = $matches->first();

        return $matches->count() === 1 || $best['rank'] > $matches[1]['rank']
            ? $best['airport']->anac_code
            : null;
    }

    /**
     * A message whose only content is a code — "osa", "sazr", "info osa",
     * "notams del aeropuerto de osa" — resolved without the capitals rule.
     *
     * AMBIGUOUS_CODES exists because "quiero ver los notams de eze" must not
     * resolve to VER, and it earns that by insisting those codes be typed in
     * capitals. But that rule costs something the day a bare code becomes an
     * ordinary thing to send: since a lone place name now answers with the
     * ficha, "osa" is exactly how somebody asks about Santa Rosa, and nobody
     * writes a three-letter message about a bear.
     *
     * So the rule is relaxed for that case — and, because "info osa" is the
     * same question with the topic said out loud, for a message that is a code
     * plus the words below and nothing else. What keeps it narrow is that the
     * list is only the words that name a topic: "la osa mayor se ve de noche"
     * still has five tokens this cannot account for, so it is left to the
     * capitals rule, which rejects it.
     */
    protected function bareCode(string $message): ?string
    {
        $tokens = preg_split('/[^a-z]+/', $this->normalize($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rest = array_values(array_diff($tokens, self::QUESTION_WORDS));

        if (count($rest) !== 1 || preg_match('/^[a-z]{3,4}$/', $rest[0]) !== 1) {
            return null;
        }

        $code = strtoupper($rest[0]);

        $anacCode = Airport::query()
            ->realAerodromes()
            ->where(fn ($query) => $query->where('anac_code', $code)->orWhere('icao_code', $code))
            ->value('anac_code');

        return $anacCode === null ? null : (string) $anacCode;
    }

    /**
     * Every aerodrome whose name matches the given free text, keyed by ANAC
     * code and led by the likeliest. Used to tell "nothing matched" apart from
     * "several matched", and to offer the user a choice in the latter case.
     *
     * @return array<string, string>
     */
    public function candidatesFromText(string $message): array
    {
        return $this->rankedMatches($message)
            ->mapWithKeys(fn (array $match) => [$match['airport']->anac_code => $match['airport']->name])
            ->all();
    }

    /**
     * The aerodromes whose name the message names, likeliest first. The first
     * element of every rank is at least 1 — that is what the filter keeps.
     *
     * @return Collection<int, array{airport: Airport, rank: array{int<1, max>, int, int, int}}>
     */
    protected function rankedMatches(string $message): Collection
    {
        $normalized = $this->normalize($message);

        return Airport::query()
            ->realAerodromes()
            ->get()
            ->map(fn (Airport $airport) => [
                'airport' => $airport,
                'rank' => $this->rank($airport, $this->wordsMatched($airport->name, $normalized)),
            ])
            ->filter(fn (array $match) => $match['rank'][0] > 0)
            ->sortByDesc('rank')
            ->values();
    }

    /**
     * How confident we are that this is the aerodrome the message meant, most
     * significant first: how much of the name was actually named, then open to
     * the public, then not closed, then towered — which is what separates
     * "tucumán" the international airport from the aeroclub across town.
     *
     * @return array{int, int, int, int}
     */
    protected function rank(Airport $airport, int $wordsMatched): array
    {
        return [
            $wordsMatched,
            $airport->access === 'publico' ? 1 : 0,
            $airport->is_closed ? 0 : 1,
            $airport->is_controlled ? 1 : 0,
        ];
    }

    /**
     * How many distinct words of an aerodrome's name the message names.
     *
     * Distinct, because "HELIPUERTO EZEIZA / IFE - INSTITUTO DE FORMACIÓN
     * EZEIZA" says Ezeiza twice and would otherwise outrank Ezeiza itself.
     */
    protected function wordsMatched(string $name, string $normalizedMessage): int
    {
        $matched = [];

        foreach (preg_split('/[\s\/]+/', $name) as $word) {
            $word = $this->normalize($word);

            // Short words ("DEL", "SAN") match far too much to be trusted. The
            // boundaries matter as much as the length: without them "parque"
            // matches "aeroparque", and Mar del Plata / Parque Hermoso turns up
            // as a candidate for Aeroparque.
            if (mb_strlen($word) >= 4 && preg_match('/\b'.preg_quote($word, '/').'\b/u', $normalizedMessage) === 1) {
                $matched[$word] = true;
            }
        }

        return count($matched);
    }

    protected function mentions(string $message, string $code): bool
    {
        // The /u matters: without it PCRE works on bytes, and the two bytes of
        // "ñ" both count as word boundaries — which made "mañana" a mention of
        // ANA (Paraná / Aeroclub).
        return preg_match('/\b'.preg_quote($code, '/').'\b/iu', $message) === 1;
    }

    /**
     * A code mention, with the case rule that keeps the codes which are also
     * Spanish words from swallowing ordinary sentences.
     */
    protected function mentionsCode(string $message, string $code): bool
    {
        return in_array($code, self::AMBIGUOUS_CODES, true)
            ? preg_match('/\b'.preg_quote($code, '/').'\b/u', $message) === 1
            : $this->mentions($message, $code);
    }

    protected function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower($text));
    }
}
