<?php

namespace App\Services;

/**
 * Turns a raw METAR/SPECI into plain Spanish, one line per decoded group.
 *
 * An observation is a single snapshot, so it reads as one flat run of groups:
 * the header, the conditions, and then whatever trend or remarks the issuer
 * appended. Everything the code shares with the forecast side — wind,
 * visibility, weather, cloud — is inherited from AviationCodeDecoder, which
 * also explains why no AI is involved in any of this.
 */
class MetarDecoder extends AviationCodeDecoder
{
    /**
     * @return array<int, string> One plain-Spanish line per group.
     */
    public function explain(string $raw): array
    {
        $tokens = $this->tokenize($raw);

        if ($tokens === []) {
            return [];
        }

        /** @var array<int, array{0: string, 1: string}> $entries type => text */
        $entries = [];

        foreach ($this->takeHeader($tokens) as $line) {
            $entries[] = ['line', $line];
        }

        while ($tokens !== []) {
            $token = array_shift($tokens);

            // Remarks run to the end of the report and are free-form, so they
            // are handed off wholesale rather than parsed group by group.
            if ($token === 'RMK') {
                $entries[] = ['line', $this->explainRemarks($tokens)];

                break;
            }

            // Wind shear is the only two-word group, e.g. "WS ALL RWY".
            if ($token === 'WS') {
                $entries[] = ['line', $this->explainWindShear($tokens)];

                continue;
            }

            if (($line = $this->explainSingle($token)) !== null) {
                $entries[] = ['line', $line];

                continue;
            }

            foreach (['weather', 'sky', 'rvr'] as $type) {
                if (($group = $this->matchGroup($type, $token)) !== null) {
                    $entries[] = [$type, $group];

                    continue 2;
                }
            }

            $entries[] = ['line', $this->explainUnknown($token)];
        }

        return $this->collapse($entries);
    }

    /**
     * Groups that decode on their own, independently of what surrounds them.
     * Returns null when this token isn't one of them.
     */
    protected function explainSingle(string $token): ?string
    {
        return match ($token) {
            'METAR' => 'Informe meteorológico rutinario de aeródromo (METAR).',
            'SPECI' => 'Informe meteorológico especial (SPECI), emitido fuera de horario por un cambio significativo.',
            'AUTO' => 'Informe generado de forma totalmente automática, sin intervención de un observador.',
            'RTD' => 'Observación rutinaria demorada.',
            default => $this->matchKeyword($token) ?? $this->matchStructured($token),
        };
    }

    protected function matchStructured(string $token): ?string
    {
        return $this->matchAny([
            'matchObservationTime',
            'matchWind',
            'matchWindVariation',
            'matchVisibility',
            'matchVisibilityStatute',
            'matchTemperatures',
            'matchPressure',
            'matchRecentWeather',
            'matchTrend',
        ], $token);
    }

    /**
     * The report type and the ICAO station indicator, which open every report
     * in that order.
     *
     * Both are consumed positionally rather than matched by shape, because
     * shape cannot tell them apart from what follows: "VCSH", "MIFG" and
     * "FZDZ" are all four letters and all weather groups, and a station code
     * matcher that went by appearance would eat them.
     *
     * @param  array<int, string>  $tokens  Consumed in place.
     * @return array<int, string>
     */
    protected function takeHeader(array &$tokens): array
    {
        $lines = [];

        foreach (['METAR', 'SPECI', 'COR'] as $keyword) {
            if (($tokens[0] ?? null) === $keyword) {
                $lines[] = (string) $this->explainSingle(array_shift($tokens));
            }
        }

        if (isset($tokens[0]) && preg_match('/^[A-Z]{4}$/', $tokens[0]) === 1) {
            $lines[] = 'Estación informante: '.array_shift($tokens).'.';
        }

        return $lines;
    }

    /**
     * "271400Z" — day of month, then time, always UTC.
     */
    protected function matchObservationTime(string $token): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})Z$/', $token, $m) !== 1) {
            return null;
        }

        return "Observación del día {$m[1]} a las {$m[2]}:{$m[3]} UTC.";
    }

    /**
     * "15/14", "M03/M07", "22/" (dew point missing).
     */
    protected function matchTemperatures(string $token): ?string
    {
        if (preg_match('/^(M?\d{2})\/(M?\d{2})?$/', $token, $m) !== 1) {
            return null;
        }

        $temperature = $this->signedTemperature($m[1]);
        $line = "Temperatura {$temperature} °C";

        if (($m[2] ?? '') === '') {
            return $line.', punto de rocío no informado.';
        }

        $dewPoint = $this->signedTemperature($m[2]);
        $line .= ", punto de rocío {$dewPoint} °C";

        return $line.' (humedad relativa '.$this->relativeHumidity($temperature, $dewPoint).' %).';
    }

    /**
     * Relative humidity from temperature and dew point via the Magnus formula.
     *
     * Derived rather than reported, but it is the single most asked-for figure
     * that a METAR doesn't state outright, and the derivation is exact enough
     * (better than 0.4 % over the range aviation cares about) that rounding to
     * a whole percent hides the error entirely.
     */
    protected function relativeHumidity(int $temperature, int $dewPoint): int
    {
        $saturation = fn (float $t): float => exp((17.625 * $t) / (243.04 + $t));

        return (int) round(100 * $saturation($dewPoint) / $saturation($temperature));
    }

    /**
     * "Q1009" (hPa, ICAO) or "A2990" (inches of mercury, US).
     */
    protected function matchPressure(string $token): ?string
    {
        // No thousands separator: a QNH is written as 1009, never as 1.009.
        if (preg_match('/^Q(\d{4})$/', $token, $m) === 1) {
            return 'Presión QNH '.(int) $m[1].' hPa.';
        }

        if (preg_match('/^A(\d{4})$/', $token, $m) === 1) {
            return 'Presión QNH '.number_format((int) $m[1] / 100, 2, ',', '.').' pulgadas de mercurio.';
        }

        return null;
    }

    /**
     * "RERA", "RETS" — weather observed since the previous report but no
     * longer occurring.
     */
    protected function matchRecentWeather(string $token): ?string
    {
        if (! str_starts_with($token, 'RE') || strlen($token) < 4) {
            return null;
        }

        $weather = $this->matchWeather(substr($token, 2));

        return $weather === null
            ? null
            : "Fenómeno reciente, ya finalizado: {$weather}.";
    }

    protected function matchTrend(string $token): ?string
    {
        /** @var array<string, string> $trends */
        $trends = self::table('trend');

        return isset($trends[$token]) ? ucfirst($trends[$token]).'.' : null;
    }

    /**
     * @param  array<int, string>  $tokens  Consumed in place.
     */
    protected function explainWindShear(array &$tokens): string
    {
        $target = [];

        // "WS ALL RWY" and "WS RWY11" are the two forms in use; take tokens
        // until something that clearly starts a new group.
        while ($tokens !== [] && preg_match('/^(ALL|RWY\d*|R\d{2}[LCR]?)$/', $tokens[0]) === 1) {
            $target[] = array_shift($tokens);
        }

        $where = $target === []
            ? ''
            : ' en '.(in_array('ALL', $target, true) ? 'todas las pistas' : 'la '.implode(' ', $target));

        return "Cizalladura del viento{$where}.";
    }

    protected function explainRemark(string $token): string
    {
        // PPxxx is the SMN's precipitation group: accumulated rainfall since
        // the previous observation, in tenths of a millimetre. It has no
        // forecast counterpart, which is why it lives here and not in the
        // shared glossary lookup.
        if (preg_match('/^PP(\d{3})$/', $token, $m) === 1) {
            $tenths = (int) $m[1];

            return $tenths === 0
                ? 'sin precipitación registrada desde la observación anterior'
                : number_format($tenths / 10, 1, ',', '.').' mm de precipitación desde la observación anterior';
        }

        return parent::explainRemark($token);
    }
}
