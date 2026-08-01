<?php

namespace App\DataObjects;

use Illuminate\Support\Str;

/**
 * The one-tap actions offered under a WhatsApp message.
 *
 * Each one is an id we choose and a caption the reader sees. The id comes back
 * to us verbatim when the button is tapped, which is why the aerodrome travels
 * inside it — the tap needs no guessing about what the user meant.
 *
 * WhatsApp draws these two ways, and $listLabel is which. Null means buttons:
 * at most three per message, captions capped at twenty characters, visible
 * without opening anything — right for an offer or two riding on an answer,
 * like the watch and the runway components under a METAR. Non-null means a
 * list sheet: one labelled button opening up to ten rows, each with a second
 * line under its caption. That is the only shape that fits a set that outgrew
 * three — an aerodrome's AIP documents, and the follow-up menu now that there
 * are six topics to offer.
 *
 * It is the same thing to everything downstream either way: same ids, same
 * grammar, same tap coming back, only drawn differently.
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
     * "Watch this aerodrome for the next twelve hours."
     *
     * On its own. The runway components used to sit beside it, back when the
     * follow-up menu was three buttons with no room for them; now they are a
     * row of its sheet, offered on every answer about the aerodrome instead of
     * only under a METAR.
     *
     * The twelve is in the id as well as the caption: the two must not be able
     * to drift apart.
     */
    public static function subscribe(string $icaoCode): self
    {
        return new self([
            ['id' => "sub:{$icaoCode}:12", 'title' => '🔔 Avisarme 12 h'],
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
     * Every topic that can be reached with a tap, in the order they are
     * offered. The one just answered is dropped, so the menu is always this
     * list minus itself.
     *
     * It used to be three per topic, chosen by hand, because a message renders
     * three buttons and there was nowhere to put a fourth. Drawn as a list
     * sheet there is room for ten, so nothing has to be left out and no topic
     * is reachable only by knowing the words for it — which was the whole
     * problem with the AIP documents, the one thing nobody guesses they can
     * ask for.
     *
     * PRONAREA and AEROMET stay out, as they always have: neither is a
     * question about one aerodrome, so neither belongs under one.
     *
     * @var array<int, string>
     */
    protected const MENU_ORDER = ['notam', 'metar', 'pista', 'taf', 'carta', 'crepusculo', 'info'];

    /**
     * The caption each topic is offered under, and the line under it. A row's
     * caption has more room than a button's twenty characters, so these are
     * unchanged from when they were buttons and still fit.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const MENU_TITLES = [
        'notam' => ['✈️ NOTAMs', 'Avisos vigentes para el aeródromo'],
        'metar' => ['🌦️ METAR', 'El tiempo ahora'],
        'pista' => ['🛬 Viento en pista', 'Qué cabecera favorece el viento ahora'],
        'taf' => ['🔭 TAF', 'Pronóstico para las próximas horas'],
        'carta' => ['📄 Cartas AIP', 'Aproximación, plano de aeródromo y demás documentos'],
        'crepusculo' => ['🌅 Salida/Puesta sol', 'Orto, ocaso y crepúsculos de hoy'],
        'info' => ['🛬 Ficha del aeródromo', 'Pistas, elevación, servicios y ubicación'],
    ];

    /**
     * "Want anything else about this aerodrome?"
     *
     * $withCharts is false where the aerodrome has no ICAO code: the AIP
     * indexes its documents by that and nothing else, so offering the row
     * would promise something the tap could only answer with an apology. It is
     * passed in rather than guessed from $code, which holds the ANAC indicator
     * for exactly those aerodromes and is not always distinguishable by shape.
     *
     * $runwayWindId is the whole runway-wind row, or null for no row at all —
     * there is nothing to say for an aerodrome whose cabeceras are unknown.
     * The id arrives already built rather than assembled here, because that
     * offer has its own grammar (pista:, not ask:) and its own rule about which
     * code goes in it: the ICAO where there is one, the ANAC indicator where
     * AEROMET observes the locality instead. Building it twice is how the two
     * would drift.
     */
    public static function menu(string $topic, string $code, bool $withCharts = true, ?string $runwayWindId = null): self
    {
        $offers = array_filter(self::MENU_ORDER, fn (string $offer) => match ($offer) {
            $topic => false,
            'carta' => $withCharts,
            'pista' => $runwayWindId !== null,
            default => true,
        });

        $rows = array_map(
            fn (string $offer) => [
                'id' => $offer === 'pista' ? (string) $runwayWindId : "ask:{$offer}:{$code}",
                'title' => Str::limit(self::MENU_TITLES[$offer][0], self::MAX_ROW_TITLE - 1, '…'),
                'description' => Str::limit(self::MENU_TITLES[$offer][1], self::MAX_ROW_DESCRIPTION - 1, '…'),
            ],
            $offers,
        );

        // Slicing also reindexes, which the filter above made necessary: a
        // sheet with a gap in its keys serialises as an object, not an array.
        return new self(array_slice($rows, 0, self::MAX_LIST_ROWS), '📋 Más opciones');
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
