<?php

use App\DataObjects\Metar;
use App\Models\Airport;
use App\Models\MetarSubscription;
use App\Services\WhatsappBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    withoutAi();
});

function bot(): WhatsappBotService
{
    return app(WhatsappBotService::class);
}

it('matches an airport from free text', function (string $message, string $expectedCode) {
    fakeAnac();

    expect(bot()->reply($message)->messages[0])->toContain("({$expectedCode})");
})->with([
    'bare anac code' => ['eze', 'EZE'],
    'anac code in a sentence' => ['hay notams en EZE?', 'EZE'],
    'icao code' => ['SAEZ', 'EZE'],
    'lowercase icao code' => ['notams saez', 'EZE'],
    'city name' => ['ezeiza', 'EZE'],
    'city name in a sentence' => ['hay notams en Ezeiza?', 'EZE'],
    'airport nickname' => ['aeroparque', 'AER'],
    'name without accents' => ['bariloche', 'BAR'],
]);

it('returns the help text for an empty message', function () {
    fakeAnac();

    $reply = bot()->reply('')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])->toContain('Decime el aeropuerto');
});

it('returns the help text for an unrecognizable message', function () {
    fakeAnac();

    expect(bot()->reply('cual es la capital de francia')->messages[0])->toContain('Decime el aeropuerto');
});

/**
 * ANAC's list includes FIR-wide advisory pseudo-codes ("---", "-EF") whose
 * names contain city names. Those are bulletins, not places, and must
 * never be offered as if they were an airport.
 */
it('does not match FIR-wide advisory pseudo-codes', function () {
    fakeAnac();

    expect(bot()->reply('cordoba')->messages[0])
        ->not->toContain('(-CF)')
        ->not->toContain('(---)');
});

/**
 * Seven places in MADHEL are called Córdoba something. Answering with
 * Taravella, the city's international airport, is not a coin flip: it is the
 * only public towered one among a factory airfield, a military flight school
 * and four private helipads.
 */
it('answers an ambiguous city name with its main aerodrome', function () {
    fakeAnac();

    expect(bot()->reply('cordoba')->messages[0])->toContain('(CBA)');
});

/**
 * When ranking has nothing to go on, the bot asks rather than guesses:
 * handing a pilot the wrong aerodrome's NOTAMs is the failure that matters.
 */
it('asks which aerodrome when two of the same kind share a name', function () {
    fakeAnac();

    foreach (['TWA', 'TWB'] as $code) {
        Airport::create(['anac_code' => $code, 'name' => 'VILLA ZURUMBAMBA', 'access' => 'publico']);
    }

    $reply = bot()->reply('zurumbamba')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('varios aeródromos')
        ->toContain('*TWA*')
        ->toContain('Respondeme con el código');
});

it('resolves the ambiguity when answered with a code', function () {
    fakeAnac();

    expect(bot()->reply('CBA')->messages[0])->toContain('(CBA)');
});

/**
 * "No hay NOTAM activos ✅" is the reply a closed aerodrome usually gets, and
 * on its own it reads as "está todo bien" — which is the opposite of true.
 */
it('says an aerodrome is closed even when it has no notams', function () {
    fakeAnac(Http::response('error', 500));

    // Curuzú Cuatiá, which MADHEL publishes as AD CERRADO (CLSD).
    $reply = bot()->reply('notams CCA')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('No hay NOTAM activos')
        ->toContain('Aeródromo cerrado');
});

it('carries the closed warning on every part of a split reply', function () {
    fakeAnac();

    foreach (bot()->reply('notams CCA')->messages as $message) {
        expect($message)->toContain('Aeródromo cerrado');
    }
});

it('does not warn about aerodromes that are open', function () {
    fakeAnac(Http::response('error', 500));

    expect(bot()->reply('notams EZE')->messages[0])->not->toContain('CLSD');
});

it('numbers each notam as its own message', function () {
    fakeAnac();

    $reply = bot()->reply('aeroparque')->messages;

    // The AER fixture carries three NOTAMs.
    expect($reply)->toHaveCount(3)
        ->and($reply[0])->toContain('(1/3)')->toContain('A2187/2026')
        ->and($reply[2])->toContain('(3/3)')
        // The source credit rides on the final message only.
        ->and($reply[2])->toContain('Fuente: ANAC')
        ->and($reply[0])->not->toContain('Fuente: ANAC');
});

it('falls back to the offline decoder when there is no AI', function () {
    fakeAnac();

    // "RWY 13/31 CLSD WIP MAINT" decoded without any model involved.
    expect(bot()->reply('aeroparque')->messages[0])->toContain('Pista 13/31 cerrada');
});

/**
 * A long NOTAM used to be truncated with an ellipsis, silently dropping
 * whatever came last — often the closure window or a contact number.
 */
it('splits a long notam across messages without losing text', function () {
    $tail = 'CONTACTO TEL 011-5555-9999 PARA COORDINAR';
    $long = str_repeat('OBST CRANE ERECTED NEAR THR RWY 13 HGT 45M AGL. ', 80).$tail;

    fakeAnac(Http::response(pibWith($long)));

    $reply = bot()->reply('aeroparque')->messages;

    expect(count($reply))->toBeGreaterThan(1);

    foreach ($reply as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1500);
    }

    expect(implode(' ', $reply))
        ->not->toContain('…')
        ->toContain('011-5555-9999');
});

it('reports a service problem when ANAC is unreachable', function () {
    fakeAnac(Http::response('down', 503));

    expect(bot()->reply('eze')->messages[0])->toContain('no pude obtener sus NOTAM');
});

/*
|--------------------------------------------------------------------------
| METAR
|--------------------------------------------------------------------------
*/

it('answers with the metar when the message asks about the weather', function (string $message) {
    fakeAnac();
    fakeMetar();

    $reply = bot()->reply($message)->messages;

    expect($reply[0])
        ->toContain('METAR SAEZ 271400Z')
        ->toContain('Fuente: Servicio Meteorológico Nacional')
        ->not->toContain('NOTAM');
})->with([
    'the word metar' => ['metar EZE'],
    'asking for the weather' => ['como esta el clima en ezeiza?'],
    'accented, as typed' => ['¿cómo está el tiempo en Ezeiza?'],
    'asking about wind' => ['viento en SAEZ'],
    'asking about visibility' => ['hay visibilidad en ezeiza?'],
]);

/**
 * NOTAMs stay the default. A message with no weather word in it must not be
 * quietly answered with an observation instead of the operational notices.
 */
it('still answers with notams when the weather is not mentioned', function () {
    fakeAnac();
    fakeMetar();

    // The source credit rides on the final message, so assert over the whole
    // reply rather than its first part.
    expect(implode(' ', bot()->reply('hay notams en ezeiza?')->messages))
        ->toContain('Fuente: ANAC')
        ->not->toContain('METAR SAEZ');
});

/**
 * A forecast is not an observation. Answering "¿qué tiempo va a hacer mañana?"
 * with the current METAR would be confidently wrong, so forecast wording is
 * deliberately not a METAR trigger — it goes to the TAF instead.
 */
it('does not treat a forecast request as a metar request', function () {
    fakeAnac();
    fakeMetar();
    fakeTaf();

    expect(bot()->reply('pronostico para ezeiza')->messages[0])->not->toContain('METAR SAEZ');
});

it('explains the metar in spanish under the raw report', function () {
    fakeAnac();
    fakeMetar();

    expect(bot()->reply('metar ezeiza')->messages[0])
        ->toContain('Qué dice')
        ->toContain('Viento del 030° (NNE) a 9 nudos.')
        ->toContain('Temperatura 15 °C')
        ->toContain('Presión QNH 1009 hPa.');
});

it('flags a SPECI as an off-schedule report', function () {
    fakeAnac();
    fakeMetar(Http::response(smnMetarWith('SPECI SAEZ 271530Z 18015G28KT 3000 +TSRA OVC012 19/18 Q1002 =')));

    expect(bot()->reply('metar ezeiza')->messages[0])
        ->toContain('Informe especial (SPECI)')
        ->toContain('tormenta con lluvia fuerte');
});

it('says so when the aerodrome has no ICAO code to look up', function () {
    fakeAnac();
    fakeMetar();

    // Alta Gracia is in ANAC's registry but has no OACI code, so the SMN has
    // nothing to index an observation by.
    expect(bot()->reply('metar alta gracia')->messages[0])->toContain('no tiene código OACI');

    Http::assertNothingSent();
});

/**
 * The SMN blocks us for stretches at a time. The user still gets their METAR —
 * the same SMN-issued report, relayed by NOAA — and the credit says so rather
 * than passing the relay off as a direct read.
 */
it('still answers with the metar when the SMN is blocking', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('challenge.html'), 403));

    $reply = bot()->reply('metar eze')->messages[0];

    expect($reply)
        ->toContain('METAR SAEZ 271700Z')
        ->toContain('Servicio Meteorológico Nacional')
        ->not->toContain('no pude obtener');
});

it('reports a service problem only when every source is unreachable', function () {
    fakeAnac();
    fakeMetar(Http::response('down', 503), Http::response('down', 503));

    expect(bot()->reply('metar eze')->messages[0])->toContain('no pude obtener su METAR');
});

it('says so when there is no observation published', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    expect(bot()->reply('metar eze')->messages[0])->toContain('No hay METAR publicado');
});

it('offers notams, metar and taf in the help text', function () {
    fakeAnac();

    $help = bot()->reply('')->messages[0];

    expect($help)->toContain('NOTAM')->toContain('METAR')->toContain('TAF');
});

it('keeps every metar message within the twilio limit', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-multi.html')));

    $reply = bot()->reply('metar aeroparque')->messages;

    foreach ($reply as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1500);
    }

    // All four stations survive the split.
    expect(implode(' ', $reply))
        ->toContain('SABE')
        ->toContain('SAME')
        ->toContain('SAWH')
        ->toContain('SACO');
});

/*
|--------------------------------------------------------------------------
| TAF
|--------------------------------------------------------------------------
|
| Forecast wording used to fall through to NOTAMs on purpose, because the only
| weather answer available was an observation and "¿cómo va a estar mañana?"
| deserved better than today's METAR. Now that there is a forecast to hand,
| those same messages route here.
|
*/

it('answers with the taf when the message asks about the forecast', function (string $message) {
    fakeAnac();
    fakeMetar();
    fakeTaf();

    $reply = bot()->reply($message)->messages;

    expect($reply[0])
        ->toContain('TAF SAEZ 271700Z')
        ->toContain('Fuente: Servicio Meteorológico Nacional')
        ->not->toContain('METAR SAEZ');
})->with([
    'the word taf' => ['taf EZE'],
    'asking for a forecast' => ['pronostico para ezeiza'],
    'accented, as typed' => ['¿cuál es el pronóstico de Ezeiza?'],
    'asking about tomorrow' => ['como va a estar el tiempo mañana en ezeiza?'],
    'asking whether it will rain' => ['va a llover en SAEZ?'],
]);

/**
 * The forecast words have to win over the observation words, because a question
 * about tomorrow's weather contains both: "cómo va a estar el tiempo mañana"
 * mentions "tiempo" as much as it mentions "mañana".
 */
it('prefers the forecast when a message asks about both', function () {
    fakeAnac();
    fakeMetar();
    fakeTaf();

    expect(bot()->reply('que tiempo va a haber mañana en ezeiza?')->messages[0])
        ->toContain('TAF SAEZ')
        ->not->toContain('METAR SAEZ');
});

/**
 * Someone who typed "notam" knows what they asked for. Mentioning tomorrow in
 * the same breath must not turn the reply into a weather forecast.
 */
it('keeps answering with notams when the message says notam', function () {
    fakeAnac();
    fakeTaf();

    expect(implode(' ', bot()->reply('hay notams para mañana en ezeiza?')->messages))
        ->toContain('Fuente: ANAC')
        ->not->toContain('TAF SAEZ');
});

it('explains the taf in spanish under the raw forecast', function () {
    fakeAnac();
    fakeTaf();

    expect(bot()->reply('taf ezeiza')->messages[0])
        ->toContain('Qué dice')
        ->toContain('Válido desde el día 27 a las 18:00 hasta el día 28 a las 18:00 UTC.')
        ->toContain('Fluctuaciones temporarias (TEMPO) el día 28 entre las 08:00 y las 12:00 UTC');
});

it('flags an amended forecast', function () {
    fakeAnac();
    fakeTaf(Http::response(smnTafWith('TAF AMD SAEZ 271900Z 2719/2818 18025G40KT 3000 TSRA BKN008CB =')));

    expect(bot()->reply('taf ezeiza')->messages[0])
        ->toContain('Pronóstico enmendado (AMD)')
        ->toContain('tormenta con lluvia');
});

it('flags a cancelled forecast', function () {
    fakeAnac();
    fakeTaf(Http::response(smnTafWith('TAF SAEZ 271700Z 2718/2818 CNL =')));

    expect(bot()->reply('taf ezeiza')->messages[0])->toContain('Pronóstico cancelado (CNL)');
});

it('says so when the aerodrome has no ICAO code for a forecast', function () {
    fakeAnac();
    fakeTaf();

    expect(bot()->reply('pronostico alta gracia')->messages[0])->toContain('no tiene código OACI');

    Http::assertNothingSent();
});

it('still answers with the taf when the SMN is blocking', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    expect(bot()->reply('taf eze')->messages[0])
        ->toContain('TAF SAEZ 271700Z')
        ->toContain('Servicio Meteorológico Nacional')
        ->not->toContain('no pude obtener');
});

it('reports a service problem only when every forecast source is unreachable', function () {
    fakeAnac();
    fakeTaf(Http::response('down', 503), Http::response('down', 503));

    expect(bot()->reply('taf eze')->messages[0])->toContain('no pude obtener su pronóstico TAF');
});

it('says so when there is no forecast published', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('taf-empty.html')));

    expect(bot()->reply('taf eze')->messages[0])->toContain('No hay TAF publicado');
});

it('keeps every taf message within the twilio limit', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('taf-multi.html')));

    $reply = bot()->reply('taf aeroparque')->messages;

    foreach ($reply as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1500);
    }

    // All four stations survive the split.
    expect(implode(' ', $reply))
        ->toContain('SABE')
        ->toContain('SAME')
        ->toContain('SAWH')
        ->toContain('SACO');
});

/*
|--------------------------------------------------------------------------
| PRONAREA
|--------------------------------------------------------------------------
|
| Unlike NOTAM/METAR/TAF, which answer about the aerodrome named, PRONAREA
| answers about the FIR that aerodrome sits in — the aerodrome is only ever
| used to look up which FIR.
|
*/

it('answers with the pronarea for the fir an aerodrome sits in', function () {
    fakeAnac();
    fakePronarea();

    $reply = bot()->reply('pronarea EZE')->messages;

    expect($reply[0])
        ->toContain('PRONAREA FIR EZE')
        ->toContain('SIGFENOM: CIRCULACION DE AIRE HUMEDO')
        ->toContain('Fuente: Servicio Meteorológico Nacional');
});

/**
 * "pronóstico de área" contains "pronostico", which alone reads as a TAF
 * request — PRONAREA_KEYWORDS has to be checked before TAF_KEYWORDS for this
 * not to be misrouted, the same reasoning as the sun keywords.
 */
it('routes the spelled-out phrase to pronarea instead of the forecast', function () {
    fakeAnac();
    fakeTaf();
    fakePronarea();

    expect(bot()->reply('pronostico de area para SIS')->messages[0])
        ->toContain('PRONAREA FIR SIS')
        ->not->toContain('TAF SAEZ');
});

it('says so when the aerodrome is not one the SMN covers with pronarea', function () {
    fakeAnac();

    expect(bot()->reply('pronarea alta gracia')->messages[0])
        ->toContain('no está entre los aeródromos')
        ->toContain('PRONAREA');

    Http::assertNothingSent();
});

it('reports a service problem when the smn cannot be reached and nothing was cached', function () {
    fakeAnac();
    fakePronarea(Http::response('down', 503));

    expect(bot()->reply('pronarea EZE')->messages[0])->toContain('No pude consultar el PRONAREA');
});

it('warns when serving a stale bulletin instead of failing outright', function () {
    fakeAnac();

    // A sequence rather than two fakePronarea() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one.
    Http::fake([
        '*observacion=pronarea*' => Http::sequence()
            ->push(smnFixture('pronarea-eze.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    bot()->reply('pronarea EZE');

    Cache::forget('pronarea:EZE');

    expect(bot()->reply('pronarea EZE')->messages[0])
        ->toContain('No pude confirmar si sigue vigente')
        ->toContain('PRONAREA FIR EZE VALIDEZ 1604');
});

/**
 * PRONAREA is not offered as a quick-reply action, by design — it only ever
 * answers a typed question, unlike NOTAM/METAR/TAF/crepúsculo which all also
 * offer each other after answering.
 */
it('never offers a follow-up menu for a pronarea answer', function () {
    fakeAnac();
    fakePronarea();
    withButtonTemplates();

    expect(bot()->reply('pronarea EZE', PHONE)->menu)->toBeNull();
});

/**
 * There is no "ask:pronarea:..." button anywhere for WhatsApp to echo back,
 * but the payload grammar is user-reachable regardless of provenance — a
 * stale client, a crafted webhook — and must degrade the same way any other
 * unrecognised payload does: back to the ordinary text path.
 */
it('falls back to the text path for a pronarea menu payload, since none is ever sent', function () {
    fakeAnac();

    expect(bot()->reply('aeroparque', PHONE, 'ask:pronarea:SAEZ')->messages[0])->toContain('(AER)');
});

/*
|--------------------------------------------------------------------------
| AEROMET
|--------------------------------------------------------------------------
|
| AEROMET is resolved by station name straight from the message, not by ANAC
| aerodrome — its network also covers towns with no aerodrome at all, so it
| never touches AirportResolver/matchIndicator the way NOTAM/METAR/TAF do.
|
*/

it('answers with the aeromet observation for the station named, explained like a metar', function () {
    fakeAeromet();

    $reply = bot()->reply('aeromet junin')->messages;

    expect($reply[0])
        ->toContain('AEROMET JUNIN')
        ->toContain('JUNIN 090/06KT 12KM 4Ci19800FT 16/07 Q1018.4')
        ->toContain('Viento del 090° a 6 nudos.')
        ->toContain('Nubes: 4/8 Cirrus a 19.800 ft.')
        ->toContain('Fuente: Servicio Meteorológico Nacional');

    // fakeAeromet() only stubs the AEROMET endpoint — this confirms the
    // aerodrome/ANAC lookup path was never touched.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ais.anac.gob.ar'));
});

/**
 * "NIN" is Junín's own ANAC code (see database/seeders/data/airports.php) —
 * AerometStationResolver never looks at ANAC codes on its own, so this only
 * resolves by bridging through AirportResolver.
 */
it('resolves an aeromet station named by its anac or oaci code', function () {
    fakeAeromet();

    expect(bot()->reply('aeromet nin')->messages[0])->toContain('AEROMET JUNIN');
});

/**
 * The SMN's own gloss of a phenomenon ("Lluvia. Continua...") is folded in
 * as its own explanation line rather than guessed at from the abbreviation.
 */
it('folds in the smn own gloss of a weather phenomenon', function () {
    fakeAeromet(Http::response(smnFixture('aeromet-neuquen.html')));

    expect(bot()->reply('aeromet neuquen')->messages[0])
        ->toContain('Fenómeno: Lluvia. Continua, no congelandose, debil en el momento de la observacion.');
});

it('says so when no aeromet station is named in the message', function () {
    Http::fake();

    expect(bot()->reply('aeromet')->messages[0])
        ->toContain('No encontré ninguna estación AEROMET');

    Http::assertNothingSent();
});

it('reports a service problem when the smn cannot be reached for aeromet and nothing was cached', function () {
    fakeAeromet(Http::response('down', 503));

    expect(bot()->reply('aeromet junin')->messages[0])->toContain('No pude obtener el AEROMET');
});

it('warns when serving a stale aeromet observation instead of failing outright', function () {
    // A sequence rather than two fakeAeromet() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one.
    Http::fake([
        '*observacion=aeromet*' => Http::sequence()
            ->push(smnFixture('aeromet-junin.html'))
            ->push(smnFixture('challenge.html'), 403)
            ->push(smnFixture('challenge.html'), 403),
    ]);

    bot()->reply('aeromet junin');

    Cache::forget('aeromet:0');

    expect(bot()->reply('aeromet junin')->messages[0])
        ->toContain('No pude confirmar si sigue vigente')
        ->toContain('JUNIN 090/06KT 12KM 4Ci19800FT 16/07 Q1018.4');
});

/**
 * AEROMET is not offered as a quick-reply action, same reasoning as
 * PRONAREA: it only ever answers a typed question.
 */
it('never offers a follow-up menu for an aeromet answer', function () {
    fakeAeromet();
    withButtonTemplates();

    expect(bot()->reply('aeromet junin', PHONE)->menu)->toBeNull();
});

/**
 * There is no "ask:aeromet:..." button anywhere for WhatsApp to echo back,
 * but the payload grammar is user-reachable regardless of provenance, and
 * must degrade the same way any other unrecognised payload does.
 */
it('falls back to the text path for an aeromet menu payload, since none is ever sent', function () {
    fakeAnac();

    expect(bot()->reply('aeroparque', PHONE, 'ask:aeromet:SAEZ')->messages[0])->toContain('(AER)');
});

/*
|--------------------------------------------------------------------------
| Alertas
|--------------------------------------------------------------------------
|
| The subscription topics are the only ones that need to know who is asking,
| and the only ones that write anything down. PHONE stands in for whatever
| Twilio would send as "From".
|
*/

const PHONE = 'whatsapp:+5491122334455';

it('offers the watch button under an observation', function () {
    fakeMetar();
    config(['services.twilio.content_sid_metar' => 'HXtest']);

    $reply = bot()->reply('metar EZE', PHONE);

    expect($reply->button)->not->toBeNull()
        ->and($reply->button->contentSid)->toBe('HXtest')
        ->and($reply->button->payloadValue)->toBe('SAEZ');
});

/**
 * Off-channel there is nobody to write back to, so there is nothing to offer.
 */
it('does not offer the watch button when there is no sender', function () {
    fakeMetar();

    expect(bot()->reply('metar EZE')->button)->toBeNull();
});

it('does not offer a watch that is already running', function () {
    fakeMetar();
    config(['services.twilio.content_sid_metar' => 'HXtest']);

    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR SAEZ 271400Z 03009KT 9999 SCT020 15/14 Q1009',
    ]);

    $reply = bot()->reply('metar EZE', PHONE);

    expect($reply->button)->toBeNull()
        ->and(implode(' ', $reply->messages))->toContain('Ya te estoy avisando');
});

it('subscribes from a written request and answers with the baseline observation', function () {
    fakeMetar();

    $reply = bot()->reply('avisame si cambia el clima en EZE', PHONE);

    $subscription = MetarSubscription::query()->firstOrFail();

    expect($subscription->phone)->toBe(PHONE)
        ->and($subscription->anac_code)->toBe('EZE')
        ->and($subscription->icao_code)->toBe('SAEZ')
        ->and($subscription->last_raw)->toContain('METAR SAEZ 271400Z')
        ->and($reply->messages[0])->toContain('Te aviso si cambia el METAR')
        // The report the comparison starts from travels with the confirmation,
        // so the user can see it rather than take it on trust.
        ->and(implode(' ', $reply->messages))->toContain('METAR SAEZ 271400Z');
});

/**
 * A message mentioning the weather *and* asking to be told about changes is a
 * subscription, not a METAR — the METAR words are in it by necessity.
 */
it('does not answer a subscription request with today observation only', function () {
    fakeMetar();

    expect(bot()->reply('avisame si cambia el clima en EZE', PHONE)->messages[0])
        ->toContain('Te aviso si cambia');
});

it('subscribes from a tapped button without any keyword matching', function () {
    fakeMetar();

    bot()->reply('🔔 Avisarme 12 h', PHONE, 'sub:SAEZ:12');

    expect(MetarSubscription::query()->where('anac_code', 'EZE')->exists())->toBeTrue();
});

it('honours the duration on the button payload', function () {
    fakeMetar();

    bot()->reply('', PHONE, 'sub:SAEZ:12');

    expect(MetarSubscription::query()->firstOrFail()->expires_at)
        ->toBeBetween(now()->addHours(11), now()->addHours(13));
});

it('reads a duration out of the message', function () {
    fakeMetar();

    bot()->reply('avisame EZE por 6 horas', PHONE);

    expect(MetarSubscription::query()->firstOrFail()->expires_at)
        ->toBeBetween(now()->addHours(5), now()->addHours(7));
});

/**
 * Past 24 hours WhatsApp stops letting us write to the user at all, so a
 * longer request is capped rather than honoured or refused.
 */
it('caps a request longer than the messaging window allows', function () {
    fakeMetar();

    bot()->reply('avisame EZE por 72 horas', PHONE);

    expect(MetarSubscription::query()->firstOrFail()->expires_at)
        ->toBeBetween(now()->addHours(23), now()->addHours(25));
});

it('renews an existing watch instead of stacking a second one', function () {
    fakeMetar();

    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addMinutes(5),
        'last_raw' => 'stale',
        'last_notified_at' => now()->subHour(),
    ]);

    bot()->reply('', PHONE, 'sub:SAEZ:12');

    $subscriptions = MetarSubscription::query()->get();

    expect($subscriptions)->toHaveCount(1)
        ->and($subscriptions[0]->expires_at)->toBeGreaterThan(now()->addHours(11))
        // The baseline restarts from the report just shown, so what was sent
        // under the previous watch says nothing about this one.
        ->and($subscriptions[0]->last_raw)->toContain('METAR SAEZ 271400Z')
        ->and($subscriptions[0]->last_notified_at)->toBeNull();
});

it('refuses a sixth watch and says which ones to drop', function () {
    fakeMetar();

    foreach (['AER', 'BAR', 'CBA', 'MDQ', 'MDZ'] as $code) {
        MetarSubscription::create([
            'phone' => PHONE,
            'anac_code' => $code,
            'icao_code' => 'SA'.substr($code, 0, 2),
            'expires_at' => now()->addHours(6),
            'last_raw' => 'METAR',
        ]);
    }

    $reply = bot()->reply('avisame EZE', PHONE);

    expect($reply->messages[0])
        ->toContain('máximo')
        ->toContain('*AER*')
        ->and(MetarSubscription::query()->where('anac_code', 'EZE')->exists())->toBeFalse();
});

/**
 * A watch with no baseline would compare against nothing on its first round
 * and alert on anything at all, so it is better not to exist.
 */
it('does not create a watch it cannot establish a baseline for', function () {
    fakeMetar(Http::response(smnFixture('challenge.html'), 403), Http::response('down', 503));

    expect(bot()->reply('avisame EZE', PHONE)->messages[0])->toContain('no puedo activar la alerta')
        ->and(MetarSubscription::query()->count())->toBe(0);
});

it('does not watch an aerodrome the SMN publishes nothing for', function () {
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    expect(bot()->reply('avisame EZE', PHONE)->messages[0])->toContain('no tengo desde dónde comparar')
        ->and(MetarSubscription::query()->count())->toBe(0);
});

it('says so when the aerodrome has no ICAO code to watch', function () {
    expect(bot()->reply('avisame alta gracia', PHONE)->messages[0])->toContain('no tiene código OACI');

    Http::assertNothingSent();
});

/**
 * Only observations are watched. Quietly setting up a METAR alert for someone
 * who asked about NOTAMs would leave them wondering why the messages that
 * arrive are about the wind.
 */
it('says it cannot watch notams', function () {
    expect(bot()->reply('avisame si hay notams nuevos en EZE', PHONE)->messages[0])
        ->toContain('sólo puedo avisarte cuando cambia el *METAR*');

    expect(MetarSubscription::query()->count())->toBe(0);
});

it('unsubscribes from a written request', function () {
    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR',
    ]);

    expect(bot()->reply('no me avises más de EZE', PHONE)->messages[0])->toContain('no te aviso más')
        ->and(MetarSubscription::query()->count())->toBe(0);
});

it('unsubscribes from a tapped button', function () {
    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR',
    ]);

    bot()->reply('🔕 Dar de baja', PHONE, 'unsub:SAEZ');

    expect(MetarSubscription::query()->count())->toBe(0);
});

it('drops the only watch when the request names no aerodrome', function () {
    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR',
    ]);

    bot()->reply('dar de baja', PHONE);

    expect(MetarSubscription::query()->count())->toBe(0);
});

/**
 * With several running, guessing which one to cancel would silence exactly the
 * aerodrome the user still wanted.
 */
it('asks which watch to drop when several are running', function () {
    foreach (['EZE', 'AER'] as $code) {
        MetarSubscription::create([
            'phone' => PHONE,
            'anac_code' => $code,
            'icao_code' => 'SA'.substr($code, 0, 2),
            'expires_at' => now()->addHours(6),
            'last_raw' => 'METAR',
        ]);
    }

    expect(bot()->reply('dar de baja', PHONE)->messages[0])
        ->toContain('¿De cuál querés darte de baja?')
        ->toContain('*EZE*')
        ->toContain('*AER*')
        ->and(MetarSubscription::query()->count())->toBe(2);
});

it('drops every watch when asked for all of them', function () {
    foreach (['EZE', 'AER'] as $code) {
        MetarSubscription::create([
            'phone' => PHONE,
            'anac_code' => $code,
            'icao_code' => 'SA'.substr($code, 0, 2),
            'expires_at' => now()->addHours(6),
            'last_raw' => 'METAR',
        ]);
    }

    bot()->reply('baja todas', PHONE);

    expect(MetarSubscription::query()->count())->toBe(0);
});

it('lists the running watches without needing an aerodrome', function () {
    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR',
    ]);

    expect(bot()->reply('mis alertas', PHONE)->messages[0])
        ->toContain('Tus alertas de METAR')
        ->toContain('(SAEZ)');

    Http::assertNothingSent();
});

it('says so when there are no watches to list', function () {
    expect(bot()->reply('mis alertas', PHONE)->messages[0])->toContain('No tenés alertas activas');
});

it('ignores expired watches when listing', function () {
    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->subMinute(),
        'last_raw' => 'METAR',
    ]);

    expect(bot()->reply('mis alertas', PHONE)->messages[0])->toContain('No tenés alertas activas');
});

it('keeps one phone watches out of another', function () {
    MetarSubscription::create([
        'phone' => 'whatsapp:+5499999999999',
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR',
    ]);

    expect(bot()->reply('mis alertas', PHONE)->messages[0])->toContain('No tenés alertas activas');
});

/**
 * A malformed payload is not a reason to fail: fall through to reading the
 * message as text, which is what the user typed anyway.
 */
it('falls back to the text path when the button payload makes no sense', function () {
    fakeAnac();

    expect(bot()->reply('aeroparque', PHONE, 'nonsense')->messages[0])->toContain('(AER)');
});

it('explains that alerts need whatsapp when there is no sender', function () {
    expect(bot()->reply('avisame EZE')->messages[0])->toContain('sólo funcionan por WhatsApp');

    expect(MetarSubscription::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El aviso de cambio
|--------------------------------------------------------------------------
*/

it('builds a change alert with what changed above the report', function () {
    config(['services.twilio.content_sid_alert' => 'HXalert']);

    $reply = bot()->changeAlert(
        'EZE',
        new Metar(
            station: 'SAEZ',
            airportName: 'EZEIZA',
            observedAt: '27 - 14:00',
            raw: 'METAR SAEZ 271400Z 20018G30KT 4000 -RA BKN008 21/17 Q1010',
        ),
        ['Categoría de vuelo: VFR → IFR.', 'Visibilidad: 10 km o más → 4000 m.'],
        '28/02 02:00 UTC',
    );

    $body = implode("\n", $reply->messages);

    expect($body)
        ->toContain('cambió el clima')
        ->toContain('Qué cambió')
        ->toContain('Categoría de vuelo: VFR → IFR.')
        ->toContain('METAR SAEZ 271400Z 20018G30KT')
        // Decoded in Spanish under the raw report, like every other answer.
        ->toContain('Qué dice')
        ->toContain('Alerta vigente hasta el 28/02 02:00 UTC')
        ->and($reply->button?->contentSid)->toBe('HXalert')
        ->and($reply->button?->payloadValue)->toBe('SAEZ');
});

/**
 * A templated body is capped at 1024 characters by WhatsApp, not the 1600 that
 * applies to free text — and every alert carries a button.
 */
it('keeps every alert message inside the template body limit', function () {
    $reply = bot()->changeAlert(
        'EZE',
        new Metar(
            station: 'SAEZ',
            airportName: 'EZEIZA',
            observedAt: '27 - 14:00',
            raw: 'METAR SAEZ 271400Z 20018G30KT 4000 R11/1200 R29/0800 -TSRA BR BKN008 OVC015CB 21/17 Q1010 WS ALL RWY RMK PP021',
        ),
        array_fill(0, 12, 'Visibilidad: 10 km o más → 4000 m.'),
        '28/02 02:00 UTC',
    );

    foreach ($reply->messages as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1024);
    }
});

/*
|--------------------------------------------------------------------------
| Crepúsculo
|--------------------------------------------------------------------------
|
| The one answer that is about a city instead of an aerodrome, because that is
| how the SHN publishes it. The routing is what these guard: a sun question must
| never be read as a forecast, as an alert, or as a NOTAM query.
|
*/

it('answers the sun times for a city', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    $reply = bot()->reply('crepusculo santa rosa')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('SANTA ROSA')
        ->toContain('Crepúsculo matutino: 11:01 UTC (08:01 local)')
        ->toContain('Salida del sol: 11:30 UTC (08:30 local)')
        ->toContain('Puesta del sol: 21:12 UTC (18:12 local)')
        ->toContain('Crepúsculo vespertino: 21:40 UTC (18:40 local)')
        ->toContain('Servicio de Hidrografía Naval');

    Carbon::setTestNow();
});

/**
 * The day is the one on the asker's calendar. At 22:00 in Argentina it is
 * already tomorrow in UTC, and answering with tomorrow's sunset would be wrong
 * by a day for the one person most likely to be asking: someone flying at night.
 */
it('takes today in Argentine time and not in UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-02 01:00:00', 'UTC'));
    fakeShnSun();

    // 01:00 UTC on the 2nd is 22:00 on the 1st in Argentina.
    expect(bot()->reply('crepusculo santa rosa')->messages[0])->toContain('hoy, 01/07');

    Carbon::setTestNow();
});

it('answers the sun times for tomorrow when asked', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    $reply = bot()->reply('crepusculo mañana en santa rosa')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('mañana, 02/07')
        ->toContain('Crepúsculo vespertino: 21:41 UTC (18:41 local)');

    Carbon::setTestNow();
});

it('answers the sun times for yesterday when asked', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-02 15:00:00', 'UTC'));
    fakeShnSun();

    $reply = bot()->reply('crepusculo ayer santa rosa')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('ayer, 01/07')
        ->toContain('Crepúsculo vespertino: 21:40 UTC (18:40 local)');

    Carbon::setTestNow();
});

it('answers the sun times for an explicit date', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    $reply = bot()->reply('crepusculo santa rosa 03/07')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('03/07')
        ->not->toContain('hoy')
        ->not->toContain('mañana');

    Carbon::setTestNow();
});

it('answers the sun times for an aerodrome named by ICAO code, in either word order', function (string $message) {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    expect(bot()->reply($message)->messages[0])
        ->toContain('SANTA ROSA')
        ->toContain('Crepúsculo matutino');

    Carbon::setTestNow();
})->with([
    'code first' => ['SAZR Salida Y puesta de sol'],
    'code last' => ['Salida Y puesta de sol sazr'],
]);

it('routes sun questions ahead of the forecast and the alerts', function (string $message) {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    expect(bot()->reply($message, 'whatsapp:+5491100000000')->messages[0])
        ->toContain('Crepúsculo matutino')
        ->not->toContain('Ya te estoy avisando');

    Carbon::setTestNow();
})->with([
    // Carries a TAF keyword ("mañana") and would otherwise be answered with a forecast.
    'tomorrow' => ['a que hora anochece mañana en santa rosa'],
    // Carries a subscription keyword, but a twilight has nothing to watch.
    'avisame' => ['avisame a que hora atardece en santa rosa'],
]);

it('names the localities it has when the message has none', function () {
    fakeShnSun();

    $reply = bot()->reply('crepusculo tandil')->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('Ciudades disponibles')
        ->toContain('SANTA ROSA')
        ->toContain('USHUAIA');

    Http::assertNothingSent();
});

it('says so when the SHN cannot be reached', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun(Http::response('Server Error', 500));

    expect(bot()->reply('crepusculo santa rosa')->messages[0])
        ->toContain('No pude consultar')
        ->toContain('Hidrografía Naval');

    Carbon::setTestNow();
});

/**
 * The sun keywords sit first in the topic match, which is exactly the kind of
 * change that quietly swallows the topics under it.
 */
it('still answers NOTAMs for a city that also has sun data', function () {
    fakeAnac();

    expect(bot()->reply('notams santa rosa')->messages[0])->toContain('(OSA)');
});

/*
|--------------------------------------------------------------------------
| El menú de seguimiento
|--------------------------------------------------------------------------
|
| After answering about an aerodrome, the bot offers the other three topics
| as a second, short message with its own button — never merged onto the
| answer itself (the METAR already spends its own button on the watch
| offer), and never sent at all without a registered template for it.
|
*/

it('offers the other topics after answering about an aerodrome', function (string $message, string $sid) {
    fakeAnac();
    fakeMetar();
    fakeTaf();
    withButtonTemplates();

    $reply = bot()->reply($message, PHONE);

    expect($reply->menu)->not->toBeNull()
        ->and($reply->menu->button->contentSid)->toBe($sid)
        ->and($reply->menu->button->payloadValue)->toBe('SAEZ')
        ->and($reply->menu->body)->toContain('EZEIZA');
})->with([
    'notam' => ['notams EZE', 'HXmenunotam'],
    'metar' => ['metar EZE', 'HXmenumetar'],
    'taf' => ['taf EZE', 'HXmenutaf'],
]);

it('offers both the watch and the menu under an observation', function () {
    fakeMetar();
    withButtonTemplates();

    $reply = bot()->reply('metar EZE', PHONE);

    expect($reply->button?->contentSid)->toBe('HXsub')
        ->and($reply->menu?->button->contentSid)->toBe('HXmenumetar');
});

it('does not offer a menu when no template is registered', function () {
    fakeAnac();

    expect(bot()->reply('notams EZE', PHONE)->menu)->toBeNull();
});

it('does not offer a menu off-channel', function () {
    fakeAnac();
    withButtonTemplates();

    expect(bot()->reply('notams EZE')->menu)->toBeNull();
});

it('does not offer a menu with a subscription, a listing or an alert', function () {
    fakeMetar();
    withButtonTemplates();

    expect(bot()->reply('avisame EZE', PHONE)->menu)->toBeNull()
        ->and(bot()->reply('', PHONE, 'sub:SAEZ:12')->menu)->toBeNull()
        ->and(bot()->reply('mis alertas', PHONE)->menu)->toBeNull()
        ->and(bot()->reply('baja EZE', PHONE)->menu)->toBeNull();

    config(['services.twilio.content_sid_alert' => 'HXalert']);

    $alert = bot()->changeAlert(
        'EZE',
        new Metar(station: 'SAEZ', airportName: 'EZEIZA', observedAt: '27 - 14:00', raw: 'METAR SAEZ 271400Z 18008KT 9999 SCT020 22/14 Q1013'),
        ['Categoría de vuelo: VFR → IFR.'],
        '28/02 02:00 UTC',
    );

    expect($alert->menu)->toBeNull();
});

it('does not offer a menu when it could not answer', function () {
    withButtonTemplates();

    fakeAnac(Http::response('down', 503));
    expect(bot()->reply('notams EZE', PHONE)->menu)->toBeNull();

    fakeMetar(Http::response('Server Error', 500), Http::response('Server Error', 500));
    expect(bot()->reply('metar EZE', PHONE)->menu)->toBeNull();

    fakeTaf(Http::response('Server Error', 500), Http::response('Server Error', 500));
    expect(bot()->reply('taf EZE', PHONE)->menu)->toBeNull();

    fakeShnSun(Http::response('Server Error', 500));
    expect(bot()->reply('crepusculo santa rosa', PHONE)->menu)->toBeNull();

    expect(bot()->reply('metar alta gracia', PHONE)->menu)->toBeNull();
});

it('does not offer a menu with the help text or a disambiguation', function () {
    fakeAnac();
    withButtonTemplates();

    foreach (['TWA', 'TWB'] as $code) {
        Airport::create(['anac_code' => $code, 'name' => 'VILLA ZURUMBAMBA', 'access' => 'publico']);
    }

    expect(bot()->reply('cual es la capital de francia', PHONE)->menu)->toBeNull()
        ->and(bot()->reply('zurumbamba', PHONE)->menu)->toBeNull();
});

it('answers a tapped menu button without any keyword matching', function (string $payload, string $expected) {
    fakeAnac();
    fakeMetar();
    fakeTaf();
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    // The caption is passed as the message, to prove it is never parsed —
    // only the payload drives the answer.
    expect(implode(' ', bot()->reply('🔭 TAF', PHONE, $payload)->messages))->toContain($expected);

    Carbon::setTestNow();
})->with([
    'notam' => ['ask:notam:SAEZ', 'Fuente: ANAC'],
    'metar' => ['ask:metar:SAEZ', 'METAR SAEZ'],
    'taf' => ['ask:taf:SAEZ', 'TAF SAEZ'],
    'crepusculo' => ['ask:crepusculo:SAZR', 'SANTA ROSA'],
]);

it('keeps offering the other topics after a tapped one', function () {
    fakeAnac();
    withButtonTemplates();

    $reply = bot()->reply('✈️ NOTAMs', PHONE, 'ask:notam:SAEZ');

    expect($reply->menu?->button->contentSid)->toBe('HXmenunotam');
});

it('offers the watch again under a metar reached by tapping', function () {
    fakeMetar();
    withButtonTemplates();

    $reply = bot()->reply('🌦️ METAR', PHONE, 'ask:metar:SAEZ');

    expect($reply->button?->contentSid)->toBe('HXsub')
        ->and($reply->menu?->button->contentSid)->toBe('HXmenumetar');
});

it('carries an aerodrome without an icao code in the menu payload', function () {
    fakeAnac();
    withButtonTemplates();

    expect(bot()->reply('notams alta gracia', PHONE)->menu?->button->payloadValue)->toBe('AGR');
});

it('answers honestly when a tapped topic has nothing for that aerodrome', function () {
    Http::fake();

    expect(bot()->reply('🌦️ METAR', PHONE, 'ask:metar:AGR')->messages[0])->toContain('no tiene código OACI');

    Http::assertNothingSent();
});

it('names the cities it has when a tapped aerodrome has no sun table', function () {
    Http::fake();

    $reply = bot()->reply('🌅 Crepúsculo', PHONE, 'ask:crepusculo:AGR')->messages;

    expect(implode(' ', $reply))
        ->toContain('Ciudades disponibles')
        ->toContain('SANTA ROSA');

    Http::assertNothingSent();
});

it('falls back to the text path when a menu payload names an unknown topic', function () {
    fakeAnac();

    expect(bot()->reply('aeroparque', PHONE, 'ask:humedad:SAEZ')->messages[0])->toContain('(AER)');
});

it('does not shrink the answer to fit the menu', function () {
    fakeTaf(Http::response(smnFixture('taf-multi.html')));

    $withoutTemplate = bot()->reply('taf aeroparque', PHONE)->messages;

    withButtonTemplates();
    $withTemplate = bot()->reply('taf aeroparque', PHONE)->messages;

    expect($withTemplate)->toBe($withoutTemplate);

    foreach ($withTemplate as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1500);
    }
});

it('offers the menu for a sun answer that names an aerodrome', function (string $message) {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();
    withButtonTemplates();

    $reply = bot()->reply($message, PHONE);

    expect($reply->menu)->not->toBeNull()
        ->and($reply->menu->button->contentSid)->toBe('HXmenusol')
        ->and($reply->menu->button->payloadValue)->toBe('SAZR');

    Carbon::setTestNow();
})->with([
    'by code' => ['crepusculo SAZR'],
    'by city name' => ['crepusculo santa rosa'],
]);

/**
 * "crepusculo base esperanza" resolves the Antarctic locality by alias while
 * the aerodrome matcher lands on the Esperanza in Santa Fe — offering that
 * one's NOTAMs under an Antarctic sunset would be wrong by 3.500 km, so no
 * menu is offered even though the sun answer itself is correct.
 */
it('offers no menu when the aerodrome named does not serve the city answered', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();
    withButtonTemplates();

    $reply = bot()->reply('crepusculo base esperanza', PHONE);

    expect($reply->menu)->toBeNull()
        ->and($reply->messages[0])->toContain('ESPERANZA');

    Carbon::setTestNow();
});
