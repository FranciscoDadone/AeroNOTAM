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
    /**
     * The same sixteen points written out, plus the English spellings MADHEL
     * mixes into its own field — it publishes NNO for most aerodromes and NNW
     * for a handful, depending on who typed the entry.
     *
     * Reading an abbreviation back into words is the other direction from
     * name(), and it exists because a compass point is how MADHEL says where an
     * aerodrome sits relative to its town. "4,5 km al nor-noreste de Santa
     * Rosa" is a line somebody reads once, not a heading to compute against.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'N' => 'norte',
        'NNE' => 'nor-noreste',
        'NE' => 'noreste',
        'ENE' => 'este-noreste',
        'E' => 'este',
        'ESE' => 'este-sudeste',
        'SE' => 'sudeste',
        'SSE' => 'sud-sudeste',
        'S' => 'sur',
        'SSO' => 'sud-sudoeste',
        'SSW' => 'sud-sudoeste',
        'SO' => 'sudoeste',
        'SW' => 'sudoeste',
        'OSO' => 'oeste-sudoeste',
        'WSW' => 'oeste-sudoeste',
        'O' => 'oeste',
        'W' => 'oeste',
        'ONO' => 'oeste-noroeste',
        'WNW' => 'oeste-noroeste',
        'NO' => 'noroeste',
        'NW' => 'noroeste',
        'NNO' => 'nor-noroeste',
        'NNW' => 'nor-noroeste',
    ];

    /** @var array<int, string>|null */
    private static ?array $points = null;

    public static function name(int $degrees): string
    {
        self::$points ??= (require resource_path('data/metar-abbreviations.php'))['compass'];

        return self::$points[(int) round(((($degrees % 360) + 360) % 360) / 22.5) % 16];
    }

    /**
     * A compass abbreviation spelled out, or null when it is not one.
     *
     * Null matters: the same MADHEL field also carries the literal "Lindando"
     * for the thirty-one aerodromes that sit against the town they serve, and
     * forcing that through a compass table would either invent a direction or
     * throw away what it actually says.
     */
    public static function describe(string $point): ?string
    {
        return self::NAMES[strtoupper(trim($point))] ?? null;
    }
}
