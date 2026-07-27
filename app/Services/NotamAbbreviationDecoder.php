<?php

namespace App\Services;

class NotamAbbreviationDecoder
{
    /**
     * Lookup tables live in resources/data/notam-abbreviations.php — roughly
     * 1,500 entries that are data, not logic, and would otherwise bury the
     * code that uses them.
     *
     * @var array<string, array<string, string>>|null
     */
    protected static ?array $tables = null;

    /**
     * @return array<string, string>
     */
    protected static function table(string $name): array
    {
        self::$tables ??= require resource_path('data/notam-abbreviations.php');

        return self::$tables[$name];
    }

    public function decode(?string $text): ?string
    {
        return $this->decodeWith($text, self::table('en'), self::table('phrases_en'));
    }

    /**
     * Look up the known Spanish meaning of every ICAO/FAA contraction that
     * appears in the given text. Used to hand the AI decoder a ground-truth
     * glossary instead of relying on the model to recall (and consistently
     * apply) obscure abbreviations like AMDT, AIP, or OBST on its own.
     *
     * @return array<string, string>
     */
    public function glossaryFor(string $text): array
    {
        preg_match_all('/[A-Za-zÁÉÍÓÚáéíóúÑñ][A-Za-zÁÉÍÓÚáéíóúÑñ0-9\/]*/u', $text, $matches);

        $es = self::table('es');
        $glossary = [];

        foreach (array_unique($matches[0]) as $token) {
            $key = strtoupper($token);

            if (isset($es[$key])) {
                $glossary[$key] = $es[$key];
            }
        }

        ksort($glossary);

        return $glossary;
    }

    public function decodeEs(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Phrases must run before gender agreement: they match on the original
        // English, and the agreement pass rewrites "THE" into a Spanish
        // article, which would stop "IN THE VICINITY OF" from ever matching.
        $text = $this->replacePhrases($text, self::table('phrases_es'));
        $text = $this->applyGenderAgreement($text);

        // ES first: an ICAO contraction always wins over a same-spelled
        // ordinary English word (e.g. "AD" is "aeródromo", not "anuncio").
        // Phrases are already applied, hence the empty phrase list here.
        $decoded = $this->decodeWith($text, self::table('es') + self::table('common_en_es'), []);

        return $decoded === null ? null : $this->contractArticles($decoded);
    }

    /**
     * Spanish contracts "de el" into "del" and "a el" into "al". Word-by-word
     * expansion can't produce those on its own, and leaving them uncontracted
     * is the single most obvious tell that a sentence was machine-assembled.
     */
    protected function contractArticles(string $text): string
    {
        return preg_replace(
            ['/\bde el\b/iu', '/\ba el\b/iu'],
            ['del', 'al'],
            $text,
        ) ?? $text;
    }

    /**
     * Spanish adjectives need to agree in gender with their subject (e.g.
     * "pista cerrada" vs "aeródromo cerrado"). The static dictionary can't
     * know that ahead of time, so when the NOTAM is about a feminine subject
     * (pista/calle de rodaje/plataforma/aeronave/posición) this swaps the
     * masculine default for the feminine form.
     *
     * @return array<string, string>
     */
    /**
     * Ordinary English words — not ICAO contractions — that NOTAM prose mixes
     * in between the abbreviations. The ES dictionary above only knows how to
     * expand contractions, so without this layer the offline decode comes out
     * as Spanglish ("not disponible", "of umbral pista 05").
     *
     * Deliberately kept to function words and high-frequency NOTAM vocabulary:
     * this is a degraded fallback for when the AI decoder is unavailable, not
     * a general English-to-Spanish translator, and rare prose is expected to
     * come through untranslated. Consumers can tell this mode apart via the
     * `decoded_by` field NotamEnricher sets.
     *
     * The English article "the" is intentionally absent — Spanish gender would
     * be wrong about half the time ("el pista"), and the idiomatic phrases in
     * PHRASES_ES already cover the cases where it matters.
     *
     * @var array<string, string>
     */
    protected const COMMON_EN_ES = [
        'AND' => 'y',
        'APRON' => 'plataforma',
        'AREA' => 'área',
        'AT' => 'en',
        'AVAILABLE' => 'disponible',
        'BETWEEN' => 'entre',
        'BIRD' => 'ave',
        'BIRDS' => 'aves',
        'BY' => 'por',
        'CLOSED' => 'cerrado',
        'CONSTRUCTION' => 'construcción',
        'CRANE' => 'grúa',
        'DAILY' => 'diariamente',
        'DURING' => 'durante',
        'EQUIPMENT' => 'equipamiento',
        'ERECTED' => 'erigida',
        'EXCEPT' => 'excepto',
        'FOR' => 'para',
        'FROM' => 'desde',
        'FUEL' => 'combustible',
        'FURTHER' => 'nuevo',
        'IN' => 'en',
        'LASER' => 'láser',
        'MAINTENANCE' => 'mantenimiento',
        'NEAR' => 'cerca de',
        'NOT' => 'no',
        'NOTICE' => 'aviso',
        'OF' => 'de',
        'ON' => 'en',
        'OPEN' => 'abierto',
        'OR' => 'o',
        'OUT' => 'fuera',
        'PERMANENT' => 'permanente',
        'PERSONNEL' => 'personal',
        'PLACE' => 'lugar',
        'PROGRESS' => 'progreso',
        'SERVICE' => 'servicio',
        'SHOW' => 'espectáculo',
        'TEMPORARY' => 'temporal',
        'TO' => 'a',
        'TON' => 'toneladas',
        'UNTIL' => 'hasta',
        'VEHICLE' => 'vehículo',
        'VEHICLES' => 'vehículos',
        'VICINITY' => 'proximidades',
        'WARNING' => 'advertencia',
        'WITH' => 'con',
        'WORK' => 'trabajo',
    ];

    /**
     * Subjects a gendered adjective can attach to, and their grammatical
     * gender in Spanish. Used to inflect CLSD/LTD correctly.
     *
     * @var array<string, string>
     */
    protected const SUBJECT_GENDER = [
        'RWY' => 'f',   // pista
        'TWY' => 'f',   // calle de rodaje
        'APN' => 'f',   // plataforma
        'ACFT' => 'f',  // aeronave
        'PSN' => 'f',   // posición
        'AD' => 'm',    // aeródromo
        'AP' => 'm',    // aeropuerto
        'APRON' => 'f', // plataforma
    ];

    /**
     * @var array<string, array<string, string>>
     */
    protected const GENDERED_ADJECTIVES = [
        'CLSD' => ['m' => 'cerrado', 'f' => 'cerrada'],
        'LTD' => ['m' => 'limitado', 'f' => 'limitada'],
    ];

    /**
     * Inflect gendered adjectives to agree with the subject they actually
     * modify — the nearest one to their left — rather than with whatever
     * subject happens to appear anywhere in the NOTAM. "AD CLSD EXC FOR ACFT"
     * is about an aeródromo and must read "cerrado", even though a feminine
     * "ACFT" appears later in the same sentence.
     */
    protected function applyGenderAgreement(string $text): string
    {
        $adjectives = implode('|', array_keys(self::GENDERED_ADJECTIVES));
        $subjects = implode('|', array_keys(self::SUBJECT_GENDER));

        $text = preg_replace_callback(
            '/\b('.$subjects.')\b(.*?)\b('.$adjectives.')\b/is',
            function (array $m): string {
                $gender = self::SUBJECT_GENDER[strtoupper($m[1])];
                $adjective = self::GENDERED_ADJECTIVES[strtoupper($m[3])][$gender];

                return $m[1].$m[2].$adjective;
            },
            $text,
        ) ?? $text;

        // "THE" is only safe to translate once we can see what noun it
        // introduces — "the RWY" is "la pista" but "the AD" is "el aeródromo".
        // Anything else keeps the masculine default, which covers the most
        // frequent NOTAM subjects (aeródromo, aeropuerto, obstáculo, servicio).
        return preg_replace_callback(
            '/\bTHE\s+(\S+)/i',
            function (array $m): string {
                $gender = self::SUBJECT_GENDER[strtoupper(trim($m[1], ',.;:()'))] ?? 'm';

                return ($gender === 'f' ? 'la ' : 'el ').$m[1];
            },
            $text,
        ) ?? $text;
    }

    /**
     * @param  array<string, string>  $dictionary
     * @param  array<string, string>  $phrases
     */
    protected function decodeWith(?string $text, array $dictionary, array $phrases): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $text = $this->normalizeTimeRanges($text);
        $text = $this->replacePhrases($text, $phrases);

        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $decoded = array_map(function (string $token) use ($dictionary) {
            if (trim($token) === '') {
                return $token;
            }

            $core = trim($token, ',.;:()');
            $prefixLength = strpos($token, $core);
            $prefix = $prefixLength ? substr($token, 0, $prefixLength) : '';
            $suffix = substr($token, $prefixLength + strlen($core));

            $expansion = $dictionary[strtoupper($core)] ?? $this->passThrough($core);

            return $prefix.$expansion.$suffix;
        }, $tokens);

        $result = implode('', $decoded);
        $result = preg_replace('/\s+/', ' ', $result) ?? $result;
        $result = trim($result);

        return $result === '' ? null : mb_strtoupper(mb_substr($result, 0, 1)).mb_substr($result, 1);
    }

    /**
     * Converts standalone "HHMM-HHMM" ranges (the only time format ANAC's
     * NOTAM text uses) into "HH:MM-HH:MM UTC" — NOTAM times are always UTC,
     * so the suffix is added explicitly rather than left implicit. Deliberately
     * does not touch lone 4-digit numbers, since those are just as likely to
     * be a year or a NOTAM series number as a clock time.
     */
    protected function normalizeTimeRanges(string $text): string
    {
        $pattern = '/\b([01]\d|2[0-3])([0-5]\d)-([01]\d|2[0-3])([0-5]\d)\b/';

        return preg_replace($pattern, '$1:$2-$3:$4 UTC', $text) ?? $text;
    }

    /**
     * How to render a token we have no expansion for. Unknown prose is
     * lowercased so it reads as part of a sentence, but anything that looks
     * like an identifier keeps its original form: "TWY A" must not become
     * "calle de rodaje a", and runway/taxiway/stand designators are exactly
     * the part of a NOTAM a pilot needs to read back unambiguously.
     */
    protected function passThrough(string $token): string
    {
        $isShortDesignator = mb_strlen($token) <= 2 && ctype_alpha($token);
        $hasDigits = preg_match('/\d/', $token) === 1;

        return ($isShortDesignator || $hasDigits) ? $token : mb_strtolower($token);
    }

    /**
     * @param  array<string, string>  $phrases
     */
    protected function replacePhrases(string $text, array $phrases): string
    {
        foreach ($phrases as $phrase => $expansion) {
            $text = preg_replace('/\b'.preg_quote($phrase, '/').'\b/i', $expansion, $text) ?? $text;
        }

        return $text;
    }
}
