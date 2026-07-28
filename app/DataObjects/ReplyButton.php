<?php

namespace App\DataObjects;

/**
 * A one-tap action offered under a WhatsApp message.
 *
 * WhatsApp has no free-text way to draw a button: it only renders one from a
 * pre-registered Twilio content template, referenced by SID and filled in with
 * variables at send time. That constraint is the whole shape of this class —
 * $contentSid says which template, $payloadValue is the one thing that varies
 * between sends (the aerodrome the button acts on, substituted into the
 * template's action id), and $fallbackHint is what to write instead when no
 * template has been registered.
 *
 * The fallback is not a degraded mode to be tolerated: the bot has always been
 * driven by writing to it, and the button only saves the typing. Anyone can
 * still type the hint, which is why it names a real command rather than
 * apologising.
 */
final readonly class ReplyButton
{
    public function __construct(
        public string $contentSid,
        public string $payloadValue,
        public string $fallbackHint,
    ) {}

    /**
     * "Watch this aerodrome for the next twelve hours."
     *
     * The twelve is baked into the template's action id rather than passed at
     * send time, because it is also the caption the user reads on the button —
     * the two must not be able to drift apart.
     */
    public static function subscribe(string $icaoCode): self
    {
        return new self(
            contentSid: (string) config('services.twilio.content_sid_metar'),
            payloadValue: $icaoCode,
            fallbackHint: "_Respondeme «avisame {$icaoCode}» y te aviso si cambia._",
        );
    }

    public static function unsubscribe(string $icaoCode): self
    {
        return new self(
            contentSid: (string) config('services.twilio.content_sid_alert'),
            payloadValue: $icaoCode,
            fallbackHint: "_Respondeme «baja {$icaoCode}» para dejar de recibir estos avisos._",
        );
    }

    /**
     * Whether the template this button needs has actually been registered.
     * Blank means the account has not run whatsapp:content-templates yet, and
     * the message goes out as text.
     */
    public function isAvailable(): bool
    {
        return $this->contentSid !== '';
    }

    /**
     * The template substitutions for one send: the message body, then the
     * aerodrome the button acts on.
     *
     * Numeric keys, because that is what PHP stores whatever they are written
     * as — and json_encode turns them back into the "1"/"2" strings Twilio's
     * ContentVariables expects.
     *
     * @return array<int, string>
     */
    public function variables(string $body): array
    {
        return [1 => $body, 2 => $this->payloadValue];
    }
}
