<?php

namespace App\Support;

/**
 * The five fields this reads out of an AIP "Datos del AD" PDF start with the
 * ones MADHEL leaves blank for the aerodromes it delegates to the AIP: fuel,
 * telephone and operating hours. The last two are new, and MADHEL never
 * published either for anyone: the tower/approach frequency, and the radio
 * navigation aids.
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
     * @return array{fuel: string|null, telephone: array<int, string>|null, service_schedule: string|null, ats_frequency: string|null, navaids: array<int, array{type: string, id: string|null, frequency: string, unit: string, hours: string|null}>|null}
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
            'navaids' => self::navaids($flat),
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
     * Everything AD 2.19 lists in its "Tipo de ayuda" column, longest first so
     * that DVOR is never read as a stray D followed by a VOR.
     */
    private const NAVAID_TYPES = ['TACAN', 'GBAS', 'DVOR', 'NDB', 'DME', 'ILS', 'LOC', 'VOR', 'VDF', 'GP'];

    /**
     * AD 2.19, "Radioayudas para la navegación y el aterrizaje" — the VOR and
     * its DME, the NDB, the ILS localiser, with the identifier and frequency a
     * pilot sets the box to.
     *
     * The seven-column table has no column rules left once the PDF is
     * flattened, so the row boundaries have to be inferred from the text
     * itself. The unit after a three-digit number is the only anchor that
     * holds: the Observaciones column is free prose written in the same
     * alphabet as the aid types, and every looser pattern reads "DME CH 72X
     * (350 km)" as an aid of its own.
     *
     * The glide path is the one row of the table left out — see below.
     *
     * Columns 5, 6 and 7 (antenna position, DME elevation, remarks) are read
     * past rather than captured. They are what a chart is for; this is a
     * WhatsApp message that has to fit alongside runways, services and the
     * sun.
     *
     * @return array<int, array{type: string, id: string|null, frequency: string, unit: string, hours: string|null}>|null
     */
    private static function navaids(string $flat): ?array
    {
        // Same shape as atsFrequency()'s: the section title, the column
        // headings skipped by anchoring on the row of column numbers, then
        // the table up to the next section.
        //
        // That next section is whichever comes after 2.19 rather than 2.20
        // itself — Viedma's ficha goes straight from 2.19 to 2.23 — but only
        // from 2.20 up, never a bare "AD 2.x", because the running footer
        // prints the current page's own section number ("SAVV AD 2.9") in the
        // middle of the table it is a footer of.
        if (preg_match(
            '/RADIOAYUDAS PARA LA NAVEGACI[ÓO]N.*?\b1 2 3 4 5 6 7\s+(.*?)\s+\bAD 2\.[23]\d\b/u',
            $flat,
            $m,
        ) !== 1) {
            return null;
        }

        $type = '(?:'.implode('|', self::NAVAID_TYPES).')';

        // The separator accepts both a slash and a space: the same aid is
        // "VOR/DME" on Santa Rosa's and Bariloche's PDF and "VOR DME" on
        // Ezeiza's. Identifier and hours are optional because the glide path
        // rows carry neither. The unit's "z" is optional because Esquel's
        // ficha drops it — "VOR/DME ESQ 117.8 MH H24" — on both its rows.
        if (preg_match_all(
            '/\b(?<type>'.$type.'(?:\s?\/\s?'.$type.'|\s'.$type.')*)'
            .'(?:\s+(?<id>[A-Z]{1,4}))?'
            .'\s+(?<frequency>\d{3}(?:\.\d{1,3})?)\s*(?<unit>[Mk]Hz?)'
            .'(?:\s+(?<hours>H24|HJ|HN|HO|HS|HX))?/iu',
            $m[1],
            $rows,
            PREG_SET_ORDER,
        ) === false) {
            return null;
        }

        $navaids = [];

        foreach ($rows as $row) {
            $components = preg_split('/[\s\/]+/u', mb_strtoupper($row['type'])) ?: [];

            // The glide path is the other half of the ILS whose localiser is
            // already a row above, on a frequency no receiver is ever tuned
            // to by hand — it comes paired with the localiser. Listing it
            // would spend a line of a message that is already close to
            // WhatsApp's interactive cap on something nobody reads.
            if (in_array('GP', $components, true)) {
                continue;
            }

            // An unmatched group in the middle of the pattern comes back as an
            // empty string, unlike the trailing one below, which is absent.
            $id = $row['id'] === '' ? null : mb_strtoupper($row['id']);

            // Bariloche's VOR row repeats "VOR BAR 117.4 MHZ OPR RESTRICTED
            // BTN RDL 020-060…" inside its own Observaciones column, which
            // reads exactly like a second row of the table. Same aid on the
            // same frequency is listed once.
            $key = ($id ?? mb_strtoupper($row['type'])).' '.$row['frequency'];

            $navaids[$key] ??= [
                // "VOR DME" and "VOR/DME" are the same aid written two ways,
                // and the ficha should not print the difference.
                'type' => implode('/', $components),
                'id' => $id,
                'frequency' => $row['frequency'],
                'unit' => strncasecmp($row['unit'], 'k', 1) === 0 ? 'kHz' : 'MHz',
                'hours' => ($row['hours'] ?? '') === '' ? null : mb_strtoupper($row['hours']),
            ];
        }

        return $navaids === [] ? null : array_values($navaids);
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
