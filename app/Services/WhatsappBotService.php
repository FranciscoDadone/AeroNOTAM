<?php

namespace App\Services;

use App\Ai\Agents\AirportMatcherAgent;
use App\DataObjects\Notam;
use App\Support\AirportResolver;

class WhatsappNotamBotService
{
    /**
     * Twilio hard-rejects any WhatsApp message body over 1600 characters.
     * We stay under that with margin to absorb multi-byte emoji and the
     * "(i/N)" prefix added when a reply needs to be split into several
     * messages.
     */
    protected const MAX_MESSAGE_LENGTH = 1500;

    public function __construct(
        protected AnacNotamService $anac,
        protected NotamEnricher $enricher,
        protected AirportResolver $airports,
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
            .'Por ejemplo: _"hay notams en Ezeiza?"_ o _"aeroparque"_ o el código _"EZE"_.';
    }
}
