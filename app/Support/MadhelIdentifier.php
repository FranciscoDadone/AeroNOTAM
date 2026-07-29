<?php

namespace App\Support;

/**
 * MADHEL publishes each aerodrome as a single display string that packs the
 * name, both codes and the classification together:
 *
 *   EZEIZA / MINISTRO PISTARINI - (EZE / SAEZ) - DRCE - PÚBLICO CONTROLADO INTERNACIONAL
 *   CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO - (ELP) - DRCE - PÚBLICO NO CONTROLADO
 *
 * Taking it apart lives here, in one place, because both the live importer and
 * the committed snapshot it generates read the same field: two parsers would
 * eventually disagree about what an aerodrome is called.
 */
final class MadhelIdentifier
{
    /**
     * The local code is always three letters; the OACI/ICAO code is present for
     * only 153 of the 712 entries. The dash before the parenthesis is `-`, `–`
     * or absent entirely, which is why it is optional here.
     */
    private const PATTERN = '/^(?P<name>.*?)\s*[-–—]?\s*\(\s*(?P<local>[A-Z]{3})\s*(?:\/\s*(?P<icao>[A-Z]{4})\s*)?\)\s*[-–—]?\s*(?P<rest>.*)$/u';

    /**
     * Where the name ends in the one entry that doesn't parenthesise its code.
     */
    private const FALLBACK_TAIL = '/\s*[-–—]\s*(FIR|MILITAR|PRIVADO|PÚBLICO|PUBLICO|DR[A-Z]{2})\b.*$/u';

    /**
     * @return array{name: string, icao_code: string|null, kind: string, access: string|null, is_controlled: bool, is_closed: bool}
     */
    public static function parse(string $humanReadable): array
    {
        // A handful of MADHEL entries carry a stray BOM or zero-width space in
        // the middle of the label, which would otherwise end up in the name.
        $humanReadable = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}]/u', '', $humanReadable) ?? $humanReadable;
        $humanReadable = trim(preg_replace('/\s+/u', ' ', $humanReadable) ?? $humanReadable);

        if (preg_match(self::PATTERN, $humanReadable, $m) === 1) {
            $name = trim($m['name']);
            // An optional group that didn't participate comes back as '',
            // because a later group ("rest") always does.
            $icao = $m['icao'] !== '' ? $m['icao'] : null;
            $rest = $m['rest'];
        } else {
            // "HELIPUERTO EZEIZA / IFE - INSTITUTO DE FORMACIÓN EZEIZA - FIR EZE
            // - MILITAR NO CONTROLADO (...)": the code isn't parenthesised, so
            // cut at the classification instead and read the flags off the
            // whole string. One bad row must not fail an import of 712.
            $name = trim(preg_replace(self::FALLBACK_TAIL, '', $humanReadable) ?? $humanReadable);
            $icao = null;
            $rest = $humanReadable;
        }

        return [
            'name' => $name !== '' ? $name : $humanReadable,
            'icao_code' => $icao,
            'kind' => self::kind($name, $rest),
            'access' => self::access($rest),
            // "CONTROLADO" not preceded by "NO ".
            'is_controlled' => preg_match('/(?<!NO )CONTROLADO/u', $rest) === 1,
            'is_closed' => (bool) preg_match('/CERRADO|CLSD/u', $rest),
        ];
    }

    /**
     * MADHEL has no type field on the list endpoint, so this reads the label.
     * The code prefix looks tempting — every heliport's code starts with H —
     * but so do plain aerodromes like HUI (Huinca Renancó) and HDS (Henderson),
     * so the words are the only signal that doesn't invent heliports.
     */
    private static function kind(string $name, string $rest): string
    {
        return preg_match('/HELIPUERTO|HELIPLATAFORMA/u', $name) === 1
            || preg_match('/\bHLP\b|\(ELEVADO\)/u', $rest) === 1
                ? 'HLP'
                : 'AD';
    }

    private static function access(string $rest): ?string
    {
        return match (true) {
            (bool) preg_match('/PÚBLICO|PUBLICO/u', $rest) => 'publico',
            (bool) preg_match('/PRIVADO/u', $rest) => 'privado',
            (bool) preg_match('/MILITAR/u', $rest) => 'militar',
            default => null,
        };
    }
}
