<?php

namespace App\Services;

/**
 * Turns a raw TAF into plain Spanish, one line per decoded group.
 *
 * A TAF is built from the same vocabulary as a METAR — wind, visibility,
 * weather and cloud groups mean exactly the same thing in both — so all of that
 * is inherited from AviationCodeDecoder. What is specific to a forecast is the
 * shape around it: a validity period, and then a sequence of change groups
 * (FM, BECMG, TEMPO, PROB) that each open a new period the following groups
 * describe.
 *
 * Those change groups are what make a TAF worth decoding rather than reading
 * raw. "TEMPO 2808/2812 0500 FGDZ" is four hours in which the aerodrome may
 * drop to 500 m in freezing drizzle, and nothing about the raw text says so to
 * a reader who hasn't learned the code. They are rendered as a heading line
 * ending in ":" with the conditions listed under it, so it stays obvious which
 * period each group belongs to — attaching a forecast condition to the wrong
 * time window would be as bad as getting the condition itself wrong.
 */
class TafDecoder extends AviationCodeDecoder
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

            if ($token === 'RMK') {
                $entries[] = ['line', $this->explainRemarks($tokens)];

                break;
            }

            // Change groups carry their period in the following token, and
            // PROB carries the group it qualifies, so they consume ahead.
            if (($line = $this->explainChange($token, $tokens)) !== null) {
                $entries[] = ['line', $line];

                continue;
            }

            if (($line = $this->explainSingle($token)) !== null) {
                $entries[] = ['line', $line];

                continue;
            }

            // No runway visual range here: RVR is measured at the aerodrome and
            // reported in an observation, never forecast.
            foreach (['weather', 'sky'] as $type) {
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
     * The keyword, any amendment marker and the ICAO station indicator, which
     * open every forecast in that order.
     *
     * Consumed positionally rather than matched by shape, for the same reason
     * as in an observation: a four-letter station code is indistinguishable by
     * appearance from a weather group like "FZDZ".
     *
     * @param  array<int, string>  $tokens  Consumed in place.
     * @return array<int, string>
     */
    protected function takeHeader(array &$tokens): array
    {
        $lines = [];

        foreach (['TAF', 'AMD', 'COR'] as $keyword) {
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
     * Groups that decode on their own, independently of what surrounds them.
     * Returns null when this token isn't one of them.
     */
    protected function explainSingle(string $token): ?string
    {
        return match ($token) {
            'TAF' => 'Pronóstico de aeródromo (TAF).',
            'AMD' => 'Pronóstico enmendado (AMD): reemplaza al difundido previamente.',
            'CNL' => 'Pronóstico cancelado (CNL): el aeródromo queda sin TAF vigente.',
            default => $this->matchKeyword($token) ?? $this->matchStructured($token),
        };
    }

    protected function matchStructured(string $token): ?string
    {
        return $this->matchAny([
            'matchIssueTime',
            'matchValidity',
            'matchWind',
            'matchWindShear',
            'matchWindVariation',
            'matchVisibility',
            'matchVisibilityStatute',
            'matchTemperatureForecast',
        ], $token);
    }

    /**
     * "271700Z" — when the forecast was issued, always UTC.
     */
    protected function matchIssueTime(string $token): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})Z$/', $token, $m) !== 1) {
            return null;
        }

        return "Emitido el día {$m[1]} a las {$m[2]}:{$m[3]} UTC.";
    }

    /**
     * "2718/2818" — the period the forecast covers.
     *
     * This is the group that decides whether the rest of the text applies at
     * all, so it is stated in full rather than echoed: a reader who takes an
     * expired TAF for the current one is worse off than one who has none.
     */
    protected function matchValidity(string $token): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/', $token, $m) !== 1) {
            return null;
        }

        return 'Válido '.$this->periodPhrase($m[1], $m[2], $m[3], $m[4]).'.';
    }

    /**
     * "TX18/2719Z", "TNM03/2811Z" — the highest and lowest temperature expected
     * during the validity period, each with the hour it is expected at.
     */
    protected function matchTemperatureForecast(string $token): ?string
    {
        if (preg_match('/^(TX|TN)(M?\d{2})\/(\d{2})(\d{2})Z$/', $token, $m) !== 1) {
            return null;
        }

        $extreme = $m[1] === 'TX' ? 'máxima' : 'mínima';
        $degrees = $this->signedTemperature($m[2]);

        return "Temperatura {$extreme} prevista {$degrees} °C el día {$m[3]} a las {$m[4]}:00 UTC.";
    }

    /**
     * "WS020/24045KT" — non-convective low-level wind shear: the height of the
     * shear layer in hundreds of feet, then the wind above it.
     *
     * Written as one word here, unlike the observation's "WS ALL RWY", because
     * a forecast names the layer rather than the runways it affects.
     */
    protected function matchWindShear(string $token): ?string
    {
        if (preg_match('/^WS(\d{3})\/(\d{3}|VRB)(\d{2,3})(KT|MPS|KMH)$/', $token, $m) !== 1) {
            return null;
        }

        $height = $this->number((int) $m[1] * 100);

        return "Cizalladura del viento a {$height} ft: "
            .$this->windDetail($m[2], $m[3], '', $m[4]).'.';
    }

    /**
     * A change group: the heading that opens a new period, with everything
     * after it describing that period until the next one.
     *
     * @param  array<int, string>  $tokens  Consumed in place.
     */
    protected function explainChange(string $token, array &$tokens): ?string
    {
        // "FM280200" — a clean break. Unlike the others it takes effect at a
        // single moment and replaces the forecast outright, so it carries a
        // time rather than a period.
        if (preg_match('/^FM(\d{2})(\d{2})(\d{2})$/', $token, $m) === 1) {
            return "Desde el día {$m[1]} a las {$m[2]}:{$m[3]} UTC:";
        }

        if (preg_match('/^PROB(\d{2})$/', $token, $m) === 1) {
            // PROB normally qualifies a TEMPO ("PROB30 TEMPO 2808/2812"), but
            // is also used on its own for a whole period.
            $qualifies = in_array($tokens[0] ?? '', ['TEMPO', 'BECMG'], true)
                ? (string) array_shift($tokens)
                : '';

            $what = $qualifies === '' ? 'las siguientes condiciones' : $this->changeName($qualifies);

            return "Probabilidad del {$m[1]} % de {$what}"
                .$this->takePeriod($tokens).$this->changeGloss($qualifies).':';
        }

        if (! in_array($token, ['BECMG', 'TEMPO', 'INTER'], true)) {
            return null;
        }

        return ucfirst($this->changeName($token))
            .$this->takePeriod($tokens).$this->changeGloss($token).':';
    }

    protected function changeName(string $token): string
    {
        return match ($token) {
            'BECMG' => 'cambio gradual (BECMG)',
            'TEMPO' => 'fluctuaciones temporarias (TEMPO)',
            default => 'fluctuaciones frecuentes (INTER)',
        };
    }

    /**
     * What the indicator means in practice, appended after the period so the
     * heading reads as a sentence.
     *
     * TEMPO is the one worth spelling out. It does not mean the conditions hold
     * for the whole window — it means they may appear within it, each time for
     * under an hour — and reading it as "from 08 to 12 there will be fog" is
     * the mistake that turns a usable forecast into a wrong one.
     */
    protected function changeGloss(string $token): string
    {
        return $token === 'TEMPO' ? ', en períodos de menos de una hora cada vez' : '';
    }

    /**
     * The "2802/2804" a change group applies over, as a phrase with a leading
     * space. Empty when the group carries no period — a relayed forecast can
     * end with a bare trend-style BECMG — so the heading still reads correctly
     * instead of claiming a window that was never published.
     *
     * @param  array<int, string>  $tokens  Consumed in place.
     */
    protected function takePeriod(array &$tokens): string
    {
        if (preg_match('/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/', $tokens[0] ?? '', $m) !== 1) {
            return '';
        }

        array_shift($tokens);

        return ' '.$this->periodPhrase($m[1], $m[2], $m[3], $m[4]);
    }

    /**
     * A day/hour range, named the shorter way when it does not cross midnight.
     *
     * The day is always given: a TAF is read hours after it was issued, and
     * "entre las 02:00 y las 04:00" alone leaves the reader to work out which
     * day that was — the one mistake most likely to be made confidently.
     */
    protected function periodPhrase(string $fromDay, string $fromHour, string $toDay, string $toHour): string
    {
        return $fromDay === $toDay
            ? "el día {$fromDay} entre las {$fromHour}:00 y las {$toHour}:00 UTC"
            : "desde el día {$fromDay} a las {$fromHour}:00 hasta el día {$toDay} a las {$toHour}:00 UTC";
    }
}
