<?php

namespace App\Support;

/**
 * The handful of numbers out of a METAR that decide whether a change is worth
 * waking someone up for.
 *
 * A METAR is reissued every hour, and almost every reissue differs from the one
 * before it — the temperature moves a degree, the wind backs two knots. A watch
 * that notified on "the text changed" would be an hourly alarm clock, which is
 * the fastest way to get itself muted. So the comparison is made on the groups a
 * pilot would actually act on, at the granularity they act on them: bands and
 * thresholds, not raw deltas.
 *
 * This deliberately does not reuse MetarDecoder. That class turns each group
 * into a sentence, which is the right output for a person and the wrong one for
 * a comparison — "viento del sudoeste a 8 nudos" cannot be subtracted from
 * anything. What is needed here is five scalars, and the regexes that produce
 * them are narrow enough that sharing machinery would cost more than it saved.
 *
 * The change lines name raw groups ("18008KT → 20018G30KT") rather than
 * paraphrasing them, because the alert carries the full report and its Spanish
 * explanation directly underneath: naming the group is what lets the reader find
 * it in the text below and check for themselves.
 */
final readonly class MetarConditions
{
    /** Wind speed change, in knots, that is worth a message on its own. */
    private const WIND_SPEED_DELTA = 10;

    /**
     * Wind direction change worth reporting — but only once the wind is strong
     * enough for direction to mean anything. Below that, direction wanders on
     * its own and a runway choice does not turn on it.
     */
    private const WIND_DIRECTION_DELTA = 30;

    private const WIND_DIRECTION_FLOOR = 10;

    /** QNH change, in hectopascals, worth resetting an altimeter for. */
    private const QNH_DELTA = 2;

    /**
     * Visibility bands, in metres. The two lowest are approach minima territory;
     * the upper ones are where a VFR flight stops being legal. Crossing one is
     * an event, moving inside one is not — which is what keeps a routine
     * 9999 → 8000 from ringing a phone.
     */
    private const VISIBILITY_BANDS = [800, 1500, 3000, 5000, 8000];

    /** Cloud base bands, in feet. Same reasoning as the visibility ones. */
    private const CEILING_BANDS = [200, 500, 1000, 1500, 3000];

    /**
     * Groups that end the current observation and begin something else: a trend
     * forecast or free-form remarks. Everything past them describes what the
     * weather is expected to do, not what it is doing, and folding that into
     * "what changed" would compare a forecast against an observation.
     */
    private const TERMINATORS = ['RMK', 'TEMPO', 'BECMG', 'NOSIG'];

    private const DESCRIPTORS = 'MI|BC|PR|DR|BL|SH|TS|FZ';

    private const PHENOMENA = 'DZ|RA|SN|SG|IC|PL|GR|GS|UP|BR|FG|FU|VA|DU|SA|HZ|PY|PO|SQ|FC|SS|DS';

    /**
     * @param  int|null  $windDirection  Degrees true, or null when absent or variable (VRB).
     * @param  int|null  $windSpeed  Knots, normalized from whatever unit the report used.
     * @param  int|null  $visibility  Metres; 9999 and CAVOK both become 10000.
     * @param  array<int, string>  $phenomena  Present-weather groups verbatim, e.g. ["-RA", "BR"].
     * @param  int|null  $ceiling  Feet above the aerodrome for the lowest BKN/OVC/VV layer; null means no ceiling.
     * @param  int|null  $qnh  Hectopascals.
     */
    public function __construct(
        public bool $isSpeci = false,
        public ?int $windDirection = null,
        public ?int $windSpeed = null,
        public ?int $windGust = null,
        public ?int $visibility = null,
        public array $phenomena = [],
        public ?int $ceiling = null,
        public ?int $qnh = null,
        public ?string $windGroup = null,
    ) {}

    public static function fromRaw(string $raw): self
    {
        $tokens = preg_split('/\s+/', strtoupper(trim($raw))) ?: [];

        $isSpeci = ($tokens[0] ?? '') === 'SPECI';

        $windDirection = null;
        $windSpeed = null;
        $windGust = null;
        $windGroup = null;
        $visibility = null;
        $phenomena = [];
        $ceiling = null;
        $qnh = null;

        foreach ($tokens as $token) {
            if (in_array($token, self::TERMINATORS, true)) {
                break;
            }

            // CAVOK is a statement about three groups at once: visibility 10 km
            // or more, no cloud below 5000 ft, no significant weather. Reading
            // it as one is the only way a CAVOK report compares correctly
            // against a spelled-out one.
            if ($token === 'CAVOK') {
                $visibility = 10000;
                $ceiling = null;

                continue;
            }

            if (preg_match('/^(\d{3}|VRB|\/{3})(\d{2,3})(?:G(\d{2,3}))?(KT|MPS|KMH)$/', $token, $m) === 1) {
                $windGroup = $token;
                $windDirection = ctype_digit($m[1]) ? (int) $m[1] % 360 : null;
                $windSpeed = self::toKnots((int) $m[2], $m[4]);
                // The gust group is optional but always present in $m, empty,
                // because a later group did match.
                $windGust = $m[3] === '' ? null : self::toKnots((int) $m[3], $m[4]);

                continue;
            }

            if (preg_match('/^(\d{4})(?:N|NE|E|SE|S|SW|W|NW)?$/', $token, $m) === 1) {
                // 9999 is the code for "10 km or more", not for 9999 metres.
                $visibility ??= (int) $m[1] === 9999 ? 10000 : (int) $m[1];

                continue;
            }

            // Statute miles never appear in an Argentine report, but NOAA is a
            // relay and a relay can be reformatted; reading them costs one line.
            if (preg_match('/^(?:M|P)?(\d+)(?:\/(\d+))?SM$/', $token, $m) === 1) {
                $miles = ($m[2] ?? '') === '' ? (float) $m[1] : (float) $m[1] / (float) $m[2];
                $visibility ??= (int) round($miles * 1609.34);

                continue;
            }

            if (preg_match('/^(FEW|SCT|BKN|OVC|VV)(\d{3})(?:CB|TCU)?$/', $token, $m) === 1) {
                // Only broken and overcast make a ceiling; scattered and few do
                // not. Vertical visibility counts as one because it means the
                // sky is obscured outright, which is worse than overcast.
                if (in_array($m[1], ['BKN', 'OVC', 'VV'], true)) {
                    $height = (int) $m[2] * 100;
                    $ceiling = $ceiling === null ? $height : min($ceiling, $height);
                }

                continue;
            }

            if (preg_match('/^Q(\d{4})$/', $token, $m) === 1) {
                $qnh = (int) $m[1];

                continue;
            }

            // Inches of mercury, for the same relay reason as statute miles.
            if (preg_match('/^A(\d{4})$/', $token, $m) === 1) {
                $qnh ??= (int) round((int) $m[1] / 100 * 33.8639);

                continue;
            }

            if (self::isWeatherGroup($token)) {
                $phenomena[] = $token;
            }
        }

        return new self(
            isSpeci: $isSpeci,
            windDirection: $windDirection,
            windSpeed: $windSpeed,
            windGust: $windGust,
            visibility: $visibility,
            phenomena: $phenomena,
            ceiling: $ceiling,
            qnh: $qnh,
            windGroup: $windGroup,
        );
    }

    /**
     * What changed since $previous, in terms worth sending a message about.
     * An empty list means nothing did — the report is new but says the same
     * thing, which is the ordinary hourly case.
     *
     * @return array<int, string>
     */
    public function changesFrom(self $previous): array
    {
        $changes = [];

        // A SPECI is issued precisely because the issuer judged something had
        // changed enough to report off schedule. That judgement is made by the
        // meteorological service with the full picture, so it outranks every
        // threshold below — but only on the way in. A second SPECI in a row is
        // left to the thresholds, or a slow-moving front would ring the phone
        // every time it was re-observed.
        if ($this->isSpeci && ! $previous->isSpeci) {
            $changes[] = 'Se emitió un informe especial (SPECI), fuera del horario de rutina.';
        }

        if (($category = $this->category()) !== $previous->category()) {
            $changes[] = "Categoría de vuelo: {$previous->category()} → {$category}.";
        }

        foreach ([
            $this->windChange($previous),
            $this->visibilityChange($previous),
            $this->ceilingChange($previous),
            $this->phenomenaChange($previous),
            $this->qnhChange($previous),
        ] as $change) {
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * The standard VFR/MVFR/IFR/LIFR bands, taken from ceiling and visibility
     * together and always by the worse of the two.
     *
     * "Unknown" is its own value rather than an optimistic default: a report we
     * could not read a visibility out of is not a report of good visibility.
     */
    public function category(): string
    {
        if ($this->visibility === null && $this->ceiling === null) {
            return 'sin datos';
        }

        $visibility = $this->visibility ?? PHP_INT_MAX;
        $ceiling = $this->ceiling ?? PHP_INT_MAX;

        return match (true) {
            $visibility < 1600 || $ceiling < 500 => 'LIFR',
            $visibility < 5000 || $ceiling < 1000 => 'IFR',
            $visibility <= 8000 || $ceiling <= 3000 => 'MVFR',
            default => 'VFR',
        };
    }

    protected function windChange(self $previous): ?string
    {
        if ($this->windSpeed === null || $previous->windSpeed === null) {
            return null;
        }

        $label = fn (): string => 'Viento: '.($previous->windGroup ?? '—').' → '.($this->windGroup ?? '—').'.';

        if (abs($this->windSpeed - $previous->windSpeed) >= self::WIND_SPEED_DELTA) {
            return $label();
        }

        // A gust appearing or dying is a change in kind, not in degree: it is
        // the difference between a steady crosswind and one that has to be
        // flown for its peak.
        if (($this->windGust === null) !== ($previous->windGust === null)) {
            return $label();
        }

        if ($this->windGust !== null && $previous->windGust !== null
            && abs($this->windGust - $previous->windGust) >= self::WIND_SPEED_DELTA) {
            return $label();
        }

        // Going variable, or coming out of it, changes what the wind means for
        // a runway even when the speed is unchanged.
        if (($this->windDirection === null) !== ($previous->windDirection === null)) {
            return $label();
        }

        if ($this->windDirection === null || $previous->windDirection === null) {
            return null;
        }

        $strongEnough = max($this->windSpeed, $previous->windSpeed) >= self::WIND_DIRECTION_FLOOR;

        return $strongEnough && self::angleBetween($this->windDirection, $previous->windDirection) >= self::WIND_DIRECTION_DELTA
            ? $label()
            : null;
    }

    protected function visibilityChange(self $previous): ?string
    {
        if ($this->visibility === null || $previous->visibility === null) {
            return null;
        }

        if (self::band($this->visibility, self::VISIBILITY_BANDS) === self::band($previous->visibility, self::VISIBILITY_BANDS)) {
            return null;
        }

        return 'Visibilidad: '.self::visibilityLabel($previous->visibility).' → '.self::visibilityLabel($this->visibility).'.';
    }

    protected function ceilingChange(self $previous): ?string
    {
        // Null is real information here, not a gap: it means nothing broken or
        // overcast was reported, so it bands as "above everything".
        $current = $this->ceiling ?? PHP_INT_MAX;
        $before = $previous->ceiling ?? PHP_INT_MAX;

        if (self::band($current, self::CEILING_BANDS) === self::band($before, self::CEILING_BANDS)) {
            return null;
        }

        return 'Techo de nubes: '.self::ceilingLabel($previous->ceiling).' → '.self::ceilingLabel($this->ceiling).'.';
    }

    protected function phenomenaChange(self $previous): ?string
    {
        $current = $this->phenomena;
        $before = $previous->phenomena;

        sort($current);
        sort($before);

        if ($current === $before) {
            return null;
        }

        return 'Fenómenos: '.self::phenomenaLabel($before).' → '.self::phenomenaLabel($current).'.';
    }

    protected function qnhChange(self $previous): ?string
    {
        if ($this->qnh === null || $previous->qnh === null) {
            return null;
        }

        return abs($this->qnh - $previous->qnh) >= self::QNH_DELTA
            ? "QNH: {$previous->qnh} → {$this->qnh} hPa."
            : null;
    }

    /**
     * Which side of the thresholds a value falls on. Two values in the same
     * band are treated as the same condition however far apart they are.
     *
     * @param  array<int, int>  $bands
     */
    protected static function band(int $value, array $bands): int
    {
        $index = 0;

        foreach ($bands as $threshold) {
            if ($value < $threshold) {
                return $index;
            }

            $index++;
        }

        return $index;
    }

    /**
     * The shorter way round the compass between two bearings — 350° and 010°
     * are 20° apart, not 340°.
     */
    protected static function angleBetween(int $a, int $b): int
    {
        $difference = abs($a - $b) % 360;

        return (int) min($difference, 360 - $difference);
    }

    protected static function toKnots(int $speed, string $unit): int
    {
        return match ($unit) {
            'MPS' => (int) round($speed * 1.94384),
            'KMH' => (int) round($speed * 0.539957),
            default => $speed,
        };
    }

    protected static function isWeatherGroup(string $token): bool
    {
        if ($token === 'NSW') {
            return false;
        }

        $pattern = '/^(?:-|\+|VC)?(?:'.self::DESCRIPTORS.')?(?:(?:'.self::PHENOMENA.')+)?$/';

        if (preg_match($pattern, $token) !== 1) {
            return false;
        }

        // The pattern is built entirely of optional parts, so it also matches
        // the empty string and a bare intensity prefix. Requiring a descriptor
        // or a phenomenon is what makes it mean something.
        return preg_match('/(?:'.self::DESCRIPTORS.'|'.self::PHENOMENA.')/', $token) === 1;
    }

    protected static function visibilityLabel(int $metres): string
    {
        return $metres >= 10000 ? '10 km o más' : "{$metres} m";
    }

    protected static function ceilingLabel(?int $feet): string
    {
        return $feet === null ? 'sin techo' : "{$feet} ft";
    }

    /**
     * @param  array<int, string>  $phenomena
     */
    protected static function phenomenaLabel(array $phenomena): string
    {
        return $phenomena === [] ? 'ninguno' : implode(' ', $phenomena);
    }
}
