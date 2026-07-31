<?php

namespace App\Support;

/**
 * The wind at the surface, as the runway arithmetic needs it: a bearing, a
 * speed in knots, and a gust when the report carries one.
 *
 * It exists because that arithmetic now has two sources. A METAR reports the
 * wind in one self-describing group ("35015G25KT"), a SYNOP in a positional
 * one five characters wide ("Nddff"), and RunwayWind cares about neither —
 * it wants three numbers. Reading both into the same shape is what lets one
 * reply be built for an aerodrome with a METAR and for one that only AEROMET
 * covers, instead of two that would drift apart.
 *
 * Null is not "zero" in either field, and the difference is the whole point:
 * a null speed is a report whose wind group could not be read, a zero speed
 * is calm; a null direction with a speed is a variable wind, which has no
 * bearing to measure a runway against. Callers are expected to say which of
 * those happened rather than compute a component from it.
 */
final readonly class SurfaceWind
{
    /**
     * @param  int|null  $direction  Degrees true, or null when absent or variable.
     * @param  int|null  $speed  Knots, or null when the group could not be read.
     * @param  int|null  $gust  Knots, when the report names one.
     * @param  string|null  $group  The report's own wind text, when it has one short
     *                              enough to quote back ("35015G25KT"); null for a
     *                              SYNOP, whose wind is not separable from the report.
     */
    public function __construct(
        public ?int $direction = null,
        public ?int $speed = null,
        public ?int $gust = null,
        public ?string $group = null,
    ) {}

    public static function fromMetar(MetarConditions $conditions): self
    {
        return new self(
            direction: $conditions->windDirection,
            speed: $conditions->windSpeed,
            gust: $conditions->windGust,
            group: $conditions->windGroup,
        );
    }

    /**
     * The wind out of a raw WMO FM-12 SYNOP report — the "Nddff" group, fifth
     * of the report, direction in tens of degrees and speed in whatever unit
     * the "iw" digit of "YYGGiw" names (WMO code table 1855: 0/1 metres per
     * second, 3/4 knots already).
     *
     * Null when the report is not a SYNOP or is too short to have reached that
     * group — told apart from a report that has one it cannot read, which
     * comes back as a wind with a null speed.
     *
     * SYNOP does carry a maximum gust (911ff, section 3), and it is
     * deliberately not read here: it covers the whole period since the last
     * report rather than the moment observed, so quoting it beside an
     * instantaneous METAR gust would put two different measurements under the
     * same word.
     */
    public static function fromSynop(string $raw): ?self
    {
        $tokens = preg_split('/\s+/', trim(rtrim(trim($raw), '='))) ?: [];

        if (count($tokens) < 5 || $tokens[0] !== 'AAXX') {
            return null;
        }

        if (preg_match('/^\d{5}$/', $tokens[4]) !== 1) {
            return new self;
        }

        $direction = (int) substr($tokens[4], 1, 2);
        $speed = (int) substr($tokens[4], 3, 2);

        if ($direction === 0 && $speed === 0) {
            return new self(direction: 0, speed: 0);
        }

        $knots = self::toKnots($speed, preg_match('/^\d{4}(\d)$/', $tokens[1], $m) === 1 ? $m[1] : null);

        // 99 is the code for a variable direction, and dd is in tens of
        // degrees everywhere else — the same resolution a METAR reports, so
        // the components computed off it are no coarser here than there.
        return $direction === 99
            ? new self(speed: $knots)
            : new self(direction: $direction * 10 % 360, speed: $knots);
    }

    protected static function toKnots(int $speed, ?string $iw): int
    {
        return in_array($iw, ['0', '1'], true) ? (int) round($speed * 1.94384) : $speed;
    }
}
