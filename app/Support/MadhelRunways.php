<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * MADHEL's runway list, which is prose with a designator at the front of some
 * of the lines:
 *
 *   "05/23 700x15 M - Tierra."
 *   "01 R/19 L 1377x23 M - CONC - AUW 13t/1 20t/2."
 *   "09/27 600x30 M - Tierra (CLSD)."
 *   "Franja de RWY 02/20 Sector W de 75 M de ancho."
 *   "Extremo RWY 34 343252,36S 0590451,26W - ELEV 23 M AMSL (75 FT)."
 *
 * Only the entries that *begin* with a designator pair are runways. The rest
 * are notes about them — a strip width, a threshold coordinate, a slope — and
 * they mention "RWY" in the middle precisely because they are talking about a
 * runway rather than being one. Anchoring at the start is what tells the two
 * apart without a list of exceptions.
 *
 * Closed runways are marked, never dropped. That an aerodrome has a runway it
 * cannot use is operational information; a pilot who sees three runways in the
 * chart and two in the answer has been told something false.
 */
final class MadhelRunways
{
    /**
     * The space in "01 R/19 L" is not a typo we can normalise away upstream —
     * it is how MADHEL writes Mariano Moreno, and it is the only entry in the
     * whole registry that would otherwise be missed.
     */
    private const PATTERN = '/^\s*(\d{1,2})\s*([LCR])?\s*\/\s*(\d{1,2})\s*([LCR])?\b/';

    /**
     * What follows the designator on the same line, when anything does:
     * "1871x30 M - ASPH", "- 822x26 M - Tierra", "1.591x30 M - ASPH".
     *
     * Every part is optional because plenty of entries stop at the designator,
     * and a line that only names its runway is still a runway. The thousands
     * separator is a dot ("1.591x30") in the handful of entries long enough to
     * need one, and the dash before the surface is `-` or an en dash `–`
     * depending on who typed it.
     */
    private const DIMENSIONS = '/^\s*[-–—]?\s*(\d{1,2}[.,]?\d{3}|\d{2,4})\s*[xX]\s*(\d{1,3})\s*M\b\s*[-–—]?\s*(.*)$/u';

    /**
     * MADHEL's surface vocabulary, mapped to the words the ficha prints.
     *
     * "Tierra" and "ASPH" account for all but a handful of the registry; the
     * rest are here because they are what the same field says at the
     * aerodromes whose entry has been rewritten since. Anything not on the
     * list is passed through exactly as published rather than discarded — an
     * unfamiliar surface is still a surface, and a pilot who reads a word we
     * did not recognise has lost nothing.
     *
     * @var array<string, string>
     */
    private const SURFACES = [
        'ASPH' => 'asfalto',
        'ASP' => 'asfalto',
        'ASFALTO' => 'asfalto',
        'CONC' => 'hormigón',
        'CON' => 'hormigón',
        'HORMIGON' => 'hormigón',
        'TIERRA' => 'tierra',
        'PASTO' => 'pasto',
        'CESPED' => 'pasto',
        'RIPIO' => 'ripio',
        'GRAVA' => 'ripio',
        'ARENA' => 'arena',
    ];

    /**
     * The two ends of every runway MADHEL lists for one aerodrome.
     *
     * The dimensions and the surface ride on *both* ends, because they are
     * properties of the strip rather than of either end of it, and the table
     * they land in is one row per end.
     *
     * @param  array<int, mixed>  $entries  The data.rwy array from a MADHEL record.
     * @return array<int, array{designator: string, is_closed: bool, length_m: int|null, width_m: int|null, surface: string|null, is_lighted: bool|null}>
     */
    public static function parse(array $entries): array
    {
        $ends = [];

        foreach ($entries as $entry) {
            if (! is_string($entry) || preg_match(self::PATTERN, $entry, $m) !== 1) {
                continue;
            }

            $closed = preg_match('/\bCLSD\b/i', $entry) === 1;
            $strip = self::strip((string) preg_replace(self::PATTERN, '', $entry, 1));

            // Group 2 is always present — empty when that end has no L/C/R,
            // because a later group did match. Group 4 is the last thing in the
            // pattern, so when it does not participate it is absent outright.
            foreach ([[$m[1], $m[2]], [$m[3], $m[4] ?? '']] as [$number, $suffix]) {
                $designator = self::designator((int) $number, $suffix);

                if ($designator !== null) {
                    $ends[] = ['designator' => $designator, 'is_closed' => $closed] + $strip;
                }
            }
        }

        return $ends;
    }

    /**
     * The strip itself, read off whatever the line says after the designator.
     *
     * @return array{length_m: int|null, width_m: int|null, surface: string|null, is_lighted: bool|null}
     */
    private static function strip(string $tail): array
    {
        if (preg_match(self::DIMENSIONS, $tail, $m) !== 1) {
            return ['length_m' => null, 'width_m' => null, 'surface' => null, 'is_lighted' => self::lighted($tail)];
        }

        return [
            'length_m' => (int) str_replace([',', '.'], '', $m[1]),
            'width_m' => (int) $m[2],
            'surface' => self::surface($m[3]),
            'is_lighted' => self::lighted($tail),
        ];
    }

    /**
     * The first word after the dimensions, normalised.
     *
     * Only the first: what follows it is bearing strength ("PCN 28/F/A/X/T",
     * "AUW 13t/1 16t/2"), a threshold coordinate or a note in Spanish, none of
     * which is a surface. Stopping at the first token is what keeps the ficha
     * from printing a pavement classification number as though it were one.
     */
    private static function surface(string $rest): ?string
    {
        if (preg_match('/^([\p{L}]+)/u', trim($rest), $m) !== 1) {
            return null;
        }

        $word = $m[1];
        $key = strtoupper(Str::ascii($word));

        return self::SURFACES[$key] ?? mb_strtolower($word);
    }

    /**
     * Lighting, when the line volunteers it — "Balizada", or the "ILE"
     * (iluminación) MADHEL appends to the paved entries.
     *
     * Null and not false when it says nothing: most entries do not mention
     * lighting at all, and "no balizada" is a claim about a night landing that
     * silence does not support.
     */
    private static function lighted(string $entry): ?bool
    {
        return preg_match('/\bBALIZAD[AO]\b|\bILE\b/iu', $entry) === 1 ? true : null;
    }

    private static function designator(int $number, string $suffix): ?string
    {
        return $number >= 1 && $number <= 36
            ? sprintf('%02d', $number).strtoupper($suffix)
            : null;
    }
}
