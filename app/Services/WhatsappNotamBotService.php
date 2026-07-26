<?php

namespace App\Services;

use App\Ai\Agents\AirportMatcherAgent;
use Illuminate\Support\Str;

class WhatsappNotamBotService
{
    /**
     * Twilio hard-rejects any WhatsApp message body over 1600 characters.
     * We stay under that with margin to absorb multi-byte emoji and the
     * "(i/N)" prefix added when a reply needs to be split into several
     * messages.
     */
    protected const MAX_MESSAGE_LENGTH = 1500;

    public function __construct(protected AnacNotamService $anac) {}

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

        try {
            $knownAirports = $this->anac->getKnownAirports();
        } catch (\Throwable $e) {
            report($e);

            return ['No pude consultar el servicio de NOTAM de ANAC en este momento. Probá de nuevo en unos minutos.'];
        }

        $indicator = $this->matchIndicator($message, $knownAirports);

        if ($indicator === null) {
            return [$this->helpMessage()];
        }

        try {
            $notams = $this->anac->getNotams($indicator);
        } catch (\Throwable $e) {
            report($e);

            return ['Encontré el aeropuerto pero no pude obtener sus NOTAM en este momento. Probá de nuevo en unos minutos.'];
        }

        return $this->formatNotams($knownAirports[$indicator], $indicator, $notams);
    }

    /**
     * Try to resolve an airport indicator from free text: first with cheap,
     * deterministic code/name matching, then — only if that fails — with an
     * AI call, so most messages never need a model at all.
     *
     * @param  array<string, string>  $airports
     */
    protected function matchIndicator(string $message, array $airports): ?string
    {
        return $this->matchIndicatorDeterministically($message, $airports)
            ?? $this->matchIndicatorWithAi($message, $airports);
    }

    /**
     * @param  array<string, string>  $airports
     */
    protected function matchIndicatorDeterministically(string $message, array $airports): ?string
    {
        // Direct ANAC code mention, e.g. "notams EZE" or "eze".
        foreach ($airports as $code => $name) {
            if (ctype_alpha($code) && preg_match('/\b'.preg_quote($code, '/').'\b/i', $message) === 1) {
                return $code;
            }
        }

        // Direct OACI/ICAO code mention, e.g. "notams SAEZ" or "saez".
        foreach ($this->anac->oaciCodes() as $oaci => $localCode) {
            if (array_key_exists($localCode, $airports) && preg_match('/\b'.preg_quote($oaci, '/').'\b/i', $message) === 1) {
                return $localCode;
            }
        }

        // Airport/city name mention, e.g. "ezeiza", "bariloche", "aeroparque".
        // Skip FIR-wide advisory pseudo-codes ("-EF", "---", ...) here too —
        // otherwise "ezeiza" or "cordoba" match the region-wide bulletin
        // instead of the actual airport of the same name.
        $normalizedMessage = $this->normalize($message);

        foreach ($airports as $code => $name) {
            if (! ctype_alpha($code)) {
                continue;
            }

            foreach (preg_split('/[\s\/]+/', $name) as $word) {
                $word = $this->normalize($word);

                if (mb_strlen($word) >= 4 && str_contains($normalizedMessage, $word)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $airports
     */
    protected function matchIndicatorWithAi(string $message, array $airports): ?string
    {
        if (blank(config('ai.providers.openrouter.key'))) {
            return null;
        }

        $list = collect($airports)
            ->filter(fn (string $name, string $code) => ctype_alpha($code))
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
     * One WhatsApp message per NOTAM, each prefixed with "(i/N)" (N = number
     * of NOTAMs) so a multi-NOTAM reply reads as a clearly numbered sequence
     * in the chat instead of one giant block of text.
     *
     * @param  array<int, array<string, mixed>>  $notams
     * @return array<int, string>
     */
    protected function formatNotams(string $airportName, string $indicator, array $notams): array
    {
        if ($notams === []) {
            return ["No hay NOTAM activos para *{$airportName}* ({$indicator}) en este momento. ✅"];
        }

        $total = count($notams);
        $header = "✈️ *{$airportName}* ({$indicator})";

        $messages = [];

        foreach ($notams as $i => $notam) {
            $vigencia = $notam['permanent']
                ? 'permanente'
                : "hasta {$notam['valid_until']} UTC";

            $lines = [
                $total > 1 ? '('.($i + 1)."/{$total}) {$header}" : $header,
                ($i + 1).". *{$notam['number']}*",
                $notam['decoded_es'] ?? $notam['text_en'],
                "🕐 Desde {$notam['valid_from']} UTC, {$vigencia}.",
            ];

            if ($i === $total - 1) {
                $lines[] = '';
                $lines[] = '_Fuente: ANAC Argentina_';
            }

            $message = implode("\n", $lines);

            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 1).'…';
            }

            $messages[] = $message;
        }

        return $messages;
    }

    protected function helpMessage(): string
    {
        return "¡Hola! 👋 Decime el aeropuerto que te interesa y te paso sus NOTAM activos.\n\n"
            ."Por ejemplo: _\"hay notams en Ezeiza?\"_ o _\"aeroparque\"_ o el código _\"EZE\"_.";
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = Str::ascii($text);

        return $text;
    }
}
