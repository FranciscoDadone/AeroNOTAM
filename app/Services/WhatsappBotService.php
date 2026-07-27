<?php

namespace App\Services;

use App\Ai\Agents\AirportMatcherAgent;
use App\DataObjects\Metar;
use App\DataObjects\Notam;
use App\DataObjects\Taf;
use App\Support\AirportResolver;
use Illuminate\Support\Str;

/**
 * Turns an incoming WhatsApp message into the reply to send back.
 *
 * Two things have to be worked out from free text: which aerodrome the user
 * means, and what they want to know about it. The aerodrome is resolved once,
 * up front, and shared by both answers; the question then routes to NOTAMs
 * (the default) or to the current METAR.
 */
class WhatsappBotService
{
    /**
     * Twilio hard-rejects any WhatsApp message body over 1600 characters.
     * We stay under that with margin to absorb multi-byte emoji and the
     * "(i/N)" prefix added when a reply needs to be split into several
     * messages.
     */
    protected const MAX_MESSAGE_LENGTH = 1500;

    /**
     * Words that mean the user is asking about current weather rather than
     * NOTAMs. Matched against the accent-stripped message, so "presión" and
     * "presion" both hit.
     */
    protected const METAR_KEYWORDS = [
        'metar', 'speci', 'clima', 'tiempo', 'meteorolog', 'viento', 'temperatura',
        'visibilidad', 'llueve', 'lluvia', 'niebla', 'nubes', 'nublado', 'presion',
        'qnh', 'techo', 'rafaga', 'humedad',
    ];

    /**
     * Words that mean the user is asking what the weather is going to do, which
     * is a TAF and not a METAR.
     *
     * These used to fall through to NOTAMs on purpose: a METAR is an
     * observation of what the weather is doing now, and answering a question
     * about tomorrow with one would be confidently wrong. Now that there is a
     * forecast to answer with, they route here — and they are checked before
     * the METAR words, because "cómo va a estar el tiempo mañana" contains both
     * and the forecast is the honest reading of it.
     */
    protected const TAF_KEYWORDS = [
        'pronostico', 'prevision', 'manana', 'proximas horas', 'mas tarde',
        'va a llover', 'va a estar', 'va a haber', 'esta noche',
    ];

    public function __construct(
        protected AnacNotamService $anac,
        protected NotamEnricher $enricher,
        protected AirportResolver $airports,
        protected MetarService $metarService,
        protected MetarEnricher $metarEnricher,
        protected TafService $tafService,
        protected TafEnricher $tafEnricher,
    ) {}

    /**
     * Build the natural-language WhatsApp reply for an incoming message, as
     * a list of message bodies — more than one only when the full reply
     * would exceed Twilio's per-message character limit.
     *
     * @return array<int, string>
     */
    public function reply(string $message): array
    {
        $message = trim($message);

        if ($message === '') {
            return [$this->helpMessage()];
        }

        $indicator = $this->matchIndicator($message);

        if ($indicator === null) {
            // Several aerodromes share the name the user typed (Córdoba has
            // three). Asking is the only honest answer — picking one silently
            // could send a pilot the wrong aerodrome's NOTAMs.
            $candidates = $this->airports->candidatesFromText($message);

            return [count($candidates) > 1
                ? $this->disambiguationMessage($candidates)
                : $this->helpMessage()];
        }

        return match ($this->topic($message)) {
            'taf' => $this->tafReply($indicator),
            'metar' => $this->metarReply($indicator),
            default => $this->notamReply($indicator),
        };
    }

    /**
     * What the message is actually asking for.
     *
     * "notam" wins outright when the word is there: someone who typed it knows
     * what they want, and "hay notams para mañana en EZE" must not be answered
     * with a forecast just because it mentions tomorrow.
     */
    protected function topic(string $message): string
    {
        $normalized = Str::ascii(mb_strtolower($message));

        return match (true) {
            str_contains($normalized, 'notam') => 'notam',
            preg_match('/\btaf\b/', $normalized) === 1 => 'taf',
            $this->mentions($normalized, self::TAF_KEYWORDS) => 'taf',
            $this->mentions($normalized, self::METAR_KEYWORDS) => 'metar',
            default => 'notam',
        };
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function mentions(string $normalized, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function notamReply(string $indicator): array
    {
        try {
            $notams = $this->anac->getNotams($indicator);
        } catch (\Throwable $e) {
            report($e);

            return ['Encontré el aeropuerto pero no pude obtener sus NOTAM en este momento. Probá de nuevo en unos minutos.'];
        }

        return $this->formatNotams(
            $this->airports->nameFor($indicator) ?? $indicator,
            $indicator,
            $this->enricher->enrich($notams),
        );
    }

    /**
     * @return array<int, string>
     */
    protected function metarReply(string $indicator): array
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        // Not every ANAC aerodrome has an ICAO code, and the SMN indexes
        // observations by that code alone. Saying so is more useful than a
        // generic failure, because retrying will never help.
        if ($icao === null) {
            return ["*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica METAR para ese aeródromo."];
        }

        try {
            $metars = $this->metarService->getMetars($icao);
        } catch (\Throwable $e) {
            report($e);

            return ['Encontré el aeropuerto pero no pude obtener su METAR en este momento. Probá de nuevo en unos minutos.'];
        }

        return $this->formatMetars($name, $icao, $this->metarEnricher->enrich($metars));
    }

    /**
     * @return array<int, string>
     */
    protected function tafReply(string $indicator): array
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        if ($icao === null) {
            return ["*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica TAF para ese aeródromo."];
        }

        try {
            $tafs = $this->tafService->getTafs($icao);
        } catch (\Throwable $e) {
            report($e);

            return ['Encontré el aeropuerto pero no pude obtener su pronóstico TAF en este momento. Probá de nuevo en unos minutos.'];
        }

        return $this->formatTafs($name, $icao, $this->tafEnricher->enrich($tafs));
    }

    /**
     * The forecast verbatim, then what it says in Spanish — same shape as the
     * METAR reply, and for the same reason: the raw TAF is the form a pilot can
     * cross-check against any other source.
     *
     * @param  array<int, Taf>  $tafs
     * @return array<int, string>
     */
    protected function formatTafs(string $airportName, string $icao, array $tafs): array
    {
        if ($tafs === []) {
            return ["No hay TAF publicado para *{$airportName}* ({$icao}) en este momento."];
        }

        $header = "🔭 *{$airportName}* ({$icao})";
        $total = count($tafs);
        $credit = $this->sourceCredit($tafs[0]->isRelayed());
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;

        $parts = [];

        foreach ($tafs as $i => $taf) {
            $lines = [];

            // A cancelled TAF means the aerodrome has no valid forecast at all,
            // and an amendment means the previous one was withdrawn early.
            // Either changes what the text below is worth, so neither is left
            // for the reader to notice on their own.
            if ($taf->isCancelled()) {
                $lines[] = '⚠️ Pronóstico cancelado (CNL)';
            } elseif ($taf->isAmended()) {
                $lines[] = '⚠️ Pronóstico enmendado (AMD)';
            }

            $lines[] = '```'.$taf->raw.'```';

            if ($taf->explanation !== []) {
                $lines[] = '';
                $lines[] = '📋 *Qué dice*';

                foreach ($taf->explanation as $line) {
                    $lines[] = "• {$line}";
                }
            }

            if ($i === $total - 1) {
                $lines[] = '';
                $lines[] = $credit;
            }

            foreach ($this->splitToFit(implode("\n", $lines), $budget) as $part) {
                $parts[] = $part;
            }
        }

        return $this->withHeader($header, $parts);
    }

    /**
     * One WhatsApp message per observation: the report verbatim, then what it
     * says in Spanish.
     *
     * The raw METAR leads and is never dropped — it is the internationally
     * standard form a pilot can cross-check against any other source, and the
     * explanation underneath is what makes it readable to everyone else.
     *
     * @param  array<int, Metar>  $metars
     * @return array<int, string>
     */
    protected function formatMetars(string $airportName, string $icao, array $metars): array
    {
        if ($metars === []) {
            return ["No hay METAR publicado para *{$airportName}* ({$icao}) en este momento."];
        }

        $header = "🌦️ *{$airportName}* ({$icao})";
        $total = count($metars);
        $credit = $this->sourceCredit($metars[0]->isRelayed());
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;

        $parts = [];

        foreach ($metars as $i => $metar) {
            $lines = [];

            // A SPECI is issued because something changed sharply enough to
            // warrant an off-schedule report, so it gets flagged rather than
            // reading like the routine hourly observation.
            if ($metar->isSpeci()) {
                $lines[] = '⚠️ Informe especial (SPECI)';
            }

            $lines[] = '```'.$metar->raw.'```';

            if ($metar->explanation !== []) {
                $lines[] = '';
                $lines[] = '📋 *Qué dice*';

                foreach ($metar->explanation as $line) {
                    $lines[] = "• {$line}";
                }
            }

            if ($i === $total - 1) {
                $lines[] = '';
                $lines[] = $credit;
            }

            foreach ($this->splitToFit(implode("\n", $lines), $budget) as $part) {
                $parts[] = $part;
            }
        }

        return $this->withHeader($header, $parts);
    }

    /**
     * The SMN issues these reports either way — NOAA only relays them over the
     * international exchange — so the credit names the SMN in both cases and
     * mentions the relay only when there was one. Hiding the relay would be
     * dishonest; leading with it would misattribute the report.
     */
    protected function sourceCredit(bool $relayed): string
    {
        return '_Fuente: Servicio Meteorológico Nacional_';
    }

    /**
     * Try to resolve an airport indicator from free text: first with cheap,
     * deterministic code/name matching, then — only if that fails — with an
     * AI call, so most messages never need a model at all.
     */
    protected function matchIndicator(string $message): ?string
    {
        return $this->airports->matchFromText($message)
            ?? $this->matchIndicatorWithAi($message);
    }

    protected function matchIndicatorWithAi(string $message): ?string
    {
        if (blank(config('ai.providers.openrouter.key'))) {
            return null;
        }

        $airports = $this->airports->known();

        $list = collect($airports)
            ->map(fn (string $name, string $code) => "{$code} - {$name}")
            ->implode("\n");

        try {
            $response = AirportMatcherAgent::make()->prompt(
                "Airport list:\n{$list}\n\nMessage: \"{$message}\""
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $code = strtoupper(trim((string) $response));

        return array_key_exists($code, $airports) ? $code : null;
    }

    /**
     * One WhatsApp message per NOTAM — or several, when a single NOTAM's text
     * exceeds Twilio's per-message limit. Each carries an "(i/N)" prefix so a
     * multi-part reply reads as a clearly numbered sequence in the chat.
     *
     * A long NOTAM is split rather than truncated: dropping the tail of an
     * aeronautical notice would silently hide exactly the kind of detail
     * (a closure window, a contact number) the pilot needs.
     *
     * @param  array<int, Notam>  $notams
     * @return array<int, string>
     */
    protected function formatNotams(string $airportName, string $indicator, array $notams): array
    {
        if ($notams === []) {
            return ["No hay NOTAM activos para *{$airportName}* ({$indicator}) en este momento. ✅"];
        }

        $header = "✈️ *{$airportName}* ({$indicator})";
        $total = count($notams);

        // Reserve room for the header and the widest plausible "(99/99) "
        // prefix, so the assembled message still fits once both are added.
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;

        $parts = [];

        foreach ($notams as $i => $notam) {
            $vigencia = $notam->permanent
                ? 'permanente'
                : "hasta {$notam->validUntil} UTC";

            $lines = [
                ($i + 1).". *{$notam->number}*",
                $notam->displayText(),
                "🕐 Desde {$notam->validFrom} UTC, {$vigencia}.",
            ];

            if ($i === $total - 1) {
                $lines[] = '';
                $lines[] = '_Fuente: ANAC Argentina_';
            }

            foreach ($this->splitToFit(implode("\n", $lines), $budget) as $part) {
                $parts[] = $part;
            }
        }

        return $this->withHeader($header, $parts);
    }

    /**
     * Put the aerodrome header on every message body, numbering them "(i/N)"
     * when there is more than one so a split reply reads as a clearly ordered
     * sequence in the chat rather than as a burst of unrelated messages.
     *
     * @param  array<int, string>  $parts
     * @return array<int, string>
     */
    protected function withHeader(string $header, array $parts): array
    {
        $partCount = count($parts);

        return array_map(
            fn (string $part, int $i) => $partCount > 1
                ? '('.($i + 1)."/{$partCount}) {$header}\n{$part}"
                : "{$header}\n{$part}",
            $parts,
            array_keys($parts),
        );
    }

    /**
     * Break text into chunks of at most $limit characters, preferring line
     * breaks and then word boundaries so a split never lands mid-word.
     *
     * @return array<int, string>
     */
    protected function splitToFit(string $text, int $limit): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            // A single line longer than the limit has to be broken up itself.
            foreach ($this->wrapLine($line, $limit) as $piece) {
                $candidate = $current === '' ? $piece : $current."\n".$piece;

                if (mb_strlen($candidate) <= $limit) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $chunks[] = $current;
                }

                $current = $piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    protected function wrapLine(string $line, int $limit): array
    {
        if (mb_strlen($line) <= $limit) {
            return [$line];
        }

        $pieces = [];
        $current = '';

        foreach (explode(' ', $line) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $pieces[] = $current;
                $current = '';
            }

            // A single word longer than the whole budget (a URL, a coordinate
            // run) still has to be cut somewhere — hard-split it.
            while (mb_strlen($word) > $limit) {
                $pieces[] = mb_substr($word, 0, $limit);
                $word = mb_substr($word, $limit);
            }

            $current = $word;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @param  array<string, string>  $candidates
     */
    protected function disambiguationMessage(array $candidates): string
    {
        $lines = ['Encontré varios aeródromos que coinciden. ¿Cuál te interesa?', ''];

        foreach ($candidates as $code => $name) {
            $lines[] = "• *{$code}* — {$name}";
        }

        $lines[] = '';
        $lines[] = 'Respondeme con el código.';

        return implode("\n", $lines);
    }

    protected function helpMessage(): string
    {
        return "¡Hola! 👋 Decime el aeropuerto que te interesa y te paso sus NOTAM activos.\n\n"
            .'Por ejemplo: _"hay notams en Ezeiza?"_ o _"aeroparque"_ o el código _"EZE"_.'
            ."\n\n"
            .'Si querés el estado del tiempo, pedime el METAR: _"metar EZE"_ o _"cómo está el clima en Bariloche?"_.'
            ."\n\n"
            .'Y si lo que te interesa es cómo va a estar, pedime el TAF: _"taf EZE"_ o _"pronóstico de Aeroparque"_.';
    }
}
