<?php

namespace App\Support;

/**
 * Degrees to the sixteen-point compass, in Spanish (which writes O for oeste,
 * not W).
 *
 * Lives here rather than inside the METAR decoder because two unrelated things
 * now need it: explaining a wind group in prose, and naming the direction a
 * runway-wind answer is computed against. The table itself stays in
 * resources/data/metar-abbreviations.php, next to the rest of the vocabulary.
 */
final class Compass
{
    /** @var array<int, string>|null */
    private static ?array $points = null;

    public static function name(int $degrees): string
    {
        self::$points ??= (require resource_path('data/metar-abbreviations.php'))['compass'];

        return self::$points[(int) round(((($degrees % 360) + 360) % 360) / 22.5) % 16];
    }
}
