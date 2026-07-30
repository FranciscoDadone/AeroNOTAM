<?php

namespace App\Support;

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
     * The two ends of every runway MADHEL lists for one aerodrome.
     *
     * @param  array<int, mixed>  $entries  The data.rwy array from a MADHEL record.
     * @return array<int, array{designator: string, is_closed: bool}>
     */
    public static function parse(array $entries): array
    {
        $ends = [];

        foreach ($entries as $entry) {
            if (! is_string($entry) || preg_match(self::PATTERN, $entry, $m) !== 1) {
                continue;
            }

            $closed = preg_match('/\bCLSD\b/i', $entry) === 1;

            // Group 2 is always present — empty when that end has no L/C/R,
            // because a later group did match. Group 4 is the last thing in the
            // pattern, so when it does not participate it is absent outright.
            foreach ([[$m[1], $m[2]], [$m[3], $m[4] ?? '']] as [$number, $suffix]) {
                $designator = self::designator((int) $number, $suffix);

                if ($designator !== null) {
                    $ends[] = ['designator' => $designator, 'is_closed' => $closed];
                }
            }
        }

        return $ends;
    }

    private static function designator(int $number, string $suffix): ?string
    {
        return $number >= 1 && $number <= 36
            ? sprintf('%02d', $number).strtoupper($suffix)
            : null;
    }
}
