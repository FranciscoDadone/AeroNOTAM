<?php

namespace App\DataObjects;

use Illuminate\Support\Str;

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
 *
 * Unless the set does not fit in three, which is what $listLabel is for. An
 * aerodrome can have a dozen AIP documents, and WhatsApp's other shape — one
 * labelled button opening a sheet of up to ten rows — is the only way to offer
 * them. It is the same thing to everything downstream: same ids, same grammar,
 * same tap coming back, only drawn differently.
 */
final readonly class ReplyButton
{
    /**
     * How many rows WhatsApp draws in a list sheet.
     */
    public const MAX_LIST_ROWS = 10;

    /**
     * A list row's caption is capped shorter than a message's own text and
     * longer than a button's, with a second line underneath it for the rest.
     */
    protected const MAX_ROW_TITLE = 24;

    protected const MAX_ROW_DESCRIPTION = 72;

    /**
     * @param  array<int, array{id: string, title: string, description?: string}>  $buttons
     * @param  string|null  $listLabel  Non-null to render as a list sheet under a button with this caption.
     */
    public function __construct(public array $buttons, public ?string $listLabel = null) {}

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

    /**
     * "The other documents this aerodrome has in the AIP" — one row each,
     * drawn as a list because there are routinely more than three.
     *
     * The keys of $documents are their positions in what AipService returns for
     * the aerodrome, and that position is the whole payload: a download URL
     * embeds an AIRAC hash that will be wrong by next cycle, so a tap re-reads
     * the listing and takes the row again rather than carrying a link that
     * ages.
     *
     * A row's caption has room for a good deal less than an AIP title, so the
     * title is cut down to the part that distinguishes one document from
     * another — the AIP writes them as "family - specific document", and the
     * family is the same for every row here — with the whole thing repeated
     * underneath, where there is room for it.
     *
     * Past ten rows WhatsApp draws nothing at all, so the tail is dropped. It
     * is the tail of the AIP's own ordering, which runs from the aerodrome's
     * general documents to its individual procedures.
     *
     * @param  array<int, AipDocument>  $documents  Keyed by position in AipService::documentsFor().
     */
    public static function documents(array $documents): self
    {
        $rows = [];

        foreach (array_slice($documents, 0, self::MAX_LIST_ROWS, true) as $index => $document) {
            $rows[] = [
                'id' => "doc:{$document->icaoCode}:{$index}",
                'title' => Str::limit(self::documentLabel($document), self::MAX_ROW_TITLE - 1, '…'),
                'description' => Str::limit($document->title, self::MAX_ROW_DESCRIPTION - 1, '…'),
            ];
        }

        return new self($rows, '📄 Ver documentos');
    }

    /**
     * The part of an AIP title worth putting on a row.
     *
     * The AIP writes a title as segments running from the general to the
     * specific — "Cartas relativas al aeródromo - Carta de aproximación por
     * instrumentos - OACI - VOR RWY 19" — so the last of them is the one that
     * tells this document from the one under it, which are all that end up on
     * the same list. "OACI" is dropped along the way: it marks the chart series
     * and is on nearly every row, so as a caption it would distinguish nothing.
     *
     * A title written some other way is left as it is, and the whole of it goes
     * on the row's second line regardless.
     */
    protected static function documentLabel(AipDocument $document): string
    {
        $segments = array_filter(
            array_map('trim', explode(' - ', $document->title)),
            fn (string $segment) => $segment !== '' && mb_strtoupper($segment) !== 'OACI',
        );

        return $segments === [] ? $document->code : (string) end($segments);
    }
}
