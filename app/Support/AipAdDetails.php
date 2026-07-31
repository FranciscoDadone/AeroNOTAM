<?php

namespace App\Support;

/**
 * The four fields this reads out of an AIP "Datos del AD" PDF are exactly the
 * ones MADHEL leaves blank for the aerodromes it delegates to the AIP: fuel,
 * telephone, operating hours and — new, not something MADHEL ever published
 * for anyone — the tower/approach frequency.
 *
 * The AD-2 form is an ICAO template: the row label text ("Tipos de
 * combustible, lubricantes / Fuel and oil types") is the same string on every
 * aerodrome's PDF, in the same position relative to its neighbours. That is
 * what this parses against, rather than the row numbers, which repeat within
 * a page and are useless as anchors on their own.
 *
 * Mirrors MadhelDetails' rule: a field that cannot be found, or whose value is
 * the AIP's own way of saying nothing ("NIL", a bare "No"), comes back null —
 * never a guess dressed up as data.
 */
final class AipAdDetails
{
    /**
     * @return array{fuel: string|null, telephone: array<int, string>|null, service_schedule: string|null, ats_frequency: string|null}
     */
    public static function parse(string $text): array
    {
        // The PDF wraps a row's value across several physical lines; folding
        // everything to single spaces turns each row back into one string a
        // "from this label to that label" capture can work against.
        $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return [
            'fuel' => self::fuel($flat),
            'telephone' => self::telephone($flat),
            'service_schedule' => self::serviceSchedule($flat),
            'ats_frequency' => self::atsFrequency($flat),
        ];
    }

    /**
     * AD 2.4, row 2: "Tipos de combustible, lubricantes / Fuel and oil types".
     */
    private static function fuel(string $flat): ?string
    {
        if (preg_match(
            '/Fuel and oil types\s+(.*?)\s+(?:\d+\s+)?Instalaciones y capacidad de abastecimiento de combustible/iu',
            $flat,
            $m,
        ) !== 1) {
            return null;
        }

        return self::published($m[1]);
    }

    /**
     * AD 2.2, row 6: "Explotador, teléfono, FAX, AFS, correo electrónico y
     * sitio web / AD operator, Telephone, FAX, AFS, e-mail and website".
     *
     * The block repeats itself once in Spanish and once in English, same
     * numbers both times, so the phone tokens are pulled out and deduplicated
     * rather than the block being read as prose.
     *
     * @return array<int, string>|null
     */
    private static function telephone(string $flat): ?array
    {
        if (preg_match(
            '/AD operator, Telephone, FAX, AFS, e-mail and website\s+(.*?)\s+Prestador de los Servicios de Navegación Aérea/iu',
            $flat,
            $m,
        ) !== 1) {
            return null;
        }

        if (preg_match_all('/\(\+?54[^)]*\)\s*[\d]{4,}(?:[\s\-]\d{2,})*/u', $m[1], $phones) === false) {
            return null;
        }

        $numbers = [];

        foreach ($phones[0] as $phone) {
            $phone = trim(preg_replace('/\s+/u', ' ', $phone) ?? $phone);
            $key = preg_replace('/\D/', '', $phone);

            // Same number in the Spanish paragraph and its English
            // translation collapses to one key and is kept once.
            $numbers[$key] ??= $phone;
        }

        return $numbers === [] ? null : array_values($numbers);
    }

    /**
     * AD 2.3, row 1: "Explotador del AD / AD Operator" — the hours the
     * aerodrome itself is open, as opposed to customs, fuelling or ATS, which
     * the ficha has no room for individually.
     *
     * Only the Spanish half is kept, matching how MADHEL's own
     * service_schedule is stored: free prose, printed verbatim, one language.
     */
    private static function serviceSchedule(string $flat): ?string
    {
        if (preg_match('/AD Operator\s+(.*?)\s+(?:\d+\s+)?Aduanas \/ Customs/iu', $flat, $m) !== 1) {
            return null;
        }

        // Spanish, then " /" (a space before it is the only reliable tell,
        // since the sentence itself is free to contain slashes) and the
        // English translation of the same hours.
        $spanish = preg_split('/\s\/\s*/u', $m[1], 2)[0];

        return self::published($spanish);
    }

    /**
     * The other service designations AD 2.18 uses. Needed only to tell where
     * the first row's own text ends: the table has no column rules once the
     * PDF is flattened to plain text, so the next one of these is the only
     * marker that a second row (APP after TWR, ATIS, ground, clearance
     * delivery...) has started.
     */
    private const ATS_DESIGNATIONS = [
        'TWR', 'APP', 'GND', 'ATIS', 'CLRD', 'SMC', 'DEL', 'ARO', 'FIS', 'RDO', 'AFIS', 'TMA',
    ];

    /**
     * AD 2.18, first ATS service row — normally TWR or TWR/APP, the one a
     * pilot actually calls. Busier aerodromes list several (GND, ATIS, a
     * separate APP); reading only the first is the trade this makes for
     * staying a single free-text line rather than the table MADHEL never had
     * for anyone to begin with — and it is why the other rows' frequencies
     * must not leak in under the first row's call sign, which would be worse
     * than not showing them.
     *
     * EMERG (121.5 MHz) is the universal distress frequency, not something
     * particular to this aerodrome, so it is left out even from the first row.
     */
    private static function atsFrequency(string $flat): ?string
    {
        if (preg_match(
            '/ATS COMMUNICATION FACILITIES.*?1\s+2\s+3\s+4\s+5\s+6\s+([A-Z]{2,6}(?:\/[A-Z]{2,6})*\s+.*?)\s+AD 2\.19/u',
            $flat,
            $m,
        ) !== 1) {
            return null;
        }

        $block = $m[1];
        $designation = explode(' ', $block, 2)[0];

        // Some aerodromes give the first row a combined designation,
        // "TWR/APP" or even "TMA/APP/TWR" — one radio doing several jobs,
        // still a single row as far as parsing the table goes.
        if (array_intersect(explode('/', $designation), self::ATS_DESIGNATIONS) === []) {
            return null;
        }

        $row = self::firstAtsRow($block, $designation);

        if (preg_match_all('/([A-Z]{3,6})\s+(\d{3}\.\d{2})\s*MHz/u', $row, $channels, PREG_SET_ORDER) === false) {
            return null;
        }

        $frequencies = [];

        foreach ($channels as $channel) {
            if ($channel[1] === 'EMERG') {
                continue;
            }

            $frequencies[] = "{$channel[2]} MHz ({$channel[1]})";
        }

        if ($frequencies === []) {
            return null;
        }

        $callsign = self::atsCallsign($row, $designation);

        return trim("{$designation} {$callsign} — ".implode(' · ', $frequencies));
    }

    /**
     * The block's text up to (but not including) whichever other
     * designation — if any — comes next. A single-service aerodrome has none,
     * and the whole block is "the first row".
     */
    private static function firstAtsRow(string $block, string $designation): string
    {
        $others = array_diff(self::ATS_DESIGNATIONS, explode('/', $designation));
        $earliest = null;

        foreach ($others as $other) {
            if (preg_match('/(?<=\s)'.preg_quote($other, '/').'(?=\s)/u', $block, $next, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $next[0][1];
                $earliest = $earliest === null ? $offset : min($earliest, $offset);
            }
        }

        return $earliest === null ? $block : substr($block, 0, $earliest);
    }

    /**
     * The call sign printed after the designation — Spanish only, the AIP
     * repeats it in English straight after a "/" with no space
     * ("SANTA ROSA TORRE/SANTA ROSA TOWER") or with one, depending on the
     * aerodrome.
     */
    private static function atsCallsign(string $row, string $designation): string
    {
        $rest = trim(substr($row, strlen($designation)));
        $rest = explode('/', $rest, 2)[0];

        // Stop at the first channel code or frequency, whichever text
        // immediately follows the call sign in that aerodrome's PDF.
        $rest = preg_split('/\s+(?:CPPL|CAUX|EMERG|\d{3}\.\d{2}\s*MHz)/u', $rest, 2)[0];

        return trim($rest);
    }

    /**
     * A published value, or null for every way the AIP has of saying there
     * is none — a bare "NIL" or "No", the same words that mean "not
     * applicable" throughout the AD-2 form.
     */
    private static function published(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' || strcasecmp($trimmed, 'NIL') === 0 || strcasecmp($trimmed, 'No') === 0
            ? null
            : $trimmed;
    }
}
