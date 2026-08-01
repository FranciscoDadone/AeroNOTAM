<?php

namespace App\DataObjects;

/**
 * The one-tap actions offered under a WhatsApp message.
 *
 * Each one is an id we choose and a caption the reader sees. The id comes back
 * to us verbatim when the button is tapped, which is why the aerodrome travels
 * inside it — the tap needs no guessing about what the user meant. The captions
 * are capped at twenty characters and WhatsApp renders at most three of them,
 * and that ceiling is the whole shape of this class: the follow-up menu has
 * already spent its three on the other topics, so anything else has to ride on
 * the answer itself.
 */
final readonly class ReplyButton
{
    /**
     * @param  array<int, array{id: string, title: string}>  $buttons
     */
    public function __construct(public array $buttons) {}

    /**
     * "Watch this aerodrome for the next twelve hours" — and, on the same
     * message, "what is the wind doing to the runways?".
     *
     * Two actions rather than one because WhatsApp allows three per message and
     * the follow-up menu has already spent its three on the other topics. The
     * runway components are a question about the report just sent, so the
     * report is where the offer belongs.
     *
     * The twelve is in the id as well as the caption: the two must not be able
     * to drift apart.
     */
    public static function subscribe(string $icaoCode): self
    {
        return new self([
            ['id' => "sub:{$icaoCode}:12", 'title' => '🔔 Avisarme 12 h'],
            ['id' => "pista:{$icaoCode}", 'title' => '🛬 Viento en pista'],
        ]);
    }

    /**
     * The same runway offer on its own, for the METAR of an aerodrome the
     * reader already watches: the pair above cannot be sent there, because its
     * other button would promise something that is already true.
     *
     * $code is the aerodrome's OACI code where it has one and its ANAC
     * indicator where it does not — the components are computed off AEROMET's
     * wind for those, so the offer is made there too, and both the button
     * grammar and AirportResolver::resolve() take either.
     */
    public static function runwayWind(string $code): self
    {
        return new self([
            ['id' => "pista:{$code}", 'title' => '🛬 Viento en pista'],
        ]);
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
        return new self([
            ['id' => "unsub:{$icaoCode}", 'title' => '🔕 Dar de baja'],
        ]);
    }

    /**
     * "No METAR here, but AEROMET might have something." Offered under a METAR
     * that came back empty — or under an aerodrome that has no ICAO code and
     * so will never have one — for the aerodromes AEROMET also covers under
     * the same name. No promise the tap will find anything either, just a next
     * thing to try instead of a dead end.
     *
     * The aerodrome rides along with the station code when there is one,
     * because it is not recoverable from the other side: a WMO/OMM code names
     * a station, and a station covers a locality that may hold three
     * aerodromes (Coronel Suárez has three) or none at all. Keeping it means
     * the AEROMET answer can offer the runway components for the aerodrome the
     * question was actually about, rather than for whichever one a name
     * lookup would land on.
     */
    public static function aeromet(string $code, string $stationName, ?string $anacCode = null): self
    {
        $payload = $anacCode === null ? $code : "{$code}:{$anacCode}";

        return new self([
            ['id' => "aeromet:{$payload}", 'title' => 'Consultar AEROMET'],
        ]);
    }

    /**
     * "Want anything else about this aerodrome?" — the other topics for the
     * same aerodrome, minus the one just answered.
     *
     * PRONAREA is deliberately absent: it is not offered as a quick-reply
     * action, by design. Crepúsculo is the one left out of the ficha's menu,
     * because WhatsApp renders three and it is the least likely next question
     * after "where is this place".
     *
     * @var array<string, array<int, string>>
     */
    protected const MENU_OFFERS = [
        'notam' => ['metar', 'taf', 'crepusculo'],
        'metar' => ['notam', 'taf', 'crepusculo'],
        'taf' => ['notam', 'metar', 'crepusculo'],
        'crepusculo' => ['notam', 'metar', 'taf'],
        'info' => ['notam', 'metar', 'taf'],
    ];

    /**
     * The caption each topic is offered under. Twenty characters is WhatsApp's
     * ceiling, and "🌅 Salida/Puesta sol" is the one that sits closest to it.
     *
     * @var array<string, string>
     */
    protected const MENU_TITLES = [
        'notam' => '✈️ NOTAMs',
        'metar' => '🌦️ METAR',
        'taf' => '🔭 TAF',
        'crepusculo' => '🌅 Salida/Puesta sol',
    ];

    public static function menu(string $topic, string $code): self
    {
        return new self(array_map(
            fn (string $offer) => [
                'id' => "ask:{$offer}:{$code}",
                'title' => self::MENU_TITLES[$offer],
            ],
            self::MENU_OFFERS[$topic],
        ));
    }
}
