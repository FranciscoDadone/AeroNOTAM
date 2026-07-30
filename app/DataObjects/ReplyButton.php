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
     * "Watch this aerodrome for the next twelve hours" — and, on the same
     * message, "what is the wind doing to the runways?".
     *
     * Two actions rather than one because WhatsApp allows three per message and
     * the follow-up menu has already spent its three on the other topics. The
     * runway components are a question about the report just sent, so the
     * report is where the offer belongs.
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
            fallbackHint: "_Respondeme «avisame {$icaoCode}» y te aviso si cambia, o «viento en pista {$icaoCode}» para el componente en cada cabecera._",
        );
    }

    /**
     * "What is this wind doing to each runway?"
     *
     * Its own template because the one above cannot be sent to someone who is
     * already subscribed — the other button would offer them something they
     * already have — and this offer should not disappear just because the watch
     * one has nothing left to say.
     */
    public static function runwayWind(string $icaoCode): self
    {
        return new self(
            contentSid: (string) config('services.twilio.content_sid_pista'),
            payloadValue: $icaoCode,
            fallbackHint: "_Respondeme «viento en pista {$icaoCode}» y te paso el componente en cada cabecera._",
        );
    }

    /**
     * "Stop watching this aerodrome."
     *
     * Rides on every alert rather than only the first: an alert is unsolicited
     * by definition, so the way out of it travels with it rather than being
     * something the reader has to remember how to ask for.
     */
    public static function unsubscribe(string $icaoCode): self
    {
        return new self(
            contentSid: (string) config('services.twilio.content_sid_alert'),
            payloadValue: $icaoCode,
            fallbackHint: "_Respondeme «baja {$icaoCode}» para dejar de recibir estos avisos._",
        );
    }

    /**
     * "No METAR here, but AEROMET might have something." Offered under a METAR
     * that came back empty, for the aerodromes AEROMET also covers under the
     * same name — no promise the tap will find anything either, just a next
     * thing to try instead of a dead end.
     */
    public static function aeromet(string $code, string $stationName): self
    {
        return new self(
            contentSid: (string) config('services.twilio.content_sid_aeromet'),
            payloadValue: $code,
            fallbackHint: "_Respondeme «aeromet {$stationName}» y te paso lo que tenga el SMN de esa estación._",
        );
    }

    /**
     * "Want anything else about this aerodrome?" — one button offering one of
     * the other three topics.
     *
     * One template per topic already answered, because a quick-reply
     * template's captions are fixed when it is registered: the only thing that
     * varies at send time is the aerodrome, which rides in {{2}} and comes back
     * inside whichever action id was tapped.
     */
    public static function menu(string $topic, string $code): self
    {
        return new self(
            contentSid: (string) config("services.twilio.content_sid_menu_{$topic}"),
            payloadValue: $code,
            fallbackHint: self::menuHint($topic, $code),
        );
    }

    /**
     * The topics each menu offers, in a fixed order — NOTAM, METAR, TAF,
     * crepúsculo, minus the one just answered. Only the written fallback
     * reads this; the captions themselves live in whatsapp:content-templates,
     * because they are baked into the template and cannot be changed from
     * here.
     *
     * PRONAREA is deliberately not in here: it is not offered as a
     * quick-reply action, by design, so it never appears as an offer and has
     * no menu of its own.
     *
     * @var array<string, array<int, string>>
     */
    protected const MENU_OFFERS = [
        'notam' => ['metar', 'taf', 'crepusculo'],
        'metar' => ['notam', 'taf', 'crepusculo'],
        'taf' => ['notam', 'metar', 'crepusculo'],
        'crepusculo' => ['notam', 'metar', 'taf'],
    ];

    protected static function menuHint(string $topic, string $code): string
    {
        $commands = array_map(fn (string $offer) => "«{$offer} {$code}»", self::MENU_OFFERS[$topic]);
        $last = array_pop($commands);

        return '_También puedo pasarte '.implode(', ', $commands)." o {$last}._";
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
