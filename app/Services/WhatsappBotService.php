<?php

namespace App\Services;

use App\Ai\Agents\AirportMatcherAgent;
use App\DataObjects\AerometObservation;
use App\DataObjects\Metar;
use App\DataObjects\Notam;
use App\DataObjects\PronareaForecast;
use App\DataObjects\ReplyButton;
use App\DataObjects\ReplyContext;
use App\DataObjects\ReplyMenu;
use App\DataObjects\SunTimes;
use App\DataObjects\Taf;
use App\DataObjects\WhatsappReply;
use App\Models\Airport;
use App\Models\MetarSubscription;
use App\Models\Runway;
use App\Support\AerometStationResolver;
use App\Support\AirportResolver;
use App\Support\Compass;
use App\Support\MetarConditions;
use App\Support\PronareaFirResolver;
use App\Support\RunwayResolver;
use App\Support\RunwayWind;
use App\Support\SunCityResolver;
use App\Support\SurfaceWind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns an incoming WhatsApp message into the reply to send back.
 *
 * Two things have to be worked out from free text: which aerodrome the user
 * means, and what they want to know about it. The aerodrome is resolved once,
 * up front, and shared by both answers; the question then routes to the ficha
 * (the default — what the aerodrome *is*), to its NOTAMs, to the current METAR,
 * to the forecast, or to a standing "tell me when this changes" watch.
 *
 * A tapped button short-circuits all of that. The payload behind it is an
 * identifier this service emitted itself, so acting on it needs no keyword
 * matching, no name resolution and no model — see replyToButton().
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
     * A message that carries a button is not free text — it is a content
     * template, and WhatsApp caps a template body at 1024 characters. When a
     * button is going to be offered, the whole reply is split to this smaller
     * budget rather than only its last part: predicting which part ends up last
     * is not worth the one extra message it would occasionally save.
     */
    protected const MAX_TEMPLATE_BODY_LENGTH = 950;

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

    /**
     * Words that ask what time the sun does something, which is astronomy and
     * not weather.
     *
     * "orto" is deliberately absent even though it is the proper term: it lives
     * inside "aeropuerto", and matching is by substring.
     */
    protected const SUN_KEYWORDS = [
        'crepusculo', 'amanece', 'atardece', 'anochece', 'ocaso',
        'salida del sol', 'salida de sol', 'puesta del sol', 'puesta de sol',
        'sale el sol', 'se pone el sol', 'salida y puesta', 'puesta y salida',
        'luz diurna', 'primera luz', 'ultima luz',
    ];

    /**
     * Words that ask for PRONAREA, the SMN's area forecast by FIR.
     *
     * Checked before TAF_KEYWORDS for the same reason as the sun keywords:
     * "pronóstico de área" contains "pronostico", which alone reads as a TAF
     * request, and someone who spelled out the whole phrase means the FIR
     * bulletin specifically.
     */
    protected const PRONAREA_KEYWORDS = [
        'pronarea', 'pronostico de area', 'pronostico de la fir',
    ];

    /**
     * Words that ask for AEROMET, the SMN's wider observation network (it
     * also covers towns with no aerodrome — Azul, Ceres, Chepes).
     *
     * Checked alongside PRONAREA, for the same reason: nothing here is a
     * standing condition to watch, so it must not fall into the subscribe
     * keywords, and it names its own station rather than an ANAC aerodrome,
     * so it must not reach matchIndicator() either.
     */
    protected const AEROMET_KEYWORDS = [
        'aeromet',
    ];

    /**
     * Words that ask what the wind is doing to the runways, rather than what
     * the wind is.
     *
     * Checked before METAR_KEYWORDS, which contains "viento" and would swallow
     * every one of these — someone asking for the crosswind has already read
     * the wind and wants the next step.
     *
     * "pista" on its own is deliberately absent. Matching is by substring and
     * aerodromes carry the word in their own names — CORONEL SUÁREZ / LA PISTA,
     * EZEIZA / MINISTRO PISTARINI — so a bare "pista" would route "notams de la
     * pista" here and answer a question nobody asked.
     */
    protected const RUNWAY_WIND_KEYWORDS = [
        'viento cruzado', 'crosswind', 'componente de viento', 'componente del viento',
        'componente en pista', 'viento en pista', 'viento en la pista', 'pista en uso',
        'que pista uso', 'que pista conviene', 'que pista me conviene', 'cabecera favorecida',
    ];

    /**
     * Words that ask what an aerodrome *is* rather than what is happening at
     * it: where it is, how high, what its runways are made of, whether there
     * is fuel and who to call.
     *
     * Matched last of all the topics, which is what lets them be this broad.
     * "aeropuerto" is in here and appears in half the messages the bot gets —
     * "hay notams en el aeropuerto de Córdoba" reaches the NOTAM branch first
     * and never gets this far.
     */
    protected const INFO_KEYWORDS = [
        'info', 'informacion', 'datos', 'ficha', 'aerodromo', 'aeropuerto',
        'combustible', 'elevacion', 'telefono', 'contacto', 'superficie',
        'donde queda', 'donde esta', 'largo de pista',
    ];

    /**
     * The keywords that can decide each topic that goes on to resolve an
     * aerodrome — what withoutTopicWords() takes back out of the message.
     *
     * NOTAM has no entry because it has no list: it is matched on the bare
     * substring "notam", which no aerodrome in the registry carries in its
     * name. Nor do the topics that never reach the indicator matching at all
     * (crepúsculo, AEROMET, the subscription verbs).
     *
     * @var array<string, array<int, string>>
     */
    protected const TOPIC_KEYWORDS = [
        'pista' => self::RUNWAY_WIND_KEYWORDS,
        'metar' => self::METAR_KEYWORDS,
        'taf' => self::TAF_KEYWORDS,
        'pronarea' => self::PRONAREA_KEYWORDS,
        'info' => self::INFO_KEYWORDS,
    ];

    /**
     * How the SHN's month names are spelled in ordinary text, for a question
     * like "crepusculo en salta el 15 de agosto". Accent-stripped, since the
     * message reaching this map already went through Str::ascii().
     */
    protected const MONTH_NAMES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /**
     * Words that mean the user wants to be told about future changes rather
     * than about the weather right now.
     */
    protected const SUBSCRIBE_KEYWORDS = [
        'avisame', 'avisarme', 'avisa', 'avisar', 'suscrib', 'segui', 'seguir',
        'notific', 'alerta', 'monitorea', 'vigila', 'estar al tanto',
    ];

    /**
     * Checked before the subscribe words, not after: "no me avises más" contains
     * "avis", and reading it as a request to start would be the exact opposite
     * of what was asked.
     */
    protected const UNSUBSCRIBE_KEYWORDS = [
        'no me avises', 'no avises', 'dejar de', 'deja de', 'para de avisar',
        'desuscrib', 'dar de baja', 'baja', 'cancela', 'basta',
    ];

    /**
     * Checked before both of the above: "mis alertas" contains "alerta".
     */
    protected const LIST_KEYWORDS = [
        'mis alertas', 'mis suscripciones', 'mis avisos', 'que sigo',
        'que estoy siguiendo', 'que tengo activo',
    ];

    /**
     * The reply payloads behind the two buttons the bot offers. Emitted by us,
     * echoed back verbatim by WhatsApp, and therefore trusted — but still
     * matched strictly, because a webhook parameter is user-reachable input
     * whatever its provenance.
     */
    protected const BUTTON_SUBSCRIBE = '/^sub:([A-Z]{4}):(\d{1,2})$/';

    protected const BUTTON_UNSUBSCRIBE = '/^unsub:([A-Z]{4})$/';

    /**
     * A tap on the follow-up menu: the same five questions reply() itself can
     * answer, with the guessing removed. {3,4} because not every ANAC
     * aerodrome has an ICAO code (Alta Gracia, AGR) and its NOTAM still answer
     * fine without one.
     */
    protected const BUTTON_ASK = '/^ask:(notam|metar|taf|crepusculo|info):([A-Z]{3,4})$/';

    /**
     * A tap on the "Consultar AEROMET" offer under an empty METAR. The WMO/OMM
     * code rides on the button itself, so the tap needs no station-name
     * matching — it goes straight to AerometService with the code AEROMET was
     * already known to answer for.
     *
     * The aerodrome the offer was made under rides with it when there is one
     * (see ReplyButton::aeromet), which is what lets the answer offer the
     * runway components for that aerodrome and not for whichever one shares
     * the station's name. Optional, because the offer is also made where there
     * is no aerodrome behind it at all.
     */
    protected const BUTTON_AEROMET = '/^aeromet:(\d{5})(?::([A-Z]{3,4}))?$/';

    /**
     * A tap on the "Viento en pista" offer that rides under every METAR. Same
     * shape as BUTTON_ASK — the aerodrome comes back in the payload, so the tap
     * needs no matching — and {3,4} for the same reason.
     */
    protected const BUTTON_RUNWAY_WIND = '/^pista:([A-Z]{3,4})$/';

    /**
     * What the last call to reply() made of the message. Kept aside instead of
     * being returned with the reply because only the message log cares.
     */
    protected ReplyContext $context;

    public function __construct(
        protected AnacNotamService $anac,
        protected NotamEnricher $enricher,
        protected AirportResolver $airports,
        protected MetarService $metarService,
        protected MetarEnricher $metarEnricher,
        protected TafService $tafService,
        protected TafEnricher $tafEnricher,
        protected ShnSunService $sun,
        protected SunCityResolver $sunCities,
        protected SmnPronareaService $pronarea,
        protected PronareaFirResolver $pronareaFirs,
        protected AerometService $aeromet,
        protected AerometEnricher $aerometEnricher,
        protected AerometStationResolver $aerometStations,
        protected RunwayResolver $runways,
    ) {
        $this->context = new ReplyContext;
    }

    /**
     * Build the natural-language WhatsApp reply for an incoming message.
     *
     * @param  string|null  $from  Who is asking, in the provider's address form. Null off-channel
     *                             (the local test endpoint), where there is nobody to subscribe.
     * @param  string|null  $buttonPayload  The id behind a tapped button, if this message is one.
     */
    public function reply(string $message, ?string $from = null, ?string $buttonPayload = null): WhatsappReply
    {
        $this->context = new ReplyContext;

        if ($from !== null && $buttonPayload !== null) {
            $tapped = $this->replyToButton(trim($buttonPayload), $from);

            if ($tapped !== null) {
                return $tapped;
            }
        }

        $message = trim($message);

        if ($message === '') {
            return WhatsappReply::of($this->helpMessage());
        }

        $topic = $this->context->topic = $this->topic($message);

        // Resolves a city and not an aerodrome, so it never reaches the
        // indicator matching below.
        if ($topic === 'crepusculo') {
            return $this->sunReply($message, $from);
        }

        // Resolves an AEROMET station, which is not always an aerodrome
        // either (Azul, Ceres, Chepes have none), so this too never reaches
        // the indicator matching below.
        if ($topic === 'aeromet') {
            return $this->aerometReply($message);
        }

        if (in_array($topic, ['list', 'subscribe', 'unsubscribe'], true)) {
            return $from === null
                ? WhatsappReply::of('Las alertas sólo funcionan por WhatsApp, donde puedo escribirte cuando algo cambie.')
                : $this->subscriptionReply($topic, $message, $from);
        }

        // Without the topic's own words: they have already been read, and
        // leaving them in lets them name an aerodrome as well as a question.
        $named = $this->withoutTopicWords($message, $topic);

        $indicator = $this->context->anacCode = $this->matchIndicator($named);

        if ($indicator === null) {
            // Several aerodromes share the name the user typed (Córdoba has
            // three). Asking is the only honest answer — picking one silently
            // could send a pilot the wrong aerodrome's NOTAMs.
            $candidates = $this->airports->candidatesFromText($named);

            return WhatsappReply::of(count($candidates) > 1
                ? $this->disambiguationMessage($candidates)
                : $this->helpMessage());
        }

        return match ($topic) {
            'taf' => $this->tafReply($indicator, $from),
            'metar' => $this->metarReply($indicator, $from),
            'pista' => $this->runwayWindReply($indicator, $from),
            'pronarea' => $this->pronareaReply($indicator),
            'info' => $this->infoReply($indicator, $from),
            default => $this->notamReply($indicator, $from),
        };
    }

    /**
     * How the last message was interpreted. Valid straight after reply(),
     * including when it threw: whatever was worked out before the failure is
     * still worth logging.
     */
    public function lastContext(): ReplyContext
    {
        return $this->context;
    }

    /**
     * Act on a tapped button.
     *
     * Nothing here is inferred. The payload is a string this service put on the
     * button itself, so the aerodrome and the duration are read straight out of
     * it — no keywords, no name matching, no model. Returns null when the
     * payload is absent or malformed, which sends the message back through the
     * ordinary text path rather than failing.
     */
    protected function replyToButton(string $payload, string $from): ?WhatsappReply
    {
        if (preg_match(self::BUTTON_SUBSCRIBE, $payload, $m) === 1) {
            $this->context->topic = 'subscribe';

            return $this->subscribe($this->context->anacCode = $this->airports->resolve($m[1]), $from, (int) $m[2] * 3600);
        }

        if (preg_match(self::BUTTON_UNSUBSCRIBE, $payload, $m) === 1) {
            $this->context->topic = 'unsubscribe';

            return $this->unsubscribe($this->context->anacCode = $this->airports->resolve($m[1]), $from);
        }

        if (preg_match(self::BUTTON_ASK, $payload, $m) === 1) {
            $topic = $this->context->topic = $m[1];
            $indicator = $this->context->anacCode = $this->airports->resolve($m[2]);

            return match ($topic) {
                'metar' => $this->metarReply($indicator, $from),
                'taf' => $this->tafReply($indicator, $from),
                'crepusculo' => $this->sunReplyFor($indicator, $from),
                'info' => $this->infoReply($indicator, $from),
                default => $this->notamReply($indicator, $from),
            };
        }

        if (preg_match(self::BUTTON_AEROMET, $payload, $m) === 1) {
            $this->context->topic = 'aeromet';

            if (($m[2] ?? '') !== '') {
                $this->context->anacCode = $this->airports->resolve($m[2]);
            }

            return $this->aerometReplyForCode($m[1], $this->context->anacCode);
        }

        if (preg_match(self::BUTTON_RUNWAY_WIND, $payload, $m) === 1) {
            $this->context->topic = 'pista';

            return $this->runwayWindReply($this->context->anacCode = $this->airports->resolve($m[1]), $from);
        }

        return null;
    }

    /**
     * What the message is actually asking for.
     *
     * The sun is checked before everything else, subscriptions included. It is
     * the one topic with nothing to watch — an aerodrome's twilight for today is
     * already fixed — so "avisame a qué hora atardece en Neuquén" is a question,
     * not an alert. It also has to come before the forecast, because "a qué hora
     * anochece mañana" carries a TAF word.
     *
     * The subscription topics are checked next because they overlap with every
     * remaining one by design: "avisame si cambia el clima en EZE" contains a
     * METAR word, and answering it with today's observation would silently drop
     * the part the user actually asked for.
     *
     * "notam" wins over the rest when the word is there: someone who typed it
     * knows what they want, and "hay notams para mañana en EZE" must not be
     * answered with a forecast just because it mentions tomorrow.
     *
     * PRONAREA and AEROMET are checked right after the sun, and for the same
     * two reasons: neither has anything to watch (a FIR's current bulletin
     * and a station's current observation are not standing conditions to
     * subscribe to), and PRONAREA's keywords overlap with a TAF word the same
     * way the sun's overlap with one.
     *
     * The runway components sit with them, ahead of the METAR words, because
     * every one of their phrases contains "viento" and would otherwise be read
     * as a plain request for the observation — which is the question they are
     * one step past.
     *
     * The ficha goes last, and is also the default. Last because its words are
     * the ones that turn up inside every other kind of question — "hay notams
     * en el aeropuerto de Córdoba", "cómo está el clima en el aeródromo de
     * Bariloche" — and each of those has already been claimed by the branch
     * that reads it properly by the time this one is reached. Default because a
     * message that is just a place ("osa", "santa rosa") is asking what the
     * aerodrome is, not what is wrong with it today: NOTAMs answer a question
     * the reader has not asked yet, and the ficha carries a button to them.
     */
    protected function topic(string $message): string
    {
        $normalized = Str::ascii(mb_strtolower($message));

        return match (true) {
            $this->mentions($normalized, self::SUN_KEYWORDS) => 'crepusculo',
            $this->mentions($normalized, self::PRONAREA_KEYWORDS) => 'pronarea',
            $this->mentions($normalized, self::AEROMET_KEYWORDS) => 'aeromet',
            $this->mentions($normalized, self::RUNWAY_WIND_KEYWORDS) => 'pista',
            $this->mentions($normalized, self::LIST_KEYWORDS) => 'list',
            $this->mentions($normalized, self::UNSUBSCRIBE_KEYWORDS) => 'unsubscribe',
            $this->mentions($normalized, self::SUBSCRIBE_KEYWORDS) => 'subscribe',
            str_contains($normalized, 'notam') => 'notam',
            preg_match('/\btaf\b/', $normalized) === 1 => 'taf',
            $this->mentions($normalized, self::TAF_KEYWORDS) => 'taf',
            $this->mentions($normalized, self::METAR_KEYWORDS) => 'metar',
            $this->mentions($normalized, self::INFO_KEYWORDS) => 'info',
            default => 'info',
        };
    }

    /**
     * The message with the words that named the topic taken out of it.
     *
     * A handful of aerodromes carry ordinary aviation vocabulary in their own
     * names — CORONEL SUÁREZ / LA PISTA, and the two whose names contain
     * "AEROPUERTO" or "ÁREA" — and AirportResolver matches a name on any word
     * of four letters or more. So "viento en pista osa" used to answer for
     * Coronel Suárez: "pista" matched its name, while "osa" was ignored for
     * being an ambiguous code typed in lower case. The topic words have already
     * been read by the time this runs; letting them name a place too is what
     * turned a question about Santa Rosa into an answer about somewhere else.
     *
     * Matching is by whole token against the same normalization the resolvers
     * use, never by substring: a keyword must not be able to eat part of a name
     * it merely appears inside, and dropping a token whole is what keeps the
     * rest of the message — capitals included, which is how an ambiguous code
     * earns its match — exactly as it was typed.
     */
    protected function withoutTopicWords(string $message, string $topic): string
    {
        $tokens = preg_split('/\s+/', trim($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = array_map($this->normalizeWord(...), $tokens);

        foreach (self::TOPIC_KEYWORDS[$topic] ?? [] as $keyword) {
            $words = preg_split('/\s+/', $keyword) ?: [];

            for ($i = 0; $i + count($words) <= count($tokens); $i++) {
                if (array_slice($normalized, $i, count($words)) === $words) {
                    array_splice($tokens, $i, count($words), array_fill(0, count($words), ''));
                }
            }
        }

        return trim(implode(' ', array_filter($tokens, fn (string $token) => $token !== '')));
    }

    /**
     * One token, stripped of accents, case and the punctuation it may have been
     * typed against ("¿viento en pista, osa?") — the form the keyword lists are
     * already written in.
     */
    protected function normalizeWord(string $token): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($token)));
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
     * The three topics that act on a standing watch rather than answering a
     * question. All of them need to know who is asking, which is why they are
     * split off from reply() behind a non-null $from.
     */
    protected function subscriptionReply(string $topic, string $message, string $from): WhatsappReply
    {
        if ($topic === 'list') {
            return $this->listReply($from);
        }

        $indicator = $this->context->anacCode = $this->matchIndicator($message);

        if ($indicator === null) {
            return $topic === 'unsubscribe'
                ? $this->unsubscribeWithoutAerodrome($from, $message)
                : WhatsappReply::of($this->helpMessage());
        }

        if ($topic === 'unsubscribe') {
            return $this->unsubscribe($indicator, $from);
        }

        // Only observations are watched. Saying so beats quietly setting up a
        // METAR alert for someone who asked about NOTAMs and will never
        // understand why the messages that arrive are about the wind.
        if (str_contains(Str::ascii(mb_strtolower($message)), 'notam')) {
            return WhatsappReply::of(
                'Por ahora sólo puedo avisarte cuando cambia el *METAR* de un aeródromo, no sus NOTAM. '
                .'Pedime _"avisame EZE"_ si querés la alerta del estado del tiempo.'
            );
        }

        return $this->subscribe($indicator, $from, $this->requestedSeconds($message));
    }

    /**
     * Start (or renew) a watch on an aerodrome.
     *
     * The current observation is fetched before anything is written, for two
     * reasons: it is what the user gets back as confirmation, so they can see
     * the point the comparison starts from — and it is the baseline itself. A
     * subscription created without one would have nothing to compare against on
     * the first round, and would either alert on everything or on nothing.
     */
    protected function subscribe(string $indicator, string $from, int $seconds): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        if ($icao === null) {
            return WhatsappReply::of(
                "*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica METAR para ese aeródromo y no hay nada que vigilar."
            );
        }

        $existing = $this->subscriptions($from);

        if (! $existing->has($indicator) && $existing->count() >= $this->maxPerPhone()) {
            return WhatsappReply::of($this->toManySubscriptionsMessage($existing));
        }

        try {
            $metars = $this->metarService->getMetars($icao);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('No pude leer el METAR actual, así que todavía no puedo activar la alerta. Probá de nuevo en unos minutos.');
        }

        if ($metars === []) {
            return WhatsappReply::of(
                "No hay METAR publicado para *{$name}* ({$icao}) en este momento, así que no tengo desde dónde comparar. Probá más tarde."
            );
        }

        $subscription = MetarSubscription::updateOrCreate(
            ['phone' => $from, 'anac_code' => $indicator],
            [
                'icao_code' => $icao,
                'expires_at' => now()->addSeconds($seconds),
                'last_raw' => $metars[0]->raw,
                // Cleared on renewal: the watch starts again from the report
                // just shown, so what was sent under the previous one says
                // nothing about this one.
                'last_notified_at' => null,
            ],
        );

        $confirmation = "✅ Listo. Te aviso si cambia el METAR de *{$name}* ({$icao}).\n"
            ."La alerta vence el {$subscription->expiryLabel()}. Este es el punto de partida:";

        $reply = $this->formatMetars($name, $icao, $this->metarEnricher->enrich($metars));

        return WhatsappReply::ofMany([$confirmation, ...$reply->messages]);
    }

    protected function unsubscribe(string $indicator, string $from): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;

        $removed = MetarSubscription::query()
            ->forPhone($from)
            ->where('anac_code', $indicator)
            ->delete();

        return WhatsappReply::of($removed > 0
            ? "🔕 Listo, no te aviso más sobre *{$name}* ({$indicator})."
            : "No tenías ninguna alerta activa para *{$name}* ({$indicator}).");
    }

    /**
     * "Dame de baja" with no aerodrome named.
     *
     * With one watch running there is nothing to ask about; with several there
     * is, and guessing which one to cancel would silence exactly the aerodrome
     * the user still wanted.
     */
    protected function unsubscribeWithoutAerodrome(string $from, string $message): WhatsappReply
    {
        $subscriptions = $this->subscriptions($from);

        if ($subscriptions->isEmpty()) {
            return WhatsappReply::of('No tenés ninguna alerta activa en este momento.');
        }

        $normalized = Str::ascii(mb_strtolower($message));

        if (str_contains($normalized, 'todo') || str_contains($normalized, 'todas')) {
            MetarSubscription::query()->forPhone($from)->delete();

            return WhatsappReply::of('🔕 Listo, di de baja todas tus alertas ('.$subscriptions->count().').');
        }

        if ($subscriptions->count() === 1) {
            return $this->unsubscribe((string) $subscriptions->keys()->first(), $from);
        }

        $lines = ['¿De cuál querés darte de baja?', ''];

        foreach ($subscriptions as $subscription) {
            $lines[] = "• *{$subscription->anac_code}* — ".($this->airports->nameFor($subscription->anac_code) ?? $subscription->anac_code);
        }

        $lines[] = '';
        $lines[] = 'Respondeme _"baja EZE"_ con el código, o _"baja todas"_.';

        return WhatsappReply::of(implode("\n", $lines));
    }

    protected function listReply(string $from): WhatsappReply
    {
        $subscriptions = $this->subscriptions($from);

        if ($subscriptions->isEmpty()) {
            return WhatsappReply::of(
                "No tenés alertas activas.\n\n"
                .'Pedime el METAR de un aeródromo (_"metar EZE"_) y tocá el botón para que te avise cuando cambie.'
            );
        }

        $lines = ['🔔 *Tus alertas de METAR*', ''];

        foreach ($subscriptions as $subscription) {
            $name = $this->airports->nameFor($subscription->anac_code) ?? $subscription->anac_code;
            $lines[] = "• *{$name}* ({$subscription->icao_code}) — hasta el {$subscription->expiryLabel()}";
        }

        $lines[] = '';
        $lines[] = 'Para dar de baja alguna, respondeme _"baja EZE"_ con su código.';

        return WhatsappReply::of(implode("\n", $lines));
    }

    /**
     * This number's active watches, keyed by ANAC code.
     *
     * @return Collection<string, MetarSubscription>
     */
    protected function subscriptions(string $from): Collection
    {
        return MetarSubscription::query()
            ->forPhone($from)
            ->active()
            ->orderBy('anac_code')
            ->get()
            ->keyBy('anac_code');
    }

    /**
     * @param  Collection<string, MetarSubscription>  $existing
     */
    protected function toManySubscriptionsMessage(Collection $existing): string
    {
        $lines = [
            "Ya tenés {$existing->count()} alertas activas, que es el máximo. Dame de baja alguna y volvé a intentar:",
            '',
        ];

        foreach ($existing as $subscription) {
            $name = $this->airports->nameFor($subscription->anac_code) ?? $subscription->anac_code;
            $lines[] = "• *{$subscription->anac_code}* — {$name}";
        }

        $lines[] = '';
        $lines[] = 'Respondeme _"baja EZE"_ con el código que quieras sacar.';

        return implode("\n", $lines);
    }

    /**
     * How long the user asked the watch to last, in seconds — "avisame EZE por
     * 6 horas". Capped rather than refused, because the ceiling exists for a
     * reason the user cannot be expected to know: past 24 hours WhatsApp stops
     * letting us write to them at all.
     */
    protected function requestedSeconds(string $message): int
    {
        $default = (int) config('services.metar.watch.default_ttl');
        $max = (int) config('services.metar.watch.max_ttl');

        $normalized = Str::ascii(mb_strtolower($message));

        if (preg_match('/\b(?:por|durante)\s+(\d{1,2})\s*(?:h|hs|hora|horas)\b/', $normalized, $m) !== 1) {
            return min($default, $max);
        }

        return min(max((int) $m[1], 1) * 3600, $max);
    }

    protected function maxPerPhone(): int
    {
        return (int) config('services.metar.watch.max_per_phone');
    }

    protected function notamReply(string $indicator, ?string $from): WhatsappReply
    {
        try {
            $notams = $this->anac->getNotams($indicator);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('Encontré el aeropuerto pero no pude obtener sus NOTAM en este momento. Probá de nuevo en unos minutos.');
        }

        return $this->formatNotams(
            $this->airports->nameFor($indicator) ?? $indicator,
            $indicator,
            $this->enricher->enrich($notams),
            $this->airports->isClosed($indicator),
            $this->runwayWindOffer($indicator),
        )->withMenu($this->menuFor('notam', $indicator, $from));
    }

    /**
     * The runway-wind button to hang under an answer about this aerodrome, or
     * null when there would be nothing behind it.
     *
     * Unlike the offer under a METAR — which shares a template with the watch
     * button and therefore cannot be dropped on its own — this one stands
     * alone, so it is only sent when it will actually answer something. That
     * matters twice over: a button leading to "no tengo los rumbos de pista"
     * is worse than no button, and a message carrying one is split to the
     * smaller template budget, which would cost extra messages for nothing.
     */
    protected function runwayWindOffer(string $indicator): ?ReplyButton
    {
        if (! $this->runways->has($indicator)) {
            return null;
        }

        $icao = $this->airports->icaoFor($indicator);

        if ($icao !== null) {
            return ReplyButton::runwayWind($icao);
        }

        // No ICAO code means no METAR will ever exist — but the locality may
        // still be one AEROMET observes, and that is the wind runwayWindReply()
        // computes against when there is no METAR. So the offer is honest here
        // too, and the payload carries the ANAC code instead: airports->resolve()
        // takes either, and the button grammar has always allowed three letters.
        $name = $this->airports->nameFor($indicator);

        return $name !== null && $this->aerometStations->codeForName($name, $indicator) !== null
            ? ReplyButton::runwayWind($indicator)
            : null;
    }

    /**
     * The "Consultar AEROMET" offer for an aerodrome, or null when AEROMET
     * does not cover its locality.
     *
     * The button is captioned and hinted with AEROMET's own station name
     * rather than the aerodrome's, because that is the name the answer will
     * come back under and the one a typed "aeromet …" has to name to reach it.
     */
    protected function aerometOffer(string $indicator, string $name): ?ReplyButton
    {
        $code = $this->aerometStations->codeForName($name, $indicator);

        return $code === null
            ? null
            : ReplyButton::aeromet($code, $this->aerometStations->nameFor($code), $indicator);
    }

    protected function metarReply(string $indicator, ?string $from): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        // Not every ANAC aerodrome has an ICAO code, and the SMN indexes
        // observations by that code alone. Saying so is more useful than a
        // generic failure, because retrying will never help — and where
        // AEROMET covers the locality, there is a real next thing to try
        // rather than only an explanation of why there is nothing.
        if ($icao === null) {
            return WhatsappReply::ofMany(
                ["*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica METAR para ese aeródromo."],
                $this->aerometOffer($indicator, $name),
            );
        }

        try {
            $metars = $this->metarService->getMetars($icao);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('Encontré el aeropuerto pero no pude obtener su METAR en este momento. Probá de nuevo en unos minutos.');
        }

        return $this->formatMetars(
            $name,
            $icao,
            $this->metarEnricher->enrich($metars),
            $this->watchOffer($indicator, $icao, $from, $metars !== []),
        )->withMenu($this->menuFor('metar', $indicator, $from));
    }

    /**
     * What goes under an observation: the buttons to offer, and the line of
     * text that replaces the watch offer once a watch is already running.
     *
     * Offering the watch button to someone who is already subscribed would be a
     * promise about something already true — worse than useless, because
     * tapping it would look like it had failed to change anything. The runway
     * components are not like that: they answer a question about the report
     * just sent, and stay on offer either way. So there are two templates, and
     * which one is sent turns on whether the watch offer still has anything to
     * say.
     *
     * The runway button is offered without first checking that the aerodrome
     * has runways on file — it cannot be, since the two actions live in one
     * template and a template's buttons are fixed when it is registered. That
     * is the same bargain the AEROMET offer makes: the tap is a next thing to
     * try, and runwayWindReply() says so plainly when there is nothing behind
     * it.
     *
     * @return array{0: ?ReplyButton, 1: ?string}
     */
    protected function watchOffer(string $indicator, string $icao, ?string $from, bool $hasReport): array
    {
        if ($from === null || ! $hasReport) {
            return [null, null];
        }

        $existing = MetarSubscription::query()
            ->forPhone($from)
            ->active()
            ->where('anac_code', $indicator)
            ->first();

        return $existing === null
            ? [ReplyButton::subscribe($icao), null]
            : [ReplyButton::runwayWind($icao), "🔔 _Ya te estoy avisando de los cambios acá, hasta el {$existing->expiryLabel()}._"];
    }

    /**
     * What the current wind is doing to each runway end.
     *
     * This is the step a METAR stops one short of. It reports "35015G25KT" and
     * leaves the reader to work out, for each cabecera, how much of that lands
     * along the runway and how much across it — which is the number that
     * actually decides where to land.
     *
     * Four ways this can honestly have no answer, in the order they are worth
     * checking: no runways on file, no OACI code *and* no AEROMET station
     * covering the locality, nothing published right now by either, and a wind
     * with no direction to measure against. Each says so rather than producing
     * a number.
     *
     * An aerodrome with no OACI code is no longer a dead end on its own. The
     * SMN publishes METAR by ICAO code and nothing else, but it also observes
     * some 119 AEROMET stations by locality — so where the locality is one of
     * them, the components are computed off that wind instead. It is a
     * measurement from somewhere else in the same town, which the answer says
     * out loud; the alternative was telling a pilot standing on a runway that
     * nothing at all is known about the wind over it.
     */
    protected function runwayWindReply(string $indicator, ?string $from): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);
        $code = $icao ?? $indicator;
        $runways = $this->runways->forAnacCode($indicator);

        if ($runways === []) {
            $lead = "No tengo los rumbos de pista de *{$name}* ({$code}), así que no puedo calcular el componente.";

            return $icao !== null
                ? WhatsappReply::of("{$lead} Pedime el METAR y te paso el viento tal como lo informa el SMN.")
                : WhatsappReply::ofMany([$lead], $this->aerometOffer($indicator, $name));
        }

        if ($icao !== null) {
            try {
                $metars = $this->metarService->getMetars($icao);
            } catch (\Throwable $e) {
                report($e);

                return WhatsappReply::of('Encontré el aeropuerto pero no pude obtener su METAR en este momento. Probá de nuevo en unos minutos.');
            }

            // The most recent observation only. A crosswind from an hour ago is
            // not a crosswind, and unlike the METAR reply — where older reports
            // show a trend — there is nothing to be learnt here from stacking
            // them.
            if ($metars !== []) {
                return $this->formatRunwayWind($name, $icao, $runways, $metars[0])
                    // The METAR menu, not one of its own: the other three topics
                    // are exactly what is worth offering next, and a fifth
                    // quick-reply template could not be rendered anyway.
                    ->withMenu($this->menuFor('metar', $indicator, $from));
            }
        }

        return $this->aerometRunwayWindReply($indicator, $name, $icao, $runways, $from);
    }

    /**
     * The components computed off AEROMET's observation for the locality —
     * what answers when the METAR network either does not reach this aerodrome
     * or has nothing published for it right now.
     *
     * A failed AEROMET fetch is reported the same way an empty one is, rather
     * than as its own error: the question was about the wind on a runway, and
     * "el SMN no responde" is a truthful answer to a question nobody asked.
     * The exception is still reported for the logs.
     *
     * @param  array<int, Runway>  $runways
     */
    protected function aerometRunwayWindReply(string $indicator, string $name, ?string $icao, array $runways, ?string $from): WhatsappReply
    {
        $code = $this->aerometStations->codeForName($name, $indicator);

        if ($code !== null) {
            try {
                $observations = $this->aeromet->getObservations($code);
            } catch (\Throwable $e) {
                report($e);

                $observations = [];
            }

            if ($observations !== []) {
                return $this->formatAerometRunwayWind(
                    $name,
                    $icao ?? $indicator,
                    $runways,
                    $observations[0],
                    $this->aerometStations->nameFor($code),
                )->withMenu($this->menuFor('metar', $indicator, $from));
            }
        }

        return WhatsappReply::of($icao === null
            ? "*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica METAR y no tengo viento con el que calcular el componente en pista."
            : "No hay METAR publicado para *{$name}* ({$icao}) en este momento, así que no hay viento con el que calcular el componente en pista.");
    }

    /**
     * @param  array<int, Runway>  $runways
     */
    protected function formatRunwayWind(string $airportName, string $icao, array $runways, Metar $metar): WhatsappReply
    {
        $header = "🛬 *{$airportName}* ({$icao})";
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;
        $wind = SurfaceWind::fromMetar(MetarConditions::fromRaw($metar->raw));

        $lines = [
            '```'.($wind->group ?? $metar->raw).'```',
            ...$this->runwayWindLines($runways, $wind, 'No pude leer el grupo de viento de este METAR, así que no puedo calcular el componente.'),
            '',
            $this->sourceCredit($metar->isRelayed()),
        ];

        return WhatsappReply::ofMany(
            $this->withHeader($header, $this->splitToFit(implode("\n", $lines), $budget)),
        );
    }

    /**
     * The same message, computed off a SYNOP from the locality's AEROMET
     * station instead of the aerodrome's own METAR.
     *
     * Where the wind was measured leads the message rather than trailing it as
     * a footnote. A component figure carries an implied "here", and this one is
     * from a station somewhere else in the same locality — a reader who takes
     * it for an on-field observation has been misled, so it is said before the
     * numbers, not after them.
     *
     * @param  array<int, Runway>  $runways
     */
    protected function formatAerometRunwayWind(string $airportName, string $code, array $runways, AerometObservation $observation, string $stationName): WhatsappReply
    {
        $header = "🛬 *{$airportName}* ({$code})";
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;

        $lines = [
            $observation->stale
                ? "⚠️ Sin METAR acá: el componente sale del viento de la estación AEROMET *{$stationName}*, y es la última observación que obtuve, de las {$observation->observedAt} UTC."
                : "📍 Sin METAR acá: el componente sale del viento de la estación AEROMET *{$stationName}*, observación de las {$observation->observedAt} UTC.",
            '',
            '```'.$observation->raw.'```',
            ...$this->runwayWindLines($runways, SurfaceWind::fromSynop($observation->raw), 'No pude leer el viento de esa observación, así que no puedo calcular el componente.'),
            '',
            $this->sourceCredit(false),
        ];

        return WhatsappReply::ofMany(
            $this->withHeader($header, $this->splitToFit(implode("\n", $lines), $budget)),
        );
    }

    /**
     * The wind in words, then one line per cabecera, best first.
     *
     * Calm and variable are not failures to report a wind — they are the
     * report. Naming a favoured runway off either would be inventing a
     * preference the atmosphere does not have, so each ends the message on its
     * own line instead. So does an unreadable wind group, in whatever words the
     * caller's own report deserves.
     *
     * The gust figures ride only under the favoured runway. They are the number
     * a crosswind limit is actually checked against, so they have to be there —
     * but repeating them for every end would double a message whose whole point
     * is to be read at a glance.
     *
     * @param  array<int, Runway>  $runways
     * @param  SurfaceWind|null  $wind  Null when the report carried no wind group at all.
     * @param  string  $unreadable  What to say when there is no speed to work from.
     * @return array<int, string>
     */
    protected function runwayWindLines(array $runways, ?SurfaceWind $wind, string $unreadable): array
    {
        if ($wind === null || $wind->speed === null) {
            return [$unreadable];
        }

        if ($wind->speed === 0) {
            return ['Viento en calma: no hay componente que calcular ni cabecera favorecida.'];
        }

        if ($wind->direction === null) {
            return ["Viento variable a {$wind->speed} kt: sin dirección fija no hay una cabecera favorecida."];
        }

        // A northerly is reported as 360, not 000 — SurfaceWind normalises it
        // away for the arithmetic, and 000 is the code for calm, which this
        // branch has already been ruled out of.
        $direction = str_pad((string) ($wind->direction ?: 360), 3, '0', STR_PAD_LEFT);
        $gustNote = $wind->gust === null ? '' : ", ráfagas {$wind->gust} kt";

        $lines = [
            "Viento del {$direction}° (".Compass::name($wind->direction).") a {$wind->speed} kt{$gustNote}",
            '',
        ];

        $components = RunwayWind::components(
            $runways,
            $wind->direction,
            $wind->speed,
            $wind->gust,
        );

        $favoured = RunwayWind::favoured($components);

        foreach ($components as $component) {
            $isFavoured = $component === $favoured;

            $lines[] = ($component->isClosed ? '⛔' : ($isFavoured ? '✅' : '  '))
                ." RWY {$component->designator} — "
                .($component->isClosed ? 'cerrada — ' : '')
                .$this->componentPhrase($component->headwind, $component->crosswind, $component->side);

            if ($isFavoured && $component->gustHeadwind !== null && $component->gustCrosswind !== null) {
                $lines[] = '     con ráfaga: '
                    .$this->componentPhrase($component->gustHeadwind, $component->gustCrosswind, $component->side);
            }
        }

        return $lines;
    }

    /**
     * "de frente 15 kt · cruzado 1 kt (izq)".
     *
     * The side is dropped when the crosswind is zero: the wind is straight down
     * the runway, and naming a side would invent a distinction the arithmetic
     * did not make.
     */
    protected function componentPhrase(int $headwind, int $crosswind, string $side): string
    {
        $along = $headwind < 0
            ? 'de cola '.abs($headwind)
            : "de frente {$headwind}";

        return "{$along} kt · cruzado {$crosswind} kt".($crosswind === 0 ? '' : " ({$side})");
    }

    /**
     * What the aerodrome *is*, which is the question a message that names a
     * place and asks nothing else is really asking.
     *
     * Nearly everything here comes off the local tables —
     * notams:import-airport-details and notams:import-runways put it there —
     * and the one thing that does not, today's sun, is fetched best-effort and
     * left out when it cannot be had. The ficha answers a question about the
     * place itself, so it must not be able to fail because a website was down.
     *
     * The runway-wind offer rides at the foot of it for the same reason it
     * rides under a NOTAM: the ficha has just listed the cabeceras, and "which
     * of these does the wind favour right now" is the next thing somebody
     * looking at that list wants. Better still here than anywhere else, since
     * the offer is only sent when there are runways on file — which is exactly
     * when the ficha has a list to have prompted the question.
     */
    protected function infoReply(string $indicator, ?string $from): WhatsappReply
    {
        $airport = $this->airports->find($indicator);

        if ($airport === null) {
            return WhatsappReply::of($this->helpMessage());
        }

        return $this->formatAirportInfo(
            $airport,
            $this->runways->forAnacCode($indicator),
            $this->runwayWindOffer($indicator),
            $this->sunTimesFor($indicator),
        )->withMenu($this->menuFor('info', $indicator, $from));
    }

    /**
     * Today's sun over an aerodrome, or null when there is none to be had.
     *
     * Three ways there is none, and the ficha treats them alike because for the
     * reader they are the same thing — no times printed: the SHN publishes by
     * city and covers 34 of them, so most aerodromes bridge to none; a city it
     * covers can still have no row for today; and the site itself can be down.
     * Only the last is worth a log, and none of the three is worth failing the
     * ficha over, which is the whole reason this swallows rather than throws.
     */
    protected function sunTimesFor(string $indicator): ?SunTimes
    {
        $city = $this->sunCities->cityFor($indicator);

        if ($city === null) {
            return null;
        }

        try {
            return $this->sun->forDate($city, $this->sunToday());
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The ficha.
     *
     * One rule governs the whole thing: a field MADHEL does not publish is
     * reported as unpublished, never as absent. That an aerodrome has no fuel
     * line in the registry says nothing about whether there is a pump on the
     * apron — MADHEL publishes fuel for barely one aerodrome in seven — and a
     * pilot planning a leg on "no tiene combustible" would be planning it on
     * something nobody ever said. Same rule NotamEnricher and AerometService
     * already work under.
     *
     * The distinction that makes that honest is between a field MADHEL left
     * empty and a ficha that was never imported at all. Only the first can be
     * called "sin dato publicado"; the second is our own gap, and says so.
     *
     * @param  array<int, Runway>  $runways
     * @param  ReplyButton|null  $button  The runway-wind offer, when there is anything behind it.
     * @param  SunTimes|null  $sun  Today's sun, when the SHN covers this aerodrome's city.
     */
    protected function formatAirportInfo(Airport $airport, array $runways, ?ReplyButton $button = null, ?SunTimes $sun = null): WhatsappReply
    {
        $header = ($airport->kind === 'HLP' ? '🚁' : '🛬')." *{$airport->name}*";

        // A message that carries a button is a content template, and WhatsApp
        // caps those far shorter than a plain one. It bites here more than
        // anywhere: an aerodrome with three runways and a paragraph of opening
        // hours is a long ficha.
        $budget = ($button === null ? self::MAX_MESSAGE_LENGTH : self::MAX_TEMPLATE_BODY_LENGTH)
            - mb_strlen($header) - 12;

        $lines = ['_'.$this->airportSubtitle($airport).'_'];

        if ($airport->is_closed) {
            $lines[] = '⛔ *Aeródromo cerrado*';
        }

        $lines[] = '';
        $lines = array_merge($lines, $this->airportLocationLines($airport));

        $lines[] = '';
        $lines[] = '*Pistas*';
        $lines = array_merge($lines, $this->runwayLines($runways));

        $lines[] = '';
        $lines = array_merge($lines, $this->airportServiceLines($airport));

        if ($sun !== null) {
            $lines[] = '';
            $lines = array_merge($lines, $this->sunLines($sun));
        }

        if ($airport->is_aip_delegated) {
            $lines[] = '';
            // Once notams:import-aip-details has actually read that record,
            // pointing the reader at the AIP themselves is redundant with the
            // lines above, which already come from it — a short attribution
            // says so instead of sending them to fetch what they just read.
            $lines[] = $airport->aip_details_updated_at === null
                ? '_MADHEL remite a la AIP para este aeródromo: ais.anac.gob.ar/aip_'
                : '_Combustible, teléfono, horario y frecuencia según la AIP._';
        }

        return WhatsappReply::ofMany(
            $this->withHeader($header, $this->splitToFit(implode("\n", $lines), $budget)),
            $button,
        );
    }

    /**
     * Salida and puesta, the two the ficha has room for.
     *
     * The other two moments a full crepúsculo answer carries — matutino and
     * vespertino, the legal edges of the day — are left to it: they are what
     * somebody planning against last light asks for on purpose, and the ficha
     * is answering "what is this place", where the sun is one fact among a
     * dozen rather than the question.
     *
     * The city is named on the header line because it is not the aerodrome.
     * The SHN publishes by locality, and Ezeiza's ficha reading "BUENOS AIRES"
     * is the honest version of a table that never mentioned Ezeiza — the
     * minute of difference does not matter, but hiding where the number came
     * from does.
     *
     * @return array<int, string>
     */
    protected function sunLines(SunTimes $times): array
    {
        $lines = ["*Sol de hoy* — _SHN, {$times->city}_"];

        foreach ([['sunrise', 'Salida', $times->sunrise], ['sunset', 'Puesta', $times->sunset]] as [$moment, $label, $at]) {
            $lines[] = $at === null
                ? "• {$label}: {$this->sunSymbolMeaning($times->symbolFor($moment))}"
                : sprintf(
                    '• %s: %s UTC (%s local)',
                    $label,
                    $at->format('H:i'),
                    $at->setTimezone(ShnSunService::OFFSET)->format('H:i'),
                );
        }

        return $lines;
    }

    /**
     * "Aeródromo público controlado · OSA / SAZR / RSA" — what kind of place
     * this is, then every name it answers to.
     */
    protected function airportSubtitle(Airport $airport): string
    {
        $words = [$airport->kind === 'HLP' ? 'Helipuerto' : 'Aeródromo'];

        // Null when MADHEL does not classify it, which is not the same as
        // private — so nothing is said rather than something guessed.
        $words[] = match ($airport->access) {
            'publico' => 'público',
            'privado' => 'privado',
            'militar' => 'militar',
            default => null,
        };

        $words[] = $airport->is_controlled ? 'controlado' : 'no controlado';

        // Deduplicated: ANAC and IATA agree at a good few aerodromes (EZE is
        // both), and "EZE / SAEZ / EZE" reads as a mistake rather than as two
        // registries happening to concur.
        $codes = array_unique(array_filter([$airport->anac_code, $airport->icao_code, $airport->iata_code]));

        return implode(' ', array_filter($words)).' · '.implode(' / ', $codes);
    }

    /**
     * Where it is: the sentence MADHEL splits into three fields, then the
     * coordinates and the elevation, then the FIR.
     *
     * @return array<int, string>
     */
    protected function airportLocationLines(Airport $airport): array
    {
        $lines = [];
        $place = $this->airportPlace($airport);

        if ($place !== null) {
            $lines[] = "📍 {$place}";
        }

        if ($airport->latitude !== null && $airport->longitude !== null) {
            // Indented under the place it belongs to — unless there is no
            // place line, in which case the coordinates are the location and
            // an orphan indent would read as a continuation of nothing.
            $lines[] = ($place === null ? '📍 ' : '   ')
                .$this->latitude($airport->latitude).' '.$this->longitude($airport->longitude);
        }

        if ($airport->elevation_m !== null) {
            // MADHEL publishes metres; aviation flies in feet. Both, rather
            // than a conversion the reader has to do in their head.
            $feet = (int) round($airport->elevation_m / 0.3048);
            $lines[] = "⛰️ Elevación {$airport->elevation_m} m ({$feet} ft)";
        }

        $region = array_filter([
            $airport->fir === null ? null : 'FIR '.$this->firName($airport->fir)." ({$airport->fir})",
            match ($airport->traffic) {
                'INTL' => 'Tránsito internacional',
                'NTL' => 'Tránsito nacional',
                default => null,
            },
        ]);

        if ($region !== []) {
            $lines[] = '🗺️ '.implode(' · ', $region);
        }

        return $lines === [] ? ['📍 MADHEL no publica la ubicación de este aeródromo.'] : $lines;
    }

    /**
     * "4,5 km al nor-noreste de Santa Rosa (La Pampa)".
     *
     * MADHEL writes the direction as a compass point, in the Spanish rose most
     * of the time and the English one occasionally — but for the thirty-one
     * aerodromes that sit against the town they serve it writes the literal
     * "Lindando", with a distance of zero. That is not a bearing, and pushing
     * it through the compass table would either invent one or drop what it
     * actually says, so a zero distance is phrased as what it means instead.
     */
    protected function airportPlace(Airport $airport): ?string
    {
        $where = $airport->city_reference;

        if ($where !== null && $airport->state !== null) {
            $where .= ' ('.$this->titleCase($airport->state).')';
        }

        $where ??= $airport->state === null ? null : $this->titleCase($airport->state);

        if ($where === null) {
            return null;
        }

        if ($airport->distance_km === null || $airport->distance_km <= 0.0) {
            return $airport->city_reference === null ? $where : "Lindando con {$where}";
        }

        $distance = str_replace('.', ',', rtrim(rtrim(number_format($airport->distance_km, 1, '.', ''), '0'), '.'));
        $bearing = $airport->direction_reference === null
            ? null
            : Compass::describe($airport->direction_reference);

        return $bearing === null
            ? "A {$distance} km de {$where}"
            : "{$distance} km al {$bearing} de {$where}";
    }

    /**
     * The five Argentine FIRs by the ICAO code MADHEL publishes. Not the same
     * vocabulary as PronareaFirResolver's, which uses the short codes the SMN
     * prints on its own bulletins.
     */
    protected function firName(string $fir): string
    {
        return match ($fir) {
            'SAEF' => 'Ezeiza',
            'SACF' => 'Córdoba',
            'SAVF' => 'Comodoro Rivadavia',
            'SARR' => 'Resistencia',
            'SAMF' => 'Mendoza',
            default => $fir,
        };
    }

    /**
     * One line per runway rather than per end: both ends of a strip share its
     * length, width and surface, and "01/19 — 2300 × 30 m" is how a chart
     * writes it. An end whose opposite is not on file is listed on its own.
     *
     * @param  array<int, Runway>  $runways
     * @return array<int, string>
     */
    protected function runwayLines(array $runways): array
    {
        if ($runways === []) {
            return ['Sin pistas publicadas por MADHEL ni OurAirports.'];
        }

        $byDesignator = [];

        foreach ($runways as $runway) {
            $byDesignator[$runway->designator] = $runway;
        }

        $lines = [];
        $paired = [];

        foreach ($runways as $runway) {
            if (isset($paired[$runway->designator])) {
                continue;
            }

            $paired[$runway->designator] = true;
            $opposite = $runway->oppositeDesignator();
            $other = $opposite === null ? null : ($byDesignator[$opposite] ?? null);

            if ($other !== null) {
                $paired[$other->designator] = true;
            }

            $parts = [$other === null ? $runway->designator : "{$runway->designator}/{$other->designator}"];

            $parts[] = $this->runwaySize($runway);
            $parts[] = $runway->surface;
            // Only when it is lit. A runway nobody has said is lit may still
            // be, and "no balizada" is a claim about a night landing that no
            // source here is entitled to make.
            $parts[] = $runway->is_lighted === true ? 'balizada' : null;
            $parts[] = $runway->is_closed || $other?->is_closed ? '⛔ cerrada' : null;

            $lines[] = '• '.implode(' — ', array_filter($parts));
        }

        return $lines;
    }

    protected function runwaySize(Runway $runway): ?string
    {
        return match (true) {
            $runway->length_m !== null && $runway->width_m !== null => "{$runway->length_m} × {$runway->width_m} m",
            $runway->length_m !== null => "{$runway->length_m} m de largo",
            $runway->width_m !== null => "{$runway->width_m} m de ancho",
            default => null,
        };
    }

    /**
     * Fuel, telephone and opening hours — the three things MADHEL only
     * publishes for the aerodromes it does not delegate to the AIP.
     *
     * Fuel and telephone are named even when there is nothing to say, because
     * their silence is itself the answer to a question somebody asked; the
     * opening hours are not, since MADHEL carries them for one aerodrome in
     * fifteen and a third "sin dato publicado" would be noise rather than
     * information.
     *
     * Delegated aerodromes are handed off entirely to aipServiceLines(): this
     * method's own "sin dato publicado" would be a lie about a field MADHEL
     * never claimed to carry in the first place — it names the AIP instead.
     *
     * @return array<int, string>
     */
    protected function airportServiceLines(Airport $airport): array
    {
        if ($airport->is_aip_delegated) {
            return $this->aipServiceLines($airport);
        }

        if ($airport->details_updated_at === null) {
            // Never asked MADHEL about this one. Saying "sin dato publicado"
            // here would be reporting our own gap as the registry's.
            return ['_Todavía no importé la ficha de MADHEL de este aeródromo (notams:import-airport-details)._'];
        }

        $unpublished = 'sin dato publicado en MADHEL';

        $lines = [
            '⛽ Combustible: '.($airport->fuel ?? $unpublished),
            '☎️ Teléfono: '.($airport->telephone === null ? $unpublished : implode(' · ', $airport->telephone)),
        ];

        if ($airport->service_schedule !== null) {
            $lines[] = "🕐 Horario: {$airport->service_schedule}";
        }

        return $lines;
    }

    /**
     * Same three lines as airportServiceLines(), sourced from the AIP instead
     * of MADHEL, plus a fourth MADHEL never had for anyone: the tower/approach
     * frequency, from notams:import-aip-details.
     *
     * @return array<int, string>
     */
    protected function aipServiceLines(Airport $airport): array
    {
        if ($airport->aip_details_updated_at === null) {
            return ['_Todavía no importé la ficha de la AIP de este aeródromo (notams:import-aip-details)._'];
        }

        $unpublished = 'sin dato publicado en la AIP';

        $lines = [
            '⛽ Combustible: '.($airport->aip_fuel ?? $unpublished),
            '☎️ Teléfono: '.($airport->aip_telephone === null ? $unpublished : implode(' · ', $airport->aip_telephone)),
        ];

        if ($airport->aip_service_schedule !== null) {
            $lines[] = "🕐 Horario: {$airport->aip_service_schedule}";
        }

        if ($airport->aip_ats_frequency !== null) {
            $lines[] = "📻 Frecuencia: {$airport->aip_ats_frequency}";
        }

        return $lines;
    }

    /**
     * Degrees-minutes-seconds, the form every aeronautical chart prints and the
     * only one a pilot can compare against one without converting.
     */
    protected function latitude(float $degrees): string
    {
        return $this->dms($degrees, 2).($degrees < 0 ? 'S' : 'N');
    }

    protected function longitude(float $degrees): string
    {
        // Three digits, because longitude runs to 180 and a chart pads it.
        return $this->dms($degrees, 3).($degrees < 0 ? 'O' : 'E');
    }

    protected function dms(float $degrees, int $pad): string
    {
        $total = (int) round(abs($degrees) * 3600);

        return sprintf(
            '%0'.$pad."d°%02d'%02d\"",
            intdiv($total, 3600),
            intdiv($total % 3600, 60),
            $total % 60,
        );
    }

    /**
     * MADHEL shouts its province names ("LA PAMPA", "SANTA FÉ"). Title case
     * reads as a place rather than as an alarm, with the connecting words left
     * down, the way Spanish writes them.
     */
    protected function titleCase(string $text): string
    {
        $words = explode(' ', mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8'));

        return implode(' ', array_map(
            fn (string $word, int $i) => $i > 0 && in_array(mb_strtolower($word), ['de', 'del', 'la', 'las', 'los', 'y', 'e'], true)
                ? mb_strtolower($word)
                : $word,
            $words,
            array_keys($words),
        ));
    }

    /**
     * The follow-up offering the other three topics for the aerodrome just
     * answered about.
     *
     * Null off-channel, where there is nobody to send a second message to;
     * null for a place whose code will not fit the button payload (ANAC's
     * FIR-wide bulletins carry no indicator at all); and — unlike every other
     * button — null when no template is registered, because this offer has no
     * message of its own to ride on. Degrading it the way sub:/unsub: do would
     * mean an extra message per answer whose whole content is three commands
     * helpMessage() already teaches, so it is skipped rather than sent as text.
     */
    protected function menuFor(string $topic, ?string $indicator, ?string $from): ?ReplyMenu
    {
        if ($indicator === null || $from === null) {
            return null;
        }

        $code = $this->airports->icaoFor($indicator) ?? $indicator;

        if (preg_match('/^[A-Z]{3,4}$/', $code) !== 1) {
            return null;
        }

        $button = ReplyButton::menu($topic, $code);

        if (! $button->isAvailable()) {
            return null;
        }

        $name = $this->airports->nameFor($indicator) ?? $indicator;

        return new ReplyMenu("¿Querés algo más de *{$name}*?", $button);
    }

    protected function tafReply(string $indicator, ?string $from): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        if ($icao === null) {
            return WhatsappReply::of("*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica TAF para ese aeródromo.");
        }

        try {
            $tafs = $this->tafService->getTafs($icao);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('Encontré el aeropuerto pero no pude obtener su pronóstico TAF en este momento. Probá de nuevo en unos minutos.');
        }

        return $this->formatTafs($name, $icao, $this->tafEnricher->enrich($tafs))
            ->withMenu($this->menuFor('taf', $indicator, $from));
    }

    /**
     * The forecast verbatim, then what it says in Spanish — same shape as the
     * METAR reply, and for the same reason: the raw TAF is the form a pilot can
     * cross-check against any other source.
     *
     * @param  array<int, Taf>  $tafs
     */
    protected function formatTafs(string $airportName, string $icao, array $tafs): WhatsappReply
    {
        if ($tafs === []) {
            return WhatsappReply::of("No hay TAF publicado para *{$airportName}* ({$icao}) en este momento.");
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

        return WhatsappReply::ofMany($this->withHeader($header, $parts));
    }

    /**
     * PRONAREA is published per FIR, not per aerodrome, so the aerodrome the
     * message resolved to is only used to look up which FIR speaks for it.
     *
     * No follow-up menu, unlike NOTAM/METAR/TAF/crepúsculo: PRONAREA is not
     * offered as a quick-reply action, by design — see helpMessage() for the
     * only place it is surfaced.
     */
    protected function pronareaReply(string $indicator): WhatsappReply
    {
        $fir = $this->pronareaFirs->firFor($indicator);

        if ($fir === null) {
            $name = $this->airports->nameFor($indicator) ?? $indicator;

            return WhatsappReply::of("*{$name}* ({$indicator}) no está entre los aeródromos para los que el SMN publica PRONAREA.");
        }

        try {
            $forecast = $this->pronarea->forFir($fir);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('No pude consultar el PRONAREA del SMN en este momento. Probá de nuevo en unos minutos.');
        }

        return $this->formatPronarea($forecast);
    }

    /**
     * The bulletin verbatim — same reasoning as the TAF reply: it is the form
     * a pilot can cross-check against any other source. When the SMN could
     * not be reached and this is the last bulletin fetched successfully
     * rather than the current one, that is said up front rather than left for
     * the reader to notice on their own.
     */
    protected function formatPronarea(PronareaForecast $forecast): WhatsappReply
    {
        $header = "🗺️ *PRONAREA FIR {$forecast->fir}*";
        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($header) - 12;

        $lines = [];

        if ($forecast->stale) {
            $lines[] = sprintf(
                '⚠️ No pude confirmar si sigue vigente: es la última versión que obtuve, de las %s UTC.',
                $forecast->fetchedAt->format('H:i'),
            );
            $lines[] = '';
        }

        $lines[] = '```'.$forecast->raw.'```';
        $lines[] = '';
        $lines[] = $this->sourceCredit(false);

        $parts = $this->splitToFit(implode("\n", $lines), $budget);

        return WhatsappReply::ofMany($this->withHeader($header, $parts));
    }

    /**
     * AEROMET is queried by station name rather than by ANAC aerodrome — its
     * network is wider than the aerodromes AirportResolver knows, covering
     * towns with no aerodrome at all — so this resolves straight from the
     * message text via AerometStationResolver instead of matchIndicator().
     *
     * A message naming a station by its ANAC or OACI code instead of its name
     * ("aeromet nin" for Junín) never matches that way, so it falls back to
     * AirportResolver's own resolution and bridges the aerodrome it finds
     * back into AEROMET's station list.
     */
    protected function aerometReply(string $message): WhatsappReply
    {
        $code = $this->aerometStations->codeFromText($message)
            ?? $this->aerometCodeFromAerodrome($message);

        if ($code === null) {
            return WhatsappReply::of(
                'No encontré ninguna estación AEROMET en tu mensaje. Probá con: _"aeromet junin"_.'
            );
        }

        return $this->aerometReplyForCode($code);
    }

    /**
     * Answers for a WMO/OMM code already known to be an AEROMET station —
     * either resolved from free text by aerometReply(), or read straight off
     * a tapped BUTTON_AEROMET payload.
     *
     * The runway-wind offer rides underneath it. This report carries a wind and
     * nothing that says what it does to a runway, which is the same gap a METAR
     * leaves — and for an aerodrome with no METAR of its own, this observation
     * is exactly what runwayWindReply() would compute the components from.
     *
     * Which aerodrome, though, is not a question AEROMET's answer can settle on
     * its own: its stations are localities, and a locality may hold three
     * aerodromes (Coronel Suárez) or none (Azul, Ceres, Chepes). A tapped offer
     * says which one it was made under and that is taken verbatim; a typed
     * "aeromet junin" has only the station's name to go on, so it goes through
     * AirportResolver's ordinary matching, which answers null unless one
     * aerodrome wins outright.
     *
     * @param  string|null  $indicator  The aerodrome the offer was made under, when the
     *                                  answer came from a tapped button that carried one.
     */
    protected function aerometReplyForCode(string $code, ?string $indicator = null): WhatsappReply
    {
        try {
            $observations = $this->aeromet->getObservations($code);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('No pude obtener el AEROMET del SMN en este momento. Probá de nuevo en unos minutos.');
        }

        $stationName = $this->aerometStations->nameFor($code);
        $indicator ??= $this->airports->matchFromText($stationName);

        return $this->formatAeromet(
            $stationName,
            $this->aerometEnricher->enrich($observations),
            $indicator === null ? null : $this->runwayWindOffer($indicator),
        );
    }

    /**
     * The AEROMET code for whatever aerodrome AirportResolver finds in the
     * message, when it names one AEROMET also covers under the same name.
     */
    protected function aerometCodeFromAerodrome(string $message): ?string
    {
        $anacCode = $this->airports->matchFromText($message);

        if ($anacCode === null) {
            return null;
        }

        $name = $this->airports->nameFor($anacCode);

        return $name === null ? null : $this->aerometStations->codeForName($name, $anacCode);
    }

    /**
     * Same shape as formatMetars: the raw line first, verbatim, then the
     * plain-Spanish explanation underneath. No follow-up menu, same reasoning
     * as PRONAREA — there is nothing else to naturally offer about a station
     * yet.
     *
     * @param  array<int, AerometObservation>  $observations
     * @param  ReplyButton|null  $button  The runway-wind offer, when an aerodrome with
     *                                    runways on file sits behind this station.
     */
    protected function formatAeromet(string $stationName, array $observations, ?ReplyButton $button = null): WhatsappReply
    {
        if ($observations === []) {
            // Nothing published means no wind either, so the runway offer is
            // dropped along with it rather than leading somewhere just as empty.
            return WhatsappReply::of("No hay AEROMET publicado para *{$stationName}* en este momento.");
        }

        $header = "🌡️ *AEROMET {$stationName}*";
        $total = count($observations);
        $budget = ($button === null ? self::MAX_MESSAGE_LENGTH : self::MAX_TEMPLATE_BODY_LENGTH)
            - mb_strlen($header) - 12;

        $parts = [];

        foreach ($observations as $i => $observation) {
            $lines = [];

            // No second source to fail over to, so a Cloudflare block is
            // ridden out by serving the last observation fetched
            // successfully instead — said up front, same as PRONAREA, rather
            // than left for the reader to notice on their own. Either way the
            // reader gets the observation's own date/time, not just a
            // warning when it happens to be stale — the raw SYNOP line
            // buries it in "DDGGiw", nothing like how plainly METAR's own
            // "301700Z" reads.
            $lines[] = $observation->stale
                ? "⚠️ No pude confirmar si sigue vigente: es la última observación que obtuve, de las {$observation->observedAt} UTC."
                : "🕐 Observación de las {$observation->observedAt} UTC.";
            $lines[] = '';

            $lines[] = '```'.$observation->raw.'```';

            if ($observation->explanation !== []) {
                $lines[] = '';
                $lines[] = '📋 *Qué dice*';

                foreach ($observation->explanation as $line) {
                    $lines[] = "• {$line}";
                }
            }

            if ($i === $total - 1) {
                $lines[] = '';
                $lines[] = $this->sourceCredit(false);
            }

            foreach ($this->splitToFit(implode("\n", $lines), $budget) as $part) {
                $parts[] = $part;
            }
        }

        return WhatsappReply::ofMany($this->withHeader($header, $parts), $button);
    }

    /**
     * What time the sun does its four things on the day asked, for a city.
     *
     * The day is taken in Argentine official time and not in UTC: at 22:00 in
     * Buenos Aires it is already tomorrow in Greenwich, and "hoy" for whoever is
     * typing is the day on their own calendar — same reasoning applies to
     * resolving "mañana" or an explicit date.
     */
    protected function sunReply(string $message, ?string $from): WhatsappReply
    {
        $city = $this->sunCities->matchFromText($message);

        if ($city === null) {
            return WhatsappReply::of($this->sunCitiesMessage());
        }

        $today = $this->sunToday();
        $date = $this->resolveSunDate($message, $today);

        try {
            $reply = $this->sunTimesReply($city, $date, $today);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('No pude consultar la tabla del sol del Servicio de Hidrografía Naval en este momento. Probá de nuevo en unos minutos.');
        }

        $indicator = $this->context->anacCode = $this->sunAerodrome($message, $city);

        return $reply->withMenu($this->menuFor('crepusculo', $indicator, $from));
    }

    /**
     * The sun over an aerodrome, from a tapped button. Always today: a tap
     * carries no date, and there is nowhere in it to type one.
     */
    protected function sunReplyFor(string $indicator, string $from): WhatsappReply
    {
        $city = $this->sunCities->cityFor($indicator);

        if ($city === null) {
            $name = $this->airports->nameFor($indicator) ?? $indicator;

            return WhatsappReply::of($this->sunCitiesMessage(
                "🌅 No tengo tabla del sol para *{$name}* ({$indicator}): el Servicio de Hidrografía Naval la publica por ciudad, y esa no está en su lista."
            ));
        }

        $today = $this->sunToday();

        try {
            $reply = $this->sunTimesReply($city, $today, $today);
        } catch (\Throwable $e) {
            report($e);

            return WhatsappReply::of('No pude consultar la tabla del sol del Servicio de Hidrografía Naval en este momento. Probá de nuevo en unos minutos.');
        }

        return $reply->withMenu($this->menuFor('crepusculo', $indicator, $from));
    }

    /**
     * The sun over a city on one date, formatted — or a plain answer when the
     * SHN simply has no row for it. Shared by the text path and a tapped
     * button, which never have anything left to try beyond this call.
     */
    protected function sunTimesReply(string $city, CarbonImmutable $date, CarbonImmutable $today): WhatsappReply
    {
        $times = $this->sun->forDate($city, $date);

        if ($times === null) {
            return WhatsappReply::of("El SHN no publica datos del sol para *{$city}* en esta fecha.");
        }

        return $this->formatSunTimes($times, $this->sunDateLabel($date, $today));
    }

    protected function sunToday(): CarbonImmutable
    {
        return CarbonImmutable::now(ShnSunService::OFFSET)->startOfDay();
    }

    /**
     * The aerodrome a sun question named, when it named one at all.
     *
     * "crepusculo SAZR" carries an aerodrome; "crepusculo santa rosa" happens
     * to as well, because Santa Rosa has one. A city with several — Buenos
     * Aires has five — or none the resolver is confident about leaves nothing
     * to offer NOTAMs for, and the answer simply goes out without a menu. The
     * city has to match too: "crepusculo base esperanza" resolves the
     * Antarctic locality by alias while the aerodrome matcher lands on the
     * Esperanza in Santa Fe, and offering that one's NOTAMs under an Antarctic
     * sunset would be wrong by 3.500 km.
     */
    protected function sunAerodrome(string $message, string $city): ?string
    {
        $code = $this->airports->matchFromText($message);

        return $code !== null && $this->sunCities->cityFor($code) === $city ? $code : null;
    }

    /**
     * Which day the message is actually asking about: yesterday or tomorrow
     * when it says so, an explicit date such as "15/08" or "15 de agosto" when
     * it names one, and today otherwise.
     *
     * An explicit date wins over either relative word when somehow both appear,
     * because there is nothing more specific than a date the user typed
     * themselves.
     */
    protected function resolveSunDate(string $message, CarbonImmutable $today): CarbonImmutable
    {
        $normalized = Str::ascii(mb_strtolower($message));

        return $this->explicitDate($normalized, $today) ?? match (true) {
            str_contains($normalized, 'ayer') => $today->subDay(),
            str_contains($normalized, 'manana') => $today->addDay(),
            default => $today,
        };
    }

    /**
     * A day/month (and optionally year) the message names, either numeric
     * ("15/08" or "15/08/2026") or written out ("15 de agosto").
     */
    protected function explicitDate(string $normalized, CarbonImmutable $today): ?CarbonImmutable
    {
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{4}|\d{2}))?\b/', $normalized, $m) === 1) {
            return $this->buildDate((int) $m[1], (int) $m[2], isset($m[3]) ? $this->fullYear($m[3]) : null, $today);
        }

        if (preg_match('/\b(\d{1,2})\s+de\s+('.implode('|', array_keys(self::MONTH_NAMES)).')\b/', $normalized, $m) === 1) {
            return $this->buildDate((int) $m[1], self::MONTH_NAMES[$m[2]], null, $today);
        }

        return null;
    }

    protected function fullYear(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }

    /**
     * A calendar date from its parts, or null when the day/month combination
     * does not exist (the SHN prints no such row either, so "31/04" is honestly
     * a question with no answer rather than one to guess at).
     *
     * When no year was named, the current one is assumed unless that day has
     * already gone by — "el 5 de enero" asked in December means the January
     * still ahead, not the one behind.
     */
    protected function buildDate(int $day, int $month, ?int $year, CarbonImmutable $today): ?CarbonImmutable
    {
        $explicitYear = $year !== null;
        $year ??= $today->year;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::createFromDate($year, $month, $day, ShnSunService::OFFSET)->startOfDay();

        if (! $explicitYear && $date->lt($today)) {
            $date = $date->addYear();
        }

        return $date;
    }

    /**
     * "hoy, 01/07", "mañana, 02/07", "ayer, 30/06", or just "15/08" once the
     * date is far enough that no relative word would be honest.
     */
    protected function sunDateLabel(CarbonImmutable $date, CarbonImmutable $today): string
    {
        return match (true) {
            $date->isSameDay($today) => "hoy, {$date->format('d/m')}",
            $date->isSameDay($today->addDay()) => "mañana, {$date->format('d/m')}",
            $date->isSameDay($today->subDay()) => "ayer, {$date->format('d/m')}",
            default => $date->format('d/m'),
        };
    }

    /**
     * Both clocks, on purpose. The bot answers in UTC everywhere else because
     * that is what a flight plan is written in, and this stays consistent with
     * it — but the decision this data serves ("¿llego con luz?") is made against
     * the clock on the wall, so the local time is right there next to it.
     */
    protected function formatSunTimes(SunTimes $times, string $dateLabel): WhatsappReply
    {
        $lines = ["🌅 *{$times->city}* — {$dateLabel}", ''];

        $moments = [
            ['dawn', 'Crepúsculo matutino', $times->dawn],
            ['sunrise', 'Salida del sol', $times->sunrise],
            ['sunset', 'Puesta del sol', $times->sunset],
            ['dusk', 'Crepúsculo vespertino', $times->dusk],
        ];

        foreach ($moments as [$moment, $label, $at]) {
            $lines[] = $at === null
                ? "• {$label}: {$this->sunSymbolMeaning($times->symbolFor($moment))}"
                : sprintf(
                    '• %s: %s UTC (%s local)',
                    $label,
                    $at->format('H:i'),
                    $at->setTimezone(ShnSunService::OFFSET)->format('H:i'),
                );
        }

        $lines[] = '';
        $lines[] = '_Fuente: Servicio de Hidrografía Naval_';

        return WhatsappReply::of(implode("\n", $lines));
    }

    /**
     * The SHN prints a symbol instead of an hour on the days a high-latitude
     * place has no sunrise, no sunset, or no real night. Saying which one it is
     * beats leaving a blank the reader has to interpret.
     */
    protected function sunSymbolMeaning(?string $symbol): string
    {
        return match ($symbol) {
            '***' => 'el sol no se pone en esta fecha',
            '----' => 'el sol no sale en esta fecha',
            '////' => 'hay crepúsculo toda la noche',
            default => 'sin dato',
        };
    }

    /**
     * The SHN publishes by city, not by aerodrome, so there is no honest way to
     * answer for a place that is not on its list — naming the ones that are is
     * the whole answer.
     */
    protected function sunCitiesMessage(?string $lead = null): string
    {
        $lead ??= '🌅 La salida y puesta de sol la publica el Servicio de Hidrografía Naval por ciudad, y no encontré ninguna en tu mensaje.';

        return "{$lead}\n\n"
            .'Probá con: _"crepusculo santa rosa"_.'
            ."\n\n"
            .'*Ciudades disponibles:* '.implode(', ', $this->sunCities->cities()).'.';
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
     * @param  array{0: ?ReplyButton, 1: ?string}  $offer  Watch button and/or standing-watch note, from watchOffer().
     */
    protected function formatMetars(string $airportName, string $icao, array $metars, array $offer = [null, null]): WhatsappReply
    {
        [$button, $note] = $offer;

        if ($metars === []) {
            return WhatsappReply::ofMany(
                ["No hay METAR publicado para *{$airportName}* ({$icao}) en este momento."],
                $this->aerometOffer($this->airports->resolve($icao), $airportName),
            );
        }

        $header = "🌦️ *{$airportName}* ({$icao})";
        $total = count($metars);
        $credit = $this->sourceCredit($metars[0]->isRelayed());
        $budget = ($button === null ? self::MAX_MESSAGE_LENGTH : self::MAX_TEMPLATE_BODY_LENGTH)
            - mb_strlen($header) - 12;

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
                if ($note !== null) {
                    $lines[] = '';
                    $lines[] = $note;
                }

                $lines[] = '';
                $lines[] = $credit;
            }

            foreach ($this->splitToFit(implode("\n", $lines), $budget) as $part) {
                $parts[] = $part;
            }
        }

        return WhatsappReply::ofMany($this->withHeader($header, $parts), $button);
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
     * $button rides on the last message only — see WhatsappReply::outbound() —
     * which is where "y de paso, ¿cómo está el viento en las pistas?" belongs:
     * after the notices, not interrupting them.
     *
     * @param  array<int, Notam>  $notams
     */
    protected function formatNotams(string $airportName, string $indicator, array $notams, bool $closed = false, ?ReplyButton $button = null): WhatsappReply
    {
        // A closed aerodrome usually has nothing active to report, and "no hay
        // NOTAM activos ✅" on its own reads as "está todo bien" — the opposite
        // of what a pilot needs to know. It rides on the header rather than on
        // a message of its own so that it survives a reply split into parts,
        // and so the length budget below accounts for it.
        $closedNotice = $closed ? "\n⛔ *Aeródromo cerrado*" : '';

        if ($notams === []) {
            return WhatsappReply::ofMany(
                ["No hay NOTAM activos para *{$airportName}* ({$indicator}) en este momento. ✅".$closedNotice],
                $button,
            );
        }

        $header = "✈️ *{$airportName}* ({$indicator})".$closedNotice;
        $total = count($notams);

        // Reserve room for the header and the widest plausible "(99/99) "
        // prefix, so the assembled message still fits once both are added.
        // A message that carries a button is a content template, and WhatsApp
        // caps those far shorter than a plain one.
        $budget = ($button === null ? self::MAX_MESSAGE_LENGTH : self::MAX_TEMPLATE_BODY_LENGTH)
            - mb_strlen($header) - 12;

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

        return WhatsappReply::ofMany($this->withHeader($header, $parts), $button);
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
        return "¡Hola! 👋 Decime el aeropuerto que te interesa y te paso su ficha: dónde queda, sus pistas, la elevación y qué servicios publica ANAC.\n\n"
            .'Por ejemplo: _"aeroparque"_, _"santa rosa"_ o el código _"EZE"_.'
            ."\n\n"
            .'Si lo que querés son los NOTAM activos, pedímelos: _"hay notams en Ezeiza?"_ o _"notam EZE"_.'
            ."\n\n"
            .'Si querés el estado del tiempo, pedime el METAR: _"metar EZE"_ o _"cómo está el clima en Bariloche?"_.'
            ."\n\n"
            .'Y si lo que te interesa es cómo va a estar, pedime el TAF: _"taf EZE"_ o _"pronóstico de Aeroparque"_.'
            ."\n\n"
            .'Para el pronóstico de área de toda la FIR, pedime el PRONAREA: _"pronarea EZE"_.'
            ."\n\n"
            .'El AEROMET del SMN cubre más estaciones que el METAR, incluso ciudades sin aeródromo: _"aeromet junin"_.'
            ."\n\n"
            .'Con el METAR en la mano puedo calcular el componente de viento en cada cabecera: _"viento cruzado en Ezeiza"_.'
            ."\n\n"
            .'También puedo avisarte cuando el clima cambie: _"avisame EZE"_. Con _"mis alertas"_ ves las que tenés activas.'
            ."\n\n"
            .'Y para saber hasta qué hora hay luz, pedime la salida y puesta de sol: _"crepusculo santa rosa"_ (el SHN lo publica por ciudad).';
    }

    /**
     * The message sent when a watched aerodrome's weather has actually moved.
     *
     * Same shape as the answer to "metar EZE" — what changed, the report
     * verbatim, then what it says in Spanish — because someone reading this at
     * six in the morning should not have to learn a second layout. What is new
     * is the "qué cambió" block at the top, which names the groups rather than
     * paraphrasing them so the reader can find each one in the text below.
     *
     * @param  array<int, string>  $changes  From MetarConditions::changesFrom().
     */
    public function changeAlert(string $anacCode, Metar $metar, array $changes, string $expiryLabel): WhatsappReply
    {
        $name = $this->airports->nameFor($anacCode) ?? $anacCode;
        $icao = $metar->station !== '' ? $metar->station : ($this->airports->icaoFor($anacCode) ?? $anacCode);

        $button = ReplyButton::unsubscribe($icao);
        $enriched = $this->metarEnricher->enrich([$metar])[0] ?? $metar;

        $header = "⚠️ *{$name}* ({$icao}) — cambió el clima";
        $budget = self::MAX_TEMPLATE_BODY_LENGTH - mb_strlen($header) - 12;

        $lines = ['🔄 *Qué cambió*'];

        foreach ($changes as $change) {
            $lines[] = "• {$change}";
        }

        $lines[] = '';

        if ($enriched->isSpeci()) {
            $lines[] = '⚠️ Informe especial (SPECI)';
        }

        $lines[] = '```'.$enriched->raw.'```';

        if ($enriched->explanation !== []) {
            $lines[] = '';
            $lines[] = '📋 *Qué dice*';

            foreach ($enriched->explanation as $line) {
                $lines[] = "• {$line}";
            }
        }

        $lines[] = '';
        $lines[] = "_Alerta vigente hasta el {$expiryLabel}._";
        $lines[] = $this->sourceCredit($enriched->isRelayed());

        return WhatsappReply::ofMany(
            $this->withHeader($header, $this->splitToFit(implode("\n", $lines), $budget)),
            $button,
        );
    }

    /**
     * Sent once, when a watch runs out.
     *
     * A subscription that simply stopped would be indistinguishable from one
     * that was working and had nothing to report — and "the weather never
     * changed" is precisely the reassurance someone might act on.
     */
    public function expiryNotice(string $anacCode): string
    {
        $name = $this->airports->nameFor($anacCode) ?? $anacCode;

        return "🔕 Se venció tu alerta de METAR para *{$name}* ({$anacCode}).\n\n"
            .'Si querés reactivarla, pedime _"avisame '.$anacCode.'"_ o tocá el botón en el próximo METAR.';
    }
}
