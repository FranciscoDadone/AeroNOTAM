<?php

namespace App\Services;

use App\Support\SurfaceWind;

/**
 * Turns a raw WMO FM-12 SYNOP report into plain Spanish, one line per
 * decoded group — what makes an OgimetAerometSource row readable, the same
 * way MetarDecoder does for a METAR (see its docblock and
 * OgimetAerometSource's own for why AEROMET's raw text is genuine SYNOP
 * rather than a simpler, purpose-built grammar).
 *
 * SYNOP's groups are positional, not self-describing (the same five
 * characters mean something different in the fourth group than they would
 * in the ninth) — so this walks the report in the fixed order WMO
 * regulation 12.2 gives Section 0/1's opening groups, then dispatches the
 * rest — including Section 3, entered at the "333" marker — by each group's
 * own leading digit.
 *
 * The code tables this relies on (WMO 0500, 1677, 4377) are taken verbatim
 * from pymetdecoder (antarctica/pymetdecoder, British Antarctic Survey), a
 * tested, actively maintained SYNOP decoder, rather than reconstructed from
 * memory: a first attempt at the pressure group's threshold rule, worked out
 * by hand, produced a physically implausible reading (835 hPa at mean sea
 * level) for a real high-altitude station's report — exactly the kind of
 * quiet, hard-to-notice error this class exists to avoid the reader ever
 * seeing.
 *
 * Present/past weather (7wwW1W2) is deliberately not decoded: there is no
 * plain-Spanish gloss to trust for it the way METAR remark groups sometimes
 * carry one, and guessing at a translation for every one of its ~100 codes
 * is worse than leaving it in the raw text shown alongside this explanation.
 *
 * Station-level pressure (3PPPP) is skipped too: it depends on the
 * station's own elevation and says nothing comparable between stations,
 * unlike QNH (4PPPP), which is what gets decoded here.
 */
class SynopDecoder
{
    /**
     * WMO code table 0500 — cloud genus, confirmed against pymetdecoder.
     *
     * @var array<int, string>
     */
    protected const CLOUD_GENUS = ['Ci', 'Cc', 'Cs', 'Ac', 'As', 'Ns', 'Sc', 'St', 'Cu', 'Cb'];

    /**
     * Cloud genus abbreviations to their full Spanish name.
     *
     * @var array<string, string>
     */
    protected const CLOUD_GENUS_NAMES = [
        'Ci' => 'Cirrus', 'Cc' => 'Cirrocumulus', 'Cs' => 'Cirrostratus',
        'Ac' => 'Altocumulus', 'As' => 'Altostratus', 'Ns' => 'Nimbostratus',
        'Sc' => 'Stratocumulus', 'St' => 'Stratus', 'Cu' => 'Cumulus', 'Cb' => 'Cumulonimbus',
    ];

    /**
     * @return array<int, string>
     */
    public function explain(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim(rtrim(trim($raw), '='))) ?: [];

        if (count($tokens) < 5 || $tokens[0] !== 'AAXX') {
            return [];
        }

        $windLine = $this->windLine(SurfaceWind::fromSynop($raw));
        $visibilityLine = $this->matchVisibility($tokens[3]);

        $temperature = null;
        $dewPoint = null;
        $pressureLine = null;
        $cloudLines = [];
        $inSection3 = false;

        foreach (array_slice($tokens, 5) as $token) {
            if ($token === '333' || $token === '222') {
                $inSection3 = $token === '333';

                continue;
            }

            if ($token === '') {
                continue;
            }

            if ($inSection3) {
                $cloudLine = $this->matchCloudLayer($token);

                if ($cloudLine !== null) {
                    $cloudLines[] = $cloudLine;
                }

                continue;
            }

            $temperature ??= $this->matchSignedTenths($token, '1');
            $dewPoint ??= $this->matchSignedTenths($token, '2');
            $pressureLine ??= $this->matchPressure($token);
        }

        $temperatureLine = $temperature !== null || $dewPoint !== null
            ? $this->temperatureLine($temperature, $dewPoint)
            : null;

        return array_values(array_filter(
            [$windLine, $visibilityLine, $temperatureLine, $pressureLine, ...$cloudLines],
            fn (?string $line) => $line !== null,
        ));
    }

    /**
     * The "Nddff" group in words. Reading it is SurfaceWind's job rather than
     * this class's, because the runway-component reply needs the same group as
     * numbers — one grammar, two renderings, instead of two grammars that
     * could come to disagree about the same report.
     *
     * A northerly is printed as 360, never 000: 000 is the code for calm,
     * which the branch above has already accounted for.
     */
    protected function windLine(?SurfaceWind $wind): ?string
    {
        if ($wind === null || $wind->speed === null) {
            return null;
        }

        if ($wind->speed === 0) {
            return 'Viento: calma.';
        }

        return $wind->direction === null
            ? "Viento variable a {$wind->speed} nudos."
            : sprintf('Viento del %03d° a %d nudos.', $wind->direction ?: 360, $wind->speed);
    }

    /**
     * "iihVV" — precipitation and weather group indicators, lowest cloud
     * base height code (both unused here) and horizontal visibility, WMO
     * code table 4377.
     */
    protected function matchVisibility(string $token): ?string
    {
        if (preg_match('/^\d{5}$/', $token) !== 1) {
            return null;
        }

        $meters = $this->visibilityMeters((int) substr($token, 3, 2));

        if ($meters === null) {
            return null;
        }

        if ($meters < 1000) {
            return "Visibilidad {$meters} m.";
        }

        $decimals = $meters % 1000 === 0 ? 0 : 1;

        return 'Visibilidad '.number_format($meters / 1000, $decimals, ',', '.').' km.';
    }

    /**
     * WMO code table 4377, confirmed against pymetdecoder.
     */
    protected function visibilityMeters(int $vv): ?int
    {
        return match (true) {
            $vv === 0 => 100,
            $vv >= 1 && $vv <= 50 => $vv * 100,
            $vv >= 56 && $vv <= 80 => ($vv - 50) * 1000,
            $vv >= 81 && $vv <= 88 => ($vv - 74) * 5000,
            $vv === 89 => 70000,
            $vv === 90 => 50,
            $vv === 91 => 50,
            $vv === 92 => 200,
            $vv === 93 => 500,
            $vv === 94 => 1000,
            $vv === 95 => 2000,
            $vv === 96 => 4000,
            $vv === 97 => 10000,
            $vv === 98 => 20000,
            $vv === 99 => 50000,
            default => null,
        };
    }

    /**
     * "1sTTT" (temperature) or "2sTTT" (dew point) — sign digit (0 positive
     * or zero, 1 negative), then the value in tenths of a degree Celsius.
     */
    protected function matchSignedTenths(string $token, string $groupIndicator): ?float
    {
        if (preg_match('/^'.$groupIndicator.'([01])(\d{3})$/', $token, $m) !== 1) {
            return null;
        }

        $value = ((int) $m[2]) / 10;

        return $m[1] === '1' ? -$value : $value;
    }

    protected function temperatureLine(?float $temperature, ?float $dewPoint): string
    {
        return sprintf(
            'Temperatura %s °C, punto de rocío %s °C.',
            $temperature !== null ? number_format($temperature, 1, ',', '.') : 's/d',
            $dewPoint !== null ? number_format($dewPoint, 1, ',', '.') : 's/d',
        );
    }

    /**
     * "4PPPP" — pressure reduced to mean sea level (QNH), in tenths of a
     * hectopascal with the thousands digit omitted. Reconstructing it
     * (prepend a "1" when the omitted digit reads as "0", otherwise take
     * the four digits as they are) and then bounding the result to a
     * physically plausible range is the same defensive shape pymetdecoder
     * itself uses — the omission is genuinely ambiguous at the edges, so a
     * value outside that range is dropped rather than shown wrong.
     */
    protected function matchPressure(string $token): ?string
    {
        if (preg_match('/^4(\d{4})$/', $token, $m) !== 1) {
            return null;
        }

        $digits = $m[1];
        $tenths = $digits[0] === '0' ? (int) ('1'.$digits) : (int) $digits;
        $hpa = $tenths / 10;

        if ($hpa < 700.0 || $hpa > 1200.0) {
            return null;
        }

        return 'Presión QNH '.number_format($hpa, 1, ',', '.').' hPa.';
    }

    /**
     * Section 3's "8NChh" — one group per cloud layer, amount in octas,
     * genus (WMO code table 0500), base height (WMO code table 1677) — both
     * confirmed against pymetdecoder. Distinct from Section 1's "8NCCC"
     * summary group, which this never sees: explain() only reaches here
     * once inside Section 3.
     */
    protected function matchCloudLayer(string $token): ?string
    {
        if (preg_match('/^8(\d)(\d)(\d{2})$/', $token, $m) !== 1) {
            return null;
        }

        $amount = (int) $m[1];
        $genus = self::CLOUD_GENUS[(int) $m[2]] ?? null;

        if ($genus === null || $amount < 1 || $amount > 8) {
            return null;
        }

        $name = self::CLOUD_GENUS_NAMES[$genus];
        $meters = $this->cloudLayerHeightMeters((int) $m[3]);

        if ($meters === null) {
            return "Nubes: {$amount}/8 {$name}.";
        }

        $feet = (int) round($meters * 3.28084 / 100) * 100;

        return "Nubes: {$amount}/8 {$name} a ".number_format($feet, 0, ',', '.').' ft.';
    }

    /**
     * WMO code table 1677, confirmed against pymetdecoder. Codes 90-98 name
     * a range rather than a point (used when the height is estimated
     * rather than measured) — skipped here rather than picking one end of
     * the range to present as if it were exact.
     */
    protected function cloudLayerHeightMeters(int $hh): ?int
    {
        return match (true) {
            $hh === 0 => 30,
            $hh >= 1 && $hh <= 50 => $hh * 30,
            $hh >= 56 && $hh <= 80 => ($hh - 50) * 300,
            $hh >= 81 && $hh <= 88 => (($hh - 80) * 1500) + 9000,
            $hh === 89 => 21000,
            default => null,
        };
    }
}
