<?php

namespace App\Services;

/**
 * Turns a raw AEROMET line into plain Spanish, one line per decoded group.
 *
 * AEROMET's line is not a METAR: it comes from decoding a WMO FM-12 SYNOP
 * report into a compact SMN-specific shorthand ("JUNIN 090/06KT 12KM
 * 4Ci19800FT 16/07 Q1018.4"), confirmed group by group against the SMN's own
 * "Decodificado" screen for that same observation — so this is a small,
 * purpose-built grammar rather than a reuse of AviationCodeDecoder, whose
 * groups (wind, visibility, temperature...) are shaped for ICAO METAR/TAF
 * text and do not match AEROMET's syntax token for token (e.g. wind here is
 * "090/06KT", not METAR's "09006KT").
 *
 * Weather phenomena ("FBL RA CONS") are deliberately not decoded here: the
 * SMN prints its own plain-Spanish gloss of them alongside the line (see
 * SmnAerometSource::extraFields()), and trusting that beats guessing at a
 * translation table for every abbreviation it might use.
 */
class AerometDecoder
{
    /**
     * Cloud genus abbreviations to their full Spanish name, as confirmed
     * against the SMN's "Decodificado" breakdown ("Ci" -> "Cirrus").
     *
     * @var array<string, string>
     */
    protected const CLOUD_GENERA = [
        'Ci' => 'Cirrus', 'Cc' => 'Cirrocumulus', 'Cs' => 'Cirrostratus',
        'Ac' => 'Altocumulus', 'As' => 'Altostratus', 'Ns' => 'Nimbostratus',
        'Sc' => 'Stratocumulus', 'St' => 'Stratus', 'Cu' => 'Cumulus', 'Cb' => 'Cumulonimbus',
    ];

    /**
     * @param  string  $body  The raw line with the leading station name
     *                        already removed (AerometEnricher strips it,
     *                        since the station is already known separately).
     * @return array<int, string> One plain-Spanish line per decoded group.
     */
    public function explain(string $body): array
    {
        $lines = [];

        foreach (preg_split('/\s+/', trim($body)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            $line = $this->matchWind($token)
                ?? $this->matchVisibility($token)
                ?? $this->matchCloud($token)
                ?? $this->matchTemperatures($token)
                ?? $this->matchPressure($token);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * "090/06KT" — direction in whole degrees (already expanded from the
     * SYNOP code's tens-of-degrees group), then speed in knots.
     */
    protected function matchWind(string $token): ?string
    {
        if (preg_match('/^(\d{3})\/(\d{2})KT$/', $token, $m) !== 1) {
            return null;
        }

        if ($m[1] === '000' && $m[2] === '00') {
            return 'Viento: calma.';
        }

        return "Viento del {$m[1]}° a ".(int) $m[2].' nudos.';
    }

    /**
     * "12KM" — horizontal visibility at the surface.
     */
    protected function matchVisibility(string $token): ?string
    {
        if (preg_match('/^(\d{1,3})KM$/', $token, $m) !== 1) {
            return null;
        }

        return 'Visibilidad '.(int) $m[1].' km.';
    }

    /**
     * "4Ci19800FT" — cloud amount in octas, genus, base height in feet. Can
     * repeat, one token per layer, when more than one is reported.
     */
    protected function matchCloud(string $token): ?string
    {
        if (preg_match('/^(\d)([A-Z][a-z])(\d+)FT$/', $token, $m) !== 1) {
            return null;
        }

        $genus = self::CLOUD_GENERA[$m[2]] ?? $m[2];

        return "Nubes: {$m[1]}/8 {$genus} a ".number_format((int) $m[3], 0, ',', '.').' ft.';
    }

    /**
     * "16/07" — air temperature, then dew point, both °C. A leading "M"
     * marks a negative value, same convention METAR uses ("M03" = -3°C) —
     * not seen in a live response, since AEROMET is captured in summer, but
     * SYNOP itself signs its temperature group and there is no reason AEROMET
     * would drop that when the value goes below zero.
     */
    protected function matchTemperatures(string $token): ?string
    {
        if (preg_match('/^(M?\d{2})\/(M?\d{2})$/', $token, $m) !== 1) {
            return null;
        }

        $temperature = $this->signedTemperature($m[1]);
        $dewPoint = $this->signedTemperature($m[2]);

        return "Temperatura {$temperature} °C, punto de rocío {$dewPoint} °C.";
    }

    /**
     * "Q1018.4" — QNH in hPa, to one decimal (AEROMET carries the tenth the
     * SYNOP group does; METAR rounds it away).
     */
    protected function matchPressure(string $token): ?string
    {
        if (preg_match('/^Q(\d{4}\.\d)$/', $token, $m) !== 1) {
            return null;
        }

        return "Presión QNH {$m[1]} hPa.";
    }

    protected function signedTemperature(string $value): int
    {
        return str_starts_with($value, 'M') ? -(int) substr($value, 1) : (int) $value;
    }
}
