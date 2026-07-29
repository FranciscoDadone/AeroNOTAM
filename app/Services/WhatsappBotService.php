<?php

namespace App\Services;

use App\Ai\Agents\AirportMatcherAgent;
use App\DataObjects\Metar;
use App\DataObjects\Notam;
use App\DataObjects\ReplyButton;
use App\DataObjects\ReplyContext;
use App\DataObjects\Taf;
use App\DataObjects\WhatsappReply;
use App\Models\MetarSubscription;
use App\Support\AirportResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns an incoming WhatsApp message into the reply to send back.
 *
 * Two things have to be worked out from free text: which aerodrome the user
 * means, and what they want to know about it. The aerodrome is resolved once,
 * up front, and shared by both answers; the question then routes to NOTAMs
 * (the default), to the current METAR, to the forecast, or to a standing
 * "tell me when this changes" watch.
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

        if (in_array($topic, ['list', 'subscribe', 'unsubscribe'], true)) {
            return $from === null
                ? WhatsappReply::of('Las alertas sólo funcionan por WhatsApp, donde puedo escribirte cuando algo cambie.')
                : $this->subscriptionReply($topic, $message, $from);
        }

        $indicator = $this->context->anacCode = $this->matchIndicator($message);

        if ($indicator === null) {
            // Several aerodromes share the name the user typed (Córdoba has
            // three). Asking is the only honest answer — picking one silently
            // could send a pilot the wrong aerodrome's NOTAMs.
            $candidates = $this->airports->candidatesFromText($message);

            return WhatsappReply::of(count($candidates) > 1
                ? $this->disambiguationMessage($candidates)
                : $this->helpMessage());
        }

        return match ($topic) {
            'taf' => $this->tafReply($indicator),
            'metar' => $this->metarReply($indicator, $from),
            default => $this->notamReply($indicator),
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

        return null;
    }

    /**
     * What the message is actually asking for.
     *
     * The subscription topics are checked first because they overlap with every
     * other one by design: "avisame si cambia el clima en EZE" contains a METAR
     * word, and answering it with today's observation would silently drop the
     * part the user actually asked for.
     *
     * "notam" wins over the rest when the word is there: someone who typed it
     * knows what they want, and "hay notams para mañana en EZE" must not be
     * answered with a forecast just because it mentions tomorrow.
     */
    protected function topic(string $message): string
    {
        $normalized = Str::ascii(mb_strtolower($message));

        return match (true) {
            $this->mentions($normalized, self::LIST_KEYWORDS) => 'list',
            $this->mentions($normalized, self::UNSUBSCRIBE_KEYWORDS) => 'unsubscribe',
            $this->mentions($normalized, self::SUBSCRIBE_KEYWORDS) => 'subscribe',
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

    protected function notamReply(string $indicator): WhatsappReply
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
        );
    }

    protected function metarReply(string $indicator, ?string $from): WhatsappReply
    {
        $name = $this->airports->nameFor($indicator) ?? $indicator;
        $icao = $this->airports->icaoFor($indicator);

        // Not every ANAC aerodrome has an ICAO code, and the SMN indexes
        // observations by that code alone. Saying so is more useful than a
        // generic failure, because retrying will never help.
        if ($icao === null) {
            return WhatsappReply::of("*{$name}* ({$indicator}) no tiene código OACI, así que el SMN no publica METAR para ese aeródromo.");
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
        );
    }

    /**
     * The "notify me" offer that goes under an observation: a button when there
     * is something to offer, a line of text when there already is a watch
     * running, and nothing at all off-channel.
     *
     * Offering the button to someone who is already subscribed would be a
     * promise about something already true — worse than useless, because
     * tapping it would look like it had failed to change anything.
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
            : [null, "🔔 _Ya te estoy avisando de los cambios acá, hasta el {$existing->expiryLabel()}._"];
    }

    protected function tafReply(string $indicator): WhatsappReply
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

        return $this->formatTafs($name, $icao, $this->tafEnricher->enrich($tafs));
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
            return WhatsappReply::of("No hay METAR publicado para *{$airportName}* ({$icao}) en este momento.");
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
     * @param  array<int, Notam>  $notams
     */
    protected function formatNotams(string $airportName, string $indicator, array $notams): WhatsappReply
    {
        if ($notams === []) {
            return WhatsappReply::of("No hay NOTAM activos para *{$airportName}* ({$indicator}) en este momento. ✅");
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

        return WhatsappReply::ofMany($this->withHeader($header, $parts));
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
            .'Y si lo que te interesa es cómo va a estar, pedime el TAF: _"taf EZE"_ o _"pronóstico de Aeroparque"_.'
            ."\n\n"
            .'También puedo avisarte cuando el clima cambie: _"avisame EZE"_. Con _"mis alertas"_ ves las que tenés activas.';
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
