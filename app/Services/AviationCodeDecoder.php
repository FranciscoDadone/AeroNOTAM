<?php

namespace App\Services;

use App\Support\Compass;

/**
 * The groups METAR and TAF have in common, and the machinery for turning a run
 * of them into plain Spanish.
 *
 * The two codes are deliberately built from the same vocabulary: a wind group,
 * a visibility group, a weather group and a cloud group mean exactly the same
 * thing whether they are reporting what the weather *is* or what it is forecast
 * to *be*. Only the surrounding structure differs — an observation is a single
 * snapshot, a forecast is a validity period cut into change groups — so that is
 * all the subclasses define.
 *
 * Unlike NOTAM text — free prose, where a model earns its keep — both codes are
 * fully specified and positional. Every group has exactly one meaning, so these
 * decoders are deterministic and offline: no AI is involved anywhere in the
 * path. That is a choice about safety rather than about cost. A model asked to
 * paraphrase "03009KT" can quietly return the wrong bearing or the wrong speed,
 * and a pilot has no way to tell from the Spanish alone that it happened. A
 * parser either recognises a group or leaves it verbatim.
 *
 * Anything unrecognised is therefore passed through untouched instead of being
 * guessed at, and the caller always keeps the raw report alongside this output.
 *
 * Abbreviations come from the NWS/FAA decode key, via
 * resources/data/metar-abbreviations.php.
 */
abstract class AviationCodeDecoder
{
    /**
     * @var array<string, mixed>|null
     */
    protected static ?array $tables = null;

    /**
     * @return array<int, string> One plain-Spanish line per group.
     */
    abstract public function explain(string $raw): array;

    /**
     * @return array<string, mixed>
     */
    protected static function table(string $name): array
    {
        self::$tables ??= require resource_path('data/metar-abbreviations.php');

        return self::$tables[$name];
    }

    /**
     * Run a token past a list of matchers in order, returning the first line
     * one of them produces. Order is the parser's only disambiguator, so each
     * subclass spells its own out rather than inheriting one.
     *
     * @param  array<int, string>  $matchers
     */
    protected function matchAny(array $matchers, string $token): ?string
    {
        foreach ($matchers as $matcher) {
            if (($line = $this->{$matcher}($token)) !== null) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Keyword groups that read the same in an observation and in a forecast.
     */
    protected function matchKeyword(string $token): ?string
    {
        return match ($token) {
            'CAVOK' => 'CAVOK: visibilidad de 10 km o más, sin nubes por debajo de 1.500 m (5.000 ft) ni cumulonimbus, y sin fenómenos meteorológicos significativos.',
            'COR' => 'Corrección de un informe difundido previamente.',
            'NIL' => 'Informe no disponible.',
            default => null,
        };
    }

    protected function matchGroup(string $type, string $token): ?string
    {
        return match ($type) {
            'weather' => $this->matchWeather($token),
            'sky' => $this->matchSky($token),
            default => $this->matchRunwayVisualRange($token),
        };
    }

    /**
     * Weather, sky and RVR are each reported as a run of adjacent groups —
     * three cloud layers, two runways — that belong in one sentence. The
     * format guarantees those runs are contiguous, so collapsing consecutive
     * entries of the same type gathers them without disturbing the order the
     * rest of the report is read in.
     *
     * @param  array<int, array{0: string, 1: string}>  $entries
     * @return array<int, string>
     */
    protected function collapse(array $entries): array
    {
        $lines = [];
        $run = [];
        $runType = '';

        foreach ($entries as [$type, $text]) {
            if ($type !== $runType && $run !== []) {
                $lines[] = $this->renderRun($runType, $run);
                $run = [];
            }

            $runType = $type;

            if ($type === 'line') {
                $lines[] = $text;

                continue;
            }

            $run[] = $text;
        }

        if ($run !== []) {
            $lines[] = $this->renderRun($runType, $run);
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $run
     */
    protected function renderRun(string $type, array $run): string
    {
        return match ($type) {
            'weather' => 'Fenómenos presentes: '.$this->joinList($run).'.',
            'sky' => 'Nubes: '.implode('; ', $run).'.',
            default => 'Alcance visual en pista: '.implode('; ', $run).'.',
        };
    }

    /**
     * "03009KT", "16011G25KT", "VRB09KT", "00000KT", "18010MPS".
     */
    protected function matchWind(string $token): ?string
    {
        if (preg_match('/^(\d{3}|VRB)(\d{2,3})(?:G(\d{2,3}))?(KT|MPS|KMH)$/', $token, $m) !== 1) {
            return null;
        }

        // An unmatched gust group still comes back, as an empty string: it sits
        // before a group that always matches, so PCRE has to fill it in.
        return ucfirst($this->windPhrase($m[1], $m[2], $m[3], $m[4])).'.';
    }

    /**
     * The wind as a sentence, uncapitalised and unterminated.
     */
    protected function windPhrase(string $direction, string $speed, string $gust, string $unit): string
    {
        $detail = $this->windDetail($direction, $speed, $gust, $unit);

        return $detail === 'calma' ? 'viento: calma' : "viento {$detail}";
    }

    /**
     * The wind without the word "viento", so a caller that has already said it
     * — a forecast's wind shear group names the layer first — does not say it
     * twice.
     */
    protected function windDetail(string $direction, string $speed, string $gust, string $unit): string
    {
        $units = match ($unit) {
            'MPS' => 'm/s',
            'KMH' => 'km/h',
            default => 'nudos',
        };

        $speed = (int) $speed;

        // 00000KT is the ICAO encoding for calm, and reads as nonsense if
        // decoded literally as "from 000 degrees at 0 knots".
        if ($direction === '000' && $speed === 0) {
            return 'calma';
        }

        $from = $direction === 'VRB'
            ? 'de dirección variable'
            : "del {$direction}° ({$this->compassName((int) $direction)})";

        $detail = "{$from} a {$speed} {$units}";

        return $gust === '' ? $detail : "{$detail}, con ráfagas de {$gust} {$units}";
    }

    /**
     * "340V050" — the directional spread reported when wind direction varies
     * by 60° or more.
     */
    protected function matchWindVariation(string $token): ?string
    {
        if (preg_match('/^(\d{3})V(\d{3})$/', $token, $m) !== 1) {
            return null;
        }

        return "Dirección del viento variando entre {$m[1]}° y {$m[2]}°.";
    }

    /**
     * Metric prevailing visibility, e.g. "9999", "7000", "0800", optionally
     * with a direction suffix ("2000NE") for the minimum sector visibility.
     */
    protected function matchVisibility(string $token): ?string
    {
        if (preg_match('/^(\d{4})(N|NE|E|SE|S|SW|W|NW)?$/', $token, $m) !== 1) {
            return null;
        }

        $metres = (int) $m[1];

        // 9999 is the ICAO code for "10 km or more", not a literal 9,999 m.
        $distance = match (true) {
            $metres === 9999 => '10 km o más',
            $metres === 0 => 'menos de 50 m',
            $metres >= 1000 => $this->number($metres / 1000).' km',
            default => $this->number($metres).' m',
        };

        $line = "Visibilidad {$distance}";

        if (($m[2] ?? '') !== '') {
            $line .= ' hacia el '.$this->cardinalName($m[2]);
        }

        return $line.'.';
    }

    /**
     * US-style visibility in statute miles, e.g. "1SM", "1/2SM", "M1/4SM".
     * Argentine reports are metric, but the source decode key documents this
     * form and the SMN also relays foreign stations.
     */
    protected function matchVisibilityStatute(string $token): ?string
    {
        if (preg_match('/^(M|P)?(\d+(?:\/\d+)?)SM$/', $token, $m) !== 1) {
            return null;
        }

        $qualifier = match ($m[1]) {
            'M' => 'menos de ',
            'P' => 'más de ',
            default => '',
        };

        $unit = $m[2] === '1' ? 'milla terrestre' : 'millas terrestres';

        return "Visibilidad {$qualifier}{$m[2]} {$unit}.";
    }

    /**
     * "R11/P6000FT", "R25/0600", "R25L/M0050V0200U".
     */
    protected function matchRunwayVisualRange(string $token): ?string
    {
        $pattern = '/^R(\d{2}[LCR]?)\/([MP])?(\d{4})(?:V([MP])?(\d{4}))?([UDN])?(FT)?$/';

        if (preg_match($pattern, $token, $m) !== 1) {
            return null;
        }

        $m += array_fill(0, 8, '');
        $unit = $m[7] === 'FT' ? 'ft' : 'm';

        $value = $this->qualify($m[2], $this->number((int) $m[3]))." {$unit}";

        if ($m[5] !== '') {
            $value .= ', variando hasta '.$this->qualify($m[4], $this->number((int) $m[5]))." {$unit}";
        }

        $tendency = match ($m[6]) {
            'U' => ' y en aumento',
            'D' => ' y en descenso',
            'N' => ' y sin cambios',
            default => '',
        };

        return "pista {$m[1]}, {$value}{$tendency}";
    }

    protected function qualify(string $qualifier, string $value): string
    {
        return match ($qualifier) {
            'M' => "menos de {$value}",
            'P' => "más de {$value}",
            default => $value,
        };
    }

    /**
     * Weather, e.g. "-RA", "+TSRA", "VCSH", "BR", "MIFG".
     *
     * Built as intensity + descriptor + phenomena, in that order, which is how
     * the code is defined — so the parser consumes the token the same way
     * rather than looking the whole string up in a table of combinations.
     */
    protected function matchWeather(string $token): ?string
    {
        if ($token === 'NSW') {
            return 'fin del fenómeno meteorológico significativo';
        }

        $rest = $token;

        /** @var array<string, string> $intensities */
        $intensities = self::table('weather_intensity');
        $intensity = '';

        foreach (['-', '+', 'VC'] as $prefix) {
            if (str_starts_with($rest, $prefix)) {
                $intensity = $intensities[$prefix];
                $rest = substr($rest, strlen($prefix));

                break;
            }
        }

        /** @var array<string, string> $descriptors */
        $descriptors = self::table('weather_descriptor');
        $template = null;

        if ($rest !== '' && isset($descriptors[substr($rest, 0, 2)])) {
            $template = $descriptors[substr($rest, 0, 2)];
            $rest = substr($rest, 2);
        }

        /** @var array<string, string> $phenomena */
        $phenomena = self::table('weather_phenomenon');
        $found = [];

        while ($rest !== '') {
            $code = substr($rest, 0, 2);

            if (! isset($phenomena[$code])) {
                return null;
            }

            $found[] = $phenomena[$code];
            $rest = substr($rest, 2);
        }

        if ($found === [] && $template === null) {
            return null;
        }

        $phrase = $found === [] ? '' : $this->joinList($found);

        if ($template !== null) {
            // With no phenomena the placeholder leaves a dangling connector
            // behind — "chaparrones de" — so the connector goes with it.
            $phrase = trim(str_replace(':x', $phrase, $template));
            $phrase = preg_replace('/\s+(?:de|con)$/u', '', $phrase) ?? $phrase;
            $phrase = trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
        }

        // "VC" qualifies the whole group ("chaparrones en las proximidades"),
        // while "-"/"+" qualify its severity ("lluvia ligera"), so the two sit
        // on opposite sides of the phrase.
        return match ($intensity) {
            '' => $phrase,
            'en las proximidades' => "{$phrase} en las proximidades",
            default => "{$phrase} {$intensity}",
        };
    }

    /**
     * Sky condition, e.g. "BKN008", "OVC100", "FEW047CB", "VV003", "NSC".
     * Height is reported in hundreds of feet.
     */
    protected function matchSky(string $token): ?string
    {
        /** @var array<string, string> $cover */
        $cover = self::table('sky_cover');

        if (isset($cover[$token])) {
            return $cover[$token];
        }

        if (preg_match('/^(FEW|SCT|BKN|OVC|VV)(\d{3}|\/{3})(CB|TCU)?$/', $token, $m) !== 1) {
            return null;
        }

        $m += [3 => ''];

        $height = $m[2] === '///'
            ? 'a altura no determinada'
            : 'a '.$this->number((int) $m[2] * 100).' ft';

        // A vertical visibility group replaces cloud layers entirely: the sky
        // is obscured, so there is no layer to report a height for.
        if ($m[1] === 'VV') {
            return "cielo oculto, visibilidad vertical {$height}";
        }

        $line = "{$cover[$m[1]]} {$height}";

        if ($m[3] !== '') {
            /** @var array<string, string> $types */
            $types = self::table('cloud_type');
            $line .= " de tipo {$types[$m[3]]}";
        }

        return $line;
    }

    /**
     * "M" is the encoding for a negative value, not a separate field.
     */
    protected function signedTemperature(string $value): int
    {
        return str_starts_with($value, 'M')
            ? -1 * (int) substr($value, 1)
            : (int) $value;
    }

    /**
     * Remarks run to the end of a report and are free-form, so they are handed
     * off wholesale rather than parsed group by group.
     *
     * @param  array<int, string>  $tokens
     */
    protected function explainRemarks(array $tokens): string
    {
        if ($tokens === []) {
            return 'Sin observaciones adicionales (RMK).';
        }

        return 'Observaciones adicionales (RMK): '
            .implode('; ', array_map($this->explainRemark(...), $tokens)).'.';
    }

    protected function explainRemark(string $token): string
    {
        /** @var array<string, string> $abbreviations */
        $abbreviations = self::table('abbreviations');

        return $abbreviations[$token] ?? $token;
    }

    /**
     * A group no rule matched. Reported as-is and explicitly flagged, so an
     * unfamiliar code is visible to the reader rather than silently dropped —
     * a missing group in a weather report is worse than an untranslated one.
     */
    protected function explainUnknown(string $token): string
    {
        /** @var array<string, string> $abbreviations */
        $abbreviations = self::table('abbreviations');

        return isset($abbreviations[$token])
            ? ucfirst($abbreviations[$token]).'.'
            : "Grupo sin decodificar: {$token}.";
    }

    /**
     * @return array<int, string>
     */
    protected function tokenize(string $raw): array
    {
        // "=" terminates a report and carries no meaning of its own.
        $raw = str_replace('=', ' ', mb_strtoupper(trim($raw)));

        return preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    protected function compassName(int $degrees): string
    {
        return Compass::name($degrees);
    }

    /**
     * The English cardinal suffixes the code uses for sector visibility, named
     * in Spanish (which writes oeste, not west).
     */
    protected function cardinalName(string $cardinal): string
    {
        return match ($cardinal) {
            'N' => 'norte',
            'NE' => 'noreste',
            'E' => 'este',
            'SE' => 'sudeste',
            'S' => 'sur',
            'SW' => 'sudoeste',
            'W' => 'oeste',
            'NW' => 'noroeste',
            default => $cardinal,
        };
    }

    protected function number(float $value): string
    {
        return $value == (int) $value
            ? number_format($value, 0, ',', '.')
            : number_format($value, 1, ',', '.');
    }

    /**
     * @param  array<int, string>  $items
     */
    protected function joinList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
