<?php

use App\DataObjects\Metar;
use App\Models\Airport;
use App\Models\MetarSubscription;
use App\Models\Runway;
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

/**
 * Asserted on what the bot *resolved* rather than on the reply text, because
 * these messages no longer all route to the same topic — a bare name now
 * answers with the ficha and "notams saez" with the NOTAMs, and which aerodrome
 * was understood is the one thing both have to agree on.
 */
it('matches an airport from free text', function (string $message, string $expectedCode) {
    fakeAnac();

    $bot = bot();
    $bot->reply($message);

    expect($bot->lastContext()->anacCode)->toBe($expectedCode);
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

    expect(bot()->reply('cordoba')->messages[0])->toContain('CBA / SACO');
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

    expect(bot()->reply('CBA')->messages[0])->toContain('CBA / SACO');
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

    $reply = bot()->reply('notams aeroparque')->messages;

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
    expect(bot()->reply('notams aeroparque')->messages[0])->toContain('Pista 13/31 cerrada');
});

/**
 * A long NOTAM used to be truncated with an ellipsis, silently dropping
 * whatever came last — often the closure window or a contact number.
 */
it('splits a long notam across messages without losing text', function () {
    $tail = 'CONTACTO TEL 011-5555-9999 PARA COORDINAR';
    $long = str_repeat('OBST CRANE ERECTED NEAR THR RWY 13 HGT 45M AGL. ', 80).$tail;

    fakeAnac(Http::response(pibWith($long)));

    $reply = bot()->reply('notams aeroparque')->messages;

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

    expect(bot()->reply('notam eze')->messages[0])->toContain('no pude obtener sus NOTAM');
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

/**
 * JUNÍN (SAAJ) is also an AEROMET station under the same name — see
 * AerometStationResolver::STATIONS — so an empty METAR for it offers a way
 * to try the SMN's wider network instead of a dead end.
 *
 * The aerodrome rides in the payload alongside the station code: a station
 * covers a locality, and the answer behind the tap has to know which
 * aerodrome's runways the question was about.
 */
it('offers to check AEROMET under a metar that came back empty', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-empty.html')));
    config(['services.twilio.content_sid_aeromet' => 'HXaeromet']);

    $reply = bot()->reply('metar junin');

    expect($reply->button)->not->toBeNull()
        ->and(buttonIds($reply->button))->toBe(['aeromet:87548:NIN']);
});

/**
 * GENERAL ACHA (SAEA) is nowhere in AEROMET's 119-station list, under its own
 * name or its locality's, so there is nothing honest to offer and no button
 * rides on the message.
 */
it('does not offer AEROMET when the aerodrome is not one of its stations', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-empty.html')));
    config(['services.twilio.content_sid_aeromet' => 'HXaeromet']);

    $reply = bot()->reply('metar general acha');

    expect($reply->messages[0])->toContain('No hay METAR publicado')
        ->and($reply->button)->toBeNull();
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

    expect(bot()->reply('notams aeroparque', PHONE, 'ask:pronarea:SAEZ')->messages[0])->toContain('(AER)');
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

it('answers with the aeromet observation for the station named, explained via synop decoding', function () {
    fakeAeromet();

    $reply = bot()->reply('aeromet junin')->messages;

    expect($reply[0])
        ->toContain('AEROMET JUNIN')
        ->toContain('🕐 Observación de las 30 - 17:00 UTC.')
        ->toContain('AAXX 30174 87548')
        ->toContain('Viento del 050° a 14 nudos.')
        ->toContain('Presión QNH 1.016,2 hPa.')
        ->toContain('Fuente: Servicio Meteorológico Nacional');

    // fakeAeromet() only stubs OGIMET — this confirms the aerodrome/ANAC
    // lookup path was never touched.
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

it('says so when no aeromet station is named in the message', function () {
    Http::fake();

    expect(bot()->reply('aeromet')->messages[0])
        ->toContain('No encontré ninguna estación AEROMET');

    Http::assertNothingSent();
});

it('reports a service problem when ogimet cannot be reached and nothing was cached', function () {
    fakeAeromet(Http::response('', 503));

    expect(bot()->reply('aeromet junin')->messages[0])->toContain('No pude obtener el AEROMET');
});

it('warns when serving a stale aeromet observation instead of failing outright', function () {
    // A sequence rather than two fakeAeromet() calls: Http::fake() merges
    // stubs and the first match wins, so a later fake cannot override an
    // earlier one.
    Http::fake([
        '*ogimet.com*' => Http::sequence()
            ->push(ogimetFixture('ogimet-junin.txt'))
            ->push('', 503),
    ]);

    bot()->reply('aeromet junin');

    Cache::forget('aeromet:0');

    expect(bot()->reply('aeromet junin')->messages[0])
        ->toContain('No pude confirmar si sigue vigente')
        ->toContain('AAXX 30174 87548');
});

/**
 * AEROMET is not offered as a quick-reply action, same reasoning as
 * PRONAREA: it only ever answers a typed question.
 */
it('never offers a follow-up menu for an aeromet answer', function () {
    fakeAeromet();

    expect(bot()->reply('aeromet junin', PHONE)->menu)->toBeNull();
});

/**
 * There is no "ask:aeromet:..." button anywhere for WhatsApp to echo back,
 * but the payload grammar is user-reachable regardless of provenance, and
 * must degrade the same way any other unrecognised payload does.
 */
it('falls back to the text path for an aeromet menu payload, since none is ever sent', function () {
    fakeAnac();

    expect(bot()->reply('notams aeroparque', PHONE, 'ask:aeromet:SAEZ')->messages[0])->toContain('(AER)');
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
        ->and(buttonIds($reply->button))->toBe(['sub:SAEZ:12', 'pista:SAEZ']);
});

/**
 * Off-channel there is nobody to write back to, so there is nothing to offer.
 */
it('does not offer the watch button when there is no sender', function () {
    fakeMetar();

    expect(bot()->reply('metar EZE')->button)->toBeNull();
});

/**
 * The watch offer becomes a line of text once a watch is running — tapping a
 * button that promised something already true would look like it had failed.
 * The runway-wind offer is not like that and stays, which is why it has a
 * template of its own.
 */
it('does not offer a watch that is already running', function () {
    fakeMetar();
    config([
        'services.twilio.content_sid_metar' => 'HXtest',
        'services.twilio.content_sid_pista' => 'HXpista',
    ]);

    MetarSubscription::create([
        'phone' => PHONE,
        'anac_code' => 'EZE',
        'icao_code' => 'SAEZ',
        'expires_at' => now()->addHours(6),
        'last_raw' => 'METAR SAEZ 271400Z 03009KT 9999 SCT020 15/14 Q1009',
    ]);

    $reply = bot()->reply('metar EZE', PHONE);

    expect(buttonIds($reply->button))->toBe(['pista:SAEZ'])
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

    expect(bot()->reply('notams aeroparque', PHONE, 'nonsense')->messages[0])->toContain('(AER)');
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
        ->and(buttonIds($reply->button))->toBe(['unsub:SAEZ']);
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
| as a second, short message with its own buttons — never merged onto the
| answer itself, because WhatsApp renders three per message and the METAR
| already spends its own on the watch and runway offers.
|
*/

it('offers the other topics after answering about an aerodrome', function (string $message, array $offers) {
    fakeAnac();
    fakeMetar();
    fakeTaf();

    $reply = bot()->reply($message, PHONE);

    expect($reply->menu)->not->toBeNull()
        ->and(buttonIds($reply->menu->button))->toBe($offers)
        ->and($reply->menu->body)->toContain('EZEIZA');
})->with([
    'notam' => ['notams EZE', ['ask:metar:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']],
    'metar' => ['metar EZE', ['ask:notam:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']],
    'taf' => ['taf EZE', ['ask:notam:SAEZ', 'ask:metar:SAEZ', 'ask:crepusculo:SAEZ']],
]);

it('offers both the watch and the menu under an observation', function () {
    fakeMetar();

    $reply = bot()->reply('metar EZE', PHONE);

    expect(buttonIds($reply->button))->toBe(['sub:SAEZ:12', 'pista:SAEZ'])
        ->and(buttonIds($reply->menu?->button))->toBe(['ask:notam:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']);
});

it('does not offer a menu off-channel', function () {
    fakeAnac();

    expect(bot()->reply('notams EZE')->menu)->toBeNull();
});

it('does not offer a menu with a subscription, a listing or an alert', function () {
    fakeMetar();

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

    $reply = bot()->reply('✈️ NOTAMs', PHONE, 'ask:notam:SAEZ');

    expect(buttonIds($reply->menu?->button))->toBe(['ask:metar:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']);
});

it('offers the watch again under a metar reached by tapping', function () {
    fakeMetar();

    $reply = bot()->reply('🌦️ METAR', PHONE, 'ask:metar:SAEZ');

    expect(buttonIds($reply->button))->toBe(['sub:SAEZ:12', 'pista:SAEZ'])
        ->and(buttonIds($reply->menu?->button))->toBe(['ask:notam:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']);
});

it('carries an aerodrome without an icao code in the menu payload', function () {
    fakeAnac();

    expect(buttonIds(bot()->reply('notams alta gracia', PHONE)->menu?->button))
        ->toBe(['ask:metar:AGR', 'ask:taf:AGR', 'ask:crepusculo:AGR']);
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

/**
 * The WMO/OMM code rides on the "Consultar AEROMET" button itself, so the tap
 * needs no station-name matching — the caption proves that, the same way the
 * BUTTON_ASK tests above prove theirs.
 */
it('answers a tapped Consultar AEROMET button without any station matching', function () {
    fakeAeromet();

    $reply = bot()->reply('Consultar AEROMET', PHONE, 'aeromet:87548')->messages;

    expect(implode(' ', $reply))->toContain('AEROMET JUNIN');
});

it('falls back to the text path when a menu payload names an unknown topic', function () {
    fakeAnac();

    expect(bot()->reply('notams aeroparque', PHONE, 'ask:humedad:SAEZ')->messages[0])->toContain('(AER)');
});

it('does not shrink the answer to fit the menu', function () {
    fakeTaf(Http::response(smnFixture('taf-multi.html')));

    $withoutTemplate = bot()->reply('taf aeroparque', PHONE)->messages;

    $withTemplate = bot()->reply('taf aeroparque', PHONE)->messages;

    expect($withTemplate)->toBe($withoutTemplate);

    foreach ($withTemplate as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1500);
    }
});

it('offers the menu for a sun answer that names an aerodrome', function (string $message) {
    Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    fakeShnSun();

    $reply = bot()->reply($message, PHONE);

    expect($reply->menu)->not->toBeNull()
        ->and(buttonIds($reply->menu->button))->toBe(['ask:notam:SAZR', 'ask:metar:SAZR', 'ask:taf:SAZR']);

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

    $reply = bot()->reply('crepusculo base esperanza', PHONE);

    expect($reply->menu)->toBeNull()
        ->and($reply->messages[0])->toContain('ESPERANZA');

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Componente de viento en pista
|--------------------------------------------------------------------------
|
| The step a METAR stops one short of: how much of the reported wind lands
| along each cabecera and how much across it. The fixture wind is 03009KT, and
| Ezeiza's four ends are seeded here with their real true headings so the
| numbers in these tests are the numbers a pilot would get.
|
*/

function seedEzeizaRunways(): void
{
    foreach (['11' => 102, '29' => 282, '17' => 164, '35' => 344] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'EZE',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'ourairports',
        ]);
    }
}

it('answers the wind component for every runway end', function () {
    fakeMetar();
    seedEzeizaRunways();

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('EZEIZA')
        ->and($body)->toContain('03009KT')
        ->and($body)->toContain('Viento del 030° (NNE) a 9 kt')
        // 030° is 46° off runway 35, so nine knots split almost evenly.
        ->and($body)->toContain('✅ RWY 35 — de frente 6 kt · cruzado 6 kt (der)')
        ->and($body)->toContain('RWY 17 — de cola 6 kt · cruzado 6 kt (izq)');
});

/**
 * Every phrase for this question contains "viento", which is a METAR keyword.
 * Without the ordering in topic() the observation would be answered instead of
 * the question that starts from it.
 */
it('routes crosswind questions past the METAR keywords', function (string $message) {
    fakeMetar();
    seedEzeizaRunways();

    $service = bot();
    $service->reply($message);

    expect($service->lastContext()->topic)->toBe('pista');
})->with([
    'viento cruzado en EZE',
    'componente de viento en Ezeiza',
    'que pista conviene en EZE',
    'crosswind SAEZ',
]);

/**
 * Matching is by substring, and MADHEL has aerodromes with the word in their
 * own names — CORONEL SUÁREZ / LA PISTA. A bare "pista" keyword would hijack
 * every NOTAM request that named one of them.
 */
it('does not hijack an aerodrome whose name contains "pista"', function () {
    fakeAnac();

    $service = bot();
    $service->reply('notams de coronel suarez la pista');

    expect($service->lastContext()->topic)->toBe('notam');
});

/**
 * The mirror image of the test above, and the one that bit: the word travels
 * the other way too. "pista" matches CORONEL SUÁREZ / LA PISTA by name, and
 * OSA is an ambiguous code that needs capitals to be read as one — so every
 * one of these used to answer for Coronel Suárez instead of Santa Rosa.
 */
it('does not let the words that named the topic name an aerodrome as well', function (string $message) {
    fakeMetar();

    $service = bot();
    $service->reply($message);

    expect($service->lastContext()->anacCode)->toBe('OSA');
})->with([
    'viento en pista osa',
    'viento en la pista de osa',
    'viento cruzado en pista de osa',
    'que pista uso en osa',
    'viento en pista santa rosa',
]);

/**
 * With nothing but the question left, there is no aerodrome in the message —
 * which is the help text's answer, not a random one. It matters more than it
 * looks: "Viento en pista" is the button's own caption, and that is what
 * arrives as plain text if a tap ever reaches the message path without its
 * payload.
 */
it('asks rather than answering for a place the message never named', function (string $message) {
    fakeAnac();

    expect(bot()->reply($message)->messages[0])->toContain('Decime el aeropuerto que te interesa');
})->with(['viento en pista', 'aeropuerto', 'viento cruzado']);

/**
 * The stripping must not reach past the topic's own words: an ambiguous code
 * still earns its match by being typed in capitals, and the guards that rule
 * has always carried are unchanged.
 */
it('leaves the rest of the message exactly as it was typed', function () {
    fakeAnac();

    $service = bot();

    $service->reply('notams VER');
    expect($service->lastContext()->anacCode)->toBe('VER');

    $service->reply('quiero ver los notams de eze');
    expect($service->lastContext()->anacCode)->toBe('EZE');
});

it('answers a tap on the runway-wind button', function () {
    fakeMetar();
    seedEzeizaRunways();

    $service = bot();
    $reply = $service->reply('', PHONE, 'pista:SAEZ');

    expect($service->lastContext()->topic)->toBe('pista')
        ->and($reply->messages[0])->toContain('RWY 35');
});

/**
 * A gust is what the aircraft has to be flown for on the flare, so it rides
 * under the favoured end — and only there, because repeating it for every
 * cabecera would double a message meant to be read at a glance.
 */
it('reports the components for the gust under the favoured runway', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAEZ 271400Z 35015G25KT 9999 SCT020 15/14 Q1009 =')));
    seedEzeizaRunways();

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('ráfagas 25 kt')
        ->and($body)->toContain('✅ RWY 35')
        ->and($body)->toContain('con ráfaga:')
        ->and(substr_count($body, 'con ráfaga:'))->toBe(1);
});

/**
 * Calm and variable are the report, not a failure to report. Naming a favoured
 * runway off either would invent a preference the atmosphere does not have.
 */
it('says there is no favoured runway when the wind is variable', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAEZ 271400Z VRB03KT 9999 SCT020 15/14 Q1009 =')));
    seedEzeizaRunways();

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('Viento variable a 3 kt')
        ->and($body)->not->toContain('✅');
});

it('says there is no component to compute when the wind is calm', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAEZ 271400Z 00000KT 9999 SCT020 15/14 Q1009 =')));
    seedEzeizaRunways();

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('Viento en calma')
        ->and($body)->not->toContain('✅');
});

it('says so plainly when it has no runway headings on file', function () {
    fakeMetar();

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('No tengo los rumbos de pista')
        ->and($body)->toContain('EZEIZA');
});

/**
 * No METAR is no longer no answer. JUNÍN is also an AEROMET station, and its
 * SYNOP carries a wind — a measurement from elsewhere in the same locality,
 * which is why the message says where it came from before it says what it
 * means for a runway.
 */
function seedJuninRunways(): void
{
    foreach (['18' => 172, '36' => 352] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'NIN',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'madhel',
        ]);
    }
}

it('computes the component off the AEROMET wind when there is no METAR', function () {
    fakeMetar(Http::response(smnFixture('metar-empty.html')));
    fakeAeromet();
    seedJuninRunways();

    $body = implode("\n", bot()->reply('viento cruzado en junin', PHONE)->messages);

    expect($body)->toContain('el componente sale del viento de la estación AEROMET *JUNIN*')
        ->toContain('observación de las 30 - 17:00 UTC')
        ->toContain('Viento del 050° (NE) a 14 kt')
        ->toContain('✅ RWY 36');
});

/**
 * The one honest dead end left: neither network observes the place. GENERAL
 * ACHA has a METAR that is empty right now and no AEROMET station of its own.
 */
it('says there is no wind to compute from when neither network has one', function () {
    fakeMetar(Http::response(smnFixture('metar-empty.html')));
    fakeAeromet();

    Runway::create([
        'anac_code' => 'ACH', 'designator' => '17', 'heading_true' => 170,
        'is_closed' => false, 'source' => 'madhel',
    ]);

    expect(bot()->reply('viento cruzado en general acha', PHONE)->messages[0])
        ->toContain('No hay METAR publicado');
});

/*
|--------------------------------------------------------------------------
| El viento en pista de un aeródromo sin METAR
|--------------------------------------------------------------------------
|
| CORONEL SUÁREZ / LA PISTA (CLP) has no OACI code, so the SMN will never
| publish a METAR for it — but AEROMET observes its locality, and that wind is
| what the components are computed from. The whole chain hangs together: the
| empty-METAR answer offers AEROMET, the AEROMET answer offers the components,
| and the components come back off the SYNOP.
|
*/

function seedCoronelSuarezRunways(): void
{
    foreach (['01' => 5, '19' => 185] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'CLP',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'madhel',
        ]);
    }
}

/**
 * The same getsynop line shape as ogimet-junin.txt, for Coronel Suárez —
 * "81912" is the Nddff group, 190° at 12 kt, which RWY 19 faces almost
 * squarely and RWY 01 has behind it.
 */
function fakeCoronelSuarezAeromet(): void
{
    fakeAeromet(Http::response(ogimetFixture('ogimet-coronel-suarez.txt')));
}

it('offers AEROMET under an aerodrome that will never have a METAR', function () {
    Http::fake();
    config(['services.twilio.content_sid_aeromet' => 'HXaeromet']);

    $reply = bot()->reply('metar coronel suarez la pista');

    expect($reply->messages[0])->toContain('no tiene código OACI')
        ->and(buttonIds($reply->button))->toBe(['aeromet:87637:CLP']);
});

/**
 * CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO names its own club
 * and its own building on both halves of its name — neither is "SANTA ROSA" —
 * so the offer can only come from MADHEL's city_reference, the same registry
 * fallback SunCityResolver::cityFor() already leans on for this aerodrome.
 */
it('offers AEROMET under an aerodrome the registry, not the name, places in a covered city', function () {
    Http::fake();
    config(['services.twilio.content_sid_aeromet' => 'HXaeromet']);

    Airport::where('anac_code', 'ELP')->update(['city_reference' => 'Santa Rosa']);

    $reply = bot()->reply('metar elp');

    expect($reply->messages[0])->toContain('no tiene código OACI')
        ->and(buttonIds($reply->button))->toBe(['aeromet:87623:ELP']);
});

/**
 * The aerodrome rides in the payload because the station cannot give it back:
 * Coronel Suárez the locality holds three aerodromes, and the question was
 * about one of them.
 */
it('offers the runway components for the aerodrome the AEROMET offer came from', function () {
    fakeCoronelSuarezAeromet();
    seedCoronelSuarezRunways();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $service = bot();
    $reply = $service->reply('Consultar AEROMET', PHONE, 'aeromet:87637:CLP');

    expect($service->lastContext()->anacCode)->toBe('CLP')
        ->and($reply->messages[0])->toContain('AEROMET CORONEL SUAREZ')
        ->and(buttonIds($reply->button))->toBe(['pista:CLP']);
});

it('computes the runway components of an aerodrome with no OACI code off the AEROMET wind', function () {
    fakeCoronelSuarezAeromet();
    seedCoronelSuarezRunways();

    $body = implode("\n", bot()->reply('viento en pista', PHONE, 'pista:CLP')->messages);

    expect($body)->toContain('el componente sale del viento de la estación AEROMET *CORONEL SUAREZ*')
        ->toContain('Viento del 190°')
        ->toContain('✅ RWY 19')
        ->toContain('AAXX 30174 87637');
});

/**
 * CORONEL SUÁREZ has no METAR-publishing aerodrome behind its AEROMET
 * station, so the SYNOP is genuinely the best wind there is. SANTA ROSA is
 * the other case: the station AerometStationResolver resolves for EL
 * PAMPERO (ELP, no OACI code of its own) is also OSA, a real aerodrome with
 * its own METAR (SAZR) — so the component should come off that METAR, not
 * off a SYNOP that a real observation makes unnecessary.
 */
function seedElPamperoRunways(): void
{
    foreach (['01' => 8, '19' => 188] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'ELP',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'madhel',
        ]);
    }
}

it('computes the runway components off a nearby aerodrome\'s METAR rather than its AEROMET SYNOP', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAZR 271400Z 19012KT 9999 SCT020 15/14 Q1009 =', 'SANTA ROSA')));
    Airport::where('anac_code', 'ELP')->update(['city_reference' => 'Santa Rosa']);
    seedElPamperoRunways();

    $body = implode("\n", bot()->reply('viento en pista', PHONE, 'pista:ELP')->messages);

    expect($body)->toContain('el componente sale del METAR de *SANTA ROSA*')
        ->toContain('19012KT')
        ->toContain('Viento del 190°')
        ->toContain('✅ RWY 19')
        ->not->toContain('AEROMET');
});

/**
 * Without runways there is nothing to compute whatever the wind is doing, so
 * the answer is the ficha's own gap — and AEROMET, which at least has the wind
 * itself, is offered instead.
 */
it('offers AEROMET instead when it has no runway headings for an aerodrome with no METAR', function () {
    Http::fake();
    config(['services.twilio.content_sid_aeromet' => 'HXaeromet']);

    $reply = bot()->reply('viento en pista', PHONE, 'pista:CLP');

    expect($reply->messages[0])->toContain('No tengo los rumbos de pista')
        ->and(buttonIds($reply->button))->toBe(['aeromet:87637:CLP']);
});

/**
 * A typed "aeromet junin" has only the station's name to go on, and it is
 * enough here: JUNÍN wins AirportResolver's ranking outright, so the offer is
 * made for its own aerodrome.
 */
it('offers the runway components under an aeromet answer reached by typing', function () {
    fakeAeromet();
    seedJuninRunways();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('aeromet junin', PHONE);

    expect(buttonIds($reply->button))->toBe(['pista:SAAJ']);
});

/**
 * The offer stands alone, so it is only made when there is something behind
 * it — no runway headings on file, no offer, same rule as under a NOTAM.
 */
it('does not offer the runway components when it has no headings for the station aerodrome', function () {
    fakeAeromet();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    expect(bot()->reply('aeromet junin', PHONE)->button)->toBeNull();
});

/**
 * A closed runway is shown — a pilot who sees three on the chart and two here
 * has been told something false — but it is never the recommendation.
 */
it('shows a closed runway without ever recommending it', function () {
    fakeMetar();
    seedEzeizaRunways();
    Runway::where('anac_code', 'EZE')->where('designator', '35')->update(['is_closed' => true]);

    $body = implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages);

    expect($body)->toContain('⛔ RWY 35 — cerrada')
        ->and($body)->toContain('✅ RWY 11');
});

/**
 * A northerly is reported as 360, never 000 — 000 is the code for calm.
 * MetarConditions normalises it to 0 for comparison, which is right for
 * arithmetic and wrong to print.
 */
it('reports a northerly wind as 360 degrees, not 000', function () {
    fakeMetar(Http::response(smnMetarWith('METAR SAEZ 271400Z 36012KT 9999 SCT020 15/14 Q1009 =')));
    seedEzeizaRunways();

    expect(implode("\n", bot()->reply('viento cruzado en Ezeiza')->messages))
        ->toContain('Viento del 360° (N) a 12 kt');
});

/*
|--------------------------------------------------------------------------
| El botón de viento en pista bajo los NOTAM
|--------------------------------------------------------------------------
|
| Same offer as under a METAR, in the one place a NOTAM reply had room for it:
| its own last message. The follow-up menu is already at the three quick
| replies WhatsApp will render.
|
*/

function seedAeroparqueRunways(): void
{
    foreach (['13' => 124, '31' => 304] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'AER',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'ourairports',
        ]);
    }
}

it('offers the runway wind under a notam reply', function () {
    fakeAnac();
    seedAeroparqueRunways();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('notams aeroparque', PHONE);

    expect(buttonIds($reply->button))->toBe(['pista:SABE']);
});

/**
 * The offer stands alone here — unlike under a METAR, where it shares a
 * template with the watch button and cannot be dropped by itself — so it is
 * only sent when there is something behind it.
 */
it('does not offer the runway wind for an aerodrome with no runways on file', function () {
    fakeAnac();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    expect(bot()->reply('notams aeroparque', PHONE)->button)->toBeNull();
});

it('offers the runway wind even when there are no notams to report', function () {
    fakeAnac(Http::response('error', 500));
    seedAeroparqueRunways();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('notams aeroparque', PHONE);

    expect($reply->messages[0])->toContain('No hay NOTAM activos')
        ->and(buttonIds($reply->button))->toBe(['pista:SABE']);
});

/**
 * Three NOTAMs are three messages. Repeating the button on each would read as
 * three separate offers rather than one.
 */
it('puts the runway-wind button on the last notam message only', function () {
    fakeAnac();
    seedAeroparqueRunways();

    $outbound = bot()->reply('notams aeroparque', PHONE)->outbound();

    // Three NOTAM messages plus the follow-up menu, which is a message of its
    // own and carries its own buttons.
    expect(count($outbound))->toBe(4)
        ->and($outbound[0][1])->toBeNull()
        ->and($outbound[1][1])->toBeNull()
        ->and(buttonIds($outbound[2][1]))->toBe(['pista:SABE'])
        ->and(buttonIds($outbound[3][1]))->toBe(['ask:metar:SABE', 'ask:taf:SABE', 'ask:crepusculo:SABE']);
});

/**
 * A message carrying a button is a content template, and WhatsApp caps those
 * at 1024 characters rather than 1600. Splitting to the plain-text budget
 * would produce a message Twilio rejects outright.
 */
it('splits a notam that carries a button to the smaller template budget', function () {
    $long = str_repeat('OBST CRANE ERECTED NEAR THR RWY 13 HGT 45M AGL. ', 80);

    fakeAnac(Http::response(pibWith($long)));
    seedAeroparqueRunways();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('notams aeroparque', PHONE);

    foreach ($reply->messages as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1024);
    }
});

/*
|--------------------------------------------------------------------------
| La ficha del aeródromo
|--------------------------------------------------------------------------
|
| What the aerodrome *is*, which is what a message that names a place and asks
| nothing else is really asking — and the topic every unrecognised question
| now falls back to.
|
| One rule runs through all of it: a field MADHEL does not publish is reported
| as unpublished, never as absent. MADHEL publishes fuel for roughly one
| aerodrome in seven, and a pilot planning a leg on "no tiene combustible"
| would be planning it on something nobody ever said.
|
*/

/**
 * The SHN is stubbed here rather than in each test because the ficha reads it
 * for every aerodrome whose city it covers, and Santa Rosa is one — a ficha
 * test that forgot would be reaching hidro.gov.ar for real. Stubs merge and the
 * first match wins, so a test that needs the SHN to fail can still say so by
 * faking it before calling this.
 */
function seedSantaRosaFicha(): void
{
    fakeShnSun();

    Airport::where('anac_code', 'OSA')->update([
        'iata_code' => 'RSA',
        'fir' => 'SAEF',
        'city_reference' => 'Santa Rosa',
        'distance_km' => 4.5,
        'direction_reference' => 'NNE',
        'elevation_m' => 192,
        'state' => 'LA PAMPA',
        'traffic' => 'NTL',
        'is_aip_delegated' => true,
        'latitude' => -36.5883333,
        'longitude' => -64.2758333,
        'details_updated_at' => now(),
    ]);

    // Santa Rosa is delegated to the AIP, so its runway can only have come
    // from OurAirports — 7546 × 98 ft, lit.
    foreach (['01' => 11, '19' => 191] as $designator => $heading) {
        Runway::create([
            'anac_code' => 'OSA',
            'designator' => $designator,
            'heading_true' => $heading,
            'is_closed' => false,
            'source' => 'ourairports',
            'length_m' => 2300,
            'width_m' => 30,
            'surface' => 'asfalto',
            'is_lighted' => true,
        ]);
    }
}

it('answers a message that only names a place with the ficha', function (string $message) {
    seedSantaRosaFicha();

    $reply = bot()->reply($message)->messages;

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('SANTA ROSA')
        ->toContain('OSA / SAZR / RSA')
        ->toContain('4,5 km al nor-noreste de Santa Rosa (La Pampa)')
        ->toContain('Elevación 192 m (630 ft)')
        ->toContain('FIR Ezeiza (SAEF)')
        ->toContain('01/19 — 2300 × 30 m — asfalto — balizada');
})->with([
    'the ANAC code alone, in lower case' => ['osa'],
    'the ANAC code alone, in capitals' => ['OSA'],
    'the OACI code alone' => ['sazr'],
    'the name alone' => ['santa rosa'],
    'asking for it in words' => ['info osa'],
    'asking where it is' => ['donde queda santa rosa'],
]);

/**
 * The coordinates are printed the way a chart prints them, so they can be
 * compared against one without converting anything.
 */
it('writes the coordinates in degrees, minutes and seconds', function () {
    seedSantaRosaFicha();

    expect(bot()->reply('osa')->messages[0])->toContain('36°35\'18"S 064°16\'33"O');
});

/**
 * MADHEL leaves `data` empty for the aerodromes it delegates to the AIP,
 * which are the ones people actually ask about — but that silence is the
 * registry's, not the aerodrome's, so the ficha must never report fuel or
 * telephone as MADHEL's "sin dato publicado" for one of these. Until
 * notams:import-aip-details has actually read the AIP's own record, the
 * honest thing to say is that the import has not happened yet.
 */
it('never reports MADHEL silence as an absent service for an aerodrome delegated to the AIP', function () {
    seedSantaRosaFicha();

    expect(bot()->reply('osa')->messages[0])
        ->not->toContain('sin dato publicado en MADHEL')
        ->not->toContain('no tiene')
        ->toContain('Todavía no importé la ficha de la AIP')
        // And it points at where the answer does live.
        ->toContain('ais.anac.gob.ar/aip');
});

/**
 * is_aip_delegated is MADHEL's own grant to the AIP, not a promise that its
 * `data` block is empty — General Pico (GPI) is delegated and still
 * publishes its own fuel, telephone and hours. Until notams:import-aip-details
 * has read the AIP's record, MADHEL's own data is better than the generic
 * "not imported yet" line.
 */
it('falls back to MADHEL fuel, telephone and hours for a delegated aerodrome the AIP has not been imported for', function () {
    seedSantaRosaFicha();

    Airport::where('anac_code', 'OSA')->update([
        'fuel' => 'AVGAS 100LL y/and JET A-1',
        'telephone' => ['(02954) 434690'],
        'service_schedule' => 'LUN a VIE 12:00 a 23:00 UTC',
    ]);

    expect(bot()->reply('osa')->messages[0])
        ->toContain('⛽ Combustible: AVGAS 100LL y/and JET A-1')
        ->toContain('☎️ Teléfono: (02954) 434690')
        ->toContain('🕐 Horario: LUN a VIE 12:00 a 23:00 UTC')
        ->not->toContain('Todavía no importé la ficha de la AIP');
});

/**
 * Once notams:import-aip-details has actually read the aerodrome's AIP
 * record, a field it genuinely does not publish is "sin dato publicado en la
 * AIP" — the AIP's own silence, not MADHEL's and not ours.
 */
it('reports a field the AIP itself does not publish, once its ficha has been imported', function () {
    seedSantaRosaFicha();

    Airport::where('anac_code', 'OSA')->update([
        'aip_fuel' => null,
        'aip_telephone' => null,
        'aip_service_schedule' => null,
        'aip_ats_frequency' => '118.30 MHz (CPPL) · 119.70 MHz (CAUX)',
        'aip_details_updated_at' => now(),
    ]);

    expect(bot()->reply('osa')->messages[0])
        ->toContain('⛽ Combustible: sin dato publicado en la AIP')
        ->toContain('☎️ Teléfono: sin dato publicado en la AIP')
        ->toContain('📻 Frecuencia: 118.30 MHz (CPPL) · 119.70 MHz (CAUX)')
        ->not->toContain('sin dato publicado en MADHEL')
        ->not->toContain('no tiene')
        // Already imported, so the ficha no longer sends the reader off-app.
        ->not->toContain('MADHEL remite a la AIP')
        ->toContain('Combustible, teléfono, horario y frecuencia según la AIP');
});

it('shows the fuel and telephone of an aerodrome MADHEL does publish them for', function () {
    Airport::where('anac_code', 'CIF')->update([
        'city_reference' => 'Arrecifes',
        'distance_km' => 4.5,
        'direction_reference' => 'ESE',
        'elevation_m' => 43,
        'state' => 'BUENOS AIRES',
        'fuel' => 'AVGAS 100LL',
        'telephone' => ['(02478) 15-504877'],
        'is_aip_delegated' => false,
        'details_updated_at' => now(),
    ]);

    expect(bot()->reply('info cif')->messages[0])
        ->toContain('⛽ Combustible: AVGAS 100LL')
        ->toContain('☎️ Teléfono: (02478) 15-504877')
        ->toContain('4,5 km al este-sudeste de Arrecifes (Buenos Aires)')
        // Not delegated, so there is no AIP to send anybody to.
        ->not->toContain('ais.anac.gob.ar/aip');
});

/**
 * "Sin dato publicado en MADHEL" is a claim about the registry. Making it
 * about an aerodrome we never asked MADHEL about would be reporting our own
 * gap as somebody else's.
 */
it('says the ficha was never imported rather than blaming MADHEL for it', function () {
    expect(bot()->reply('ezeiza')->messages[0])
        ->toContain('Todavía no importé la ficha')
        ->not->toContain('sin dato publicado en MADHEL');
});

it('says so plainly when neither source has any runway', function () {
    seedSantaRosaFicha();
    Runway::where('anac_code', 'OSA')->delete();

    expect(bot()->reply('osa')->messages[0])
        ->toContain('Sin pistas publicadas por MADHEL ni OurAirports');
});

/**
 * A closed aerodrome answering "here is where it is and how long its runway
 * is" without saying it is closed would be describing a place nobody can land
 * at as though they could.
 */
it('warns that the aerodrome is closed', function () {
    // Curuzú Cuatiá, which MADHEL publishes as AD CERRADO (CLSD).
    expect(bot()->reply('CCA')->messages[0])->toContain('Aeródromo cerrado');
});

it('marks a closed runway rather than hiding it', function () {
    seedSantaRosaFicha();
    Runway::where('anac_code', 'OSA')->update(['is_closed' => true]);

    expect(bot()->reply('osa')->messages[0])->toContain('01/19')->toContain('⛔ cerrada');
});

/**
 * Both ends of a strip share its dimensions, so the ficha lists it once. An
 * end whose opposite is not on file is still listed, on its own.
 */
it('lists an unpaired runway end on its own', function () {
    seedSantaRosaFicha();
    Runway::where('anac_code', 'OSA')->where('designator', '19')->delete();

    expect(bot()->reply('osa')->messages[0])->toContain('• 01 — 2300 × 30 m');
});

/*
|--------------------------------------------------------------------------
| El sol en la ficha
|--------------------------------------------------------------------------
|
| Salida and puesta ride at the foot of the ficha — the one thing in it that
| does not come off a local table. Which is why every test here is also about
| what happens when it cannot be had: the ficha answers a question about the
| place itself and must not be able to fail because a website was down.
|
*/

it('closes the ficha with today Sun', function () {
    seedSantaRosaFicha();

    expect(bot()->reply('osa')->messages[0])
        ->toContain('*Sol de hoy* — _SHN, SANTA ROSA_')
        ->toContain('• Salida: 11:15 UTC (08:15 local)')
        ->toContain('• Puesta: 21:31 UTC (18:31 local)');
});

/**
 * The SHN publishes by city and MADHEL files every aerodrome under the one it
 * belongs to, so the registry answers for the aerodromes the curated map never
 * named: the gliding club and the municipal strip share Santa Rosa's sunset
 * with the airport across town.
 */
it('takes the city from the registry for an aerodrome the map never named', function () {
    fakeShnSun();
    Airport::where('anac_code', 'ELP')->update([
        'city_reference' => 'Santa Rosa',
        'state' => 'LA PAMPA',
        'details_updated_at' => now(),
    ]);

    expect(bot()->reply('elp')->messages[0])
        ->toContain('EL PAMPERO')
        ->toContain('*Sol de hoy* — _SHN, SANTA ROSA_')
        ->toContain('• Puesta: 21:31 UTC (18:31 local)');
});

/**
 * A city the SHN does not publish is the ordinary case — 34 localities against
 * 712 aerodromes — and it costs nothing: no section, and no request either.
 */
it('says nothing about the sun for a city the SHN does not publish', function () {
    Http::fake();

    Airport::where('anac_code', 'CIF')->update([
        'city_reference' => 'Arrecifes',
        'details_updated_at' => now(),
    ]);

    expect(bot()->reply('info cif')->messages[0])->not->toContain('Sol de hoy');

    Http::assertNothingSent();
});

/**
 * Stubs merge and the first match wins, so faking the failure before seeding
 * is what makes this the SHN's answer rather than the fixture's.
 */
it('still answers the ficha when the SHN cannot be reached', function () {
    fakeShnSun(Http::response('Server Error', 500));
    seedSantaRosaFicha();

    expect(bot()->reply('osa')->messages[0])
        ->not->toContain('Sol de hoy')
        ->toContain('01/19 — 2300 × 30 m')
        ->toContain('Elevación 192 m (630 ft)');
});

it('calls a heliport a heliport', function () {
    Airport::create([
        'anac_code' => 'HZZ',
        'name' => 'HELIPUERTO ZURUMBAMBA',
        'kind' => 'HLP',
        'access' => 'privado',
        'details_updated_at' => now(),
    ]);

    expect(bot()->reply('HZZ')->messages[0])->toContain('Helipuerto privado no controlado');
});

/**
 * Thirty-one aerodromes sit against the town they serve: MADHEL writes
 * "Lindando" in the bearing field and zero in the distance. That is not a
 * compass point, and forcing it through the rose would invent a direction.
 */
it('phrases an aerodrome that abuts its town as abutting it', function () {
    Airport::where('anac_code', 'BOL')->update([
        'city_reference' => 'El Bolsón',
        'distance_km' => 0,
        'direction_reference' => 'Lindando',
        'state' => 'RÍO NEGRO',
        'details_updated_at' => now(),
    ]);

    expect(bot()->reply('BOL')->messages[0])
        ->toContain('Lindando con El Bolsón (Río Negro)')
        ->not->toContain('0 km');
});

/**
 * The ficha keywords are matched last precisely so that they can be this
 * broad: "aeropuerto" appears in half the messages the bot gets.
 */
it('does not swallow the questions its own keywords appear inside', function (string $message, string $expected) {
    fakeAnac();
    fakeMetar();
    fakeTaf();

    $bot = bot();
    $bot->reply($message);

    expect($bot->lastContext()->topic)->toBe($expected);
})->with([
    'notams at an aeropuerto' => ['hay notams en el aeropuerto de Cordoba', 'notam'],
    'the weather at an aerodromo' => ['como esta el clima en el aerodromo de Bariloche', 'metar'],
    'the forecast for an aeropuerto' => ['taf del aeropuerto de Ezeiza', 'taf'],
    'the ficha itself' => ['datos de Ezeiza', 'info'],
    'a bare name' => ['ezeiza', 'info'],
]);

it('offers the other three topics under the ficha', function () {
    seedSantaRosaFicha();

    $menu = bot()->reply('osa', PHONE)->menu;

    expect(buttonIds($menu->button))->toBe(['ask:notam:SAZR', 'ask:metar:SAZR', 'ask:taf:SAZR']);
});

it('answers a tapped ficha button without any text matching', function () {
    seedSantaRosaFicha();

    $reply = bot()->reply('Ficha', PHONE, 'ask:info:SAZR')->messages;

    expect($reply[0])->toContain('SANTA ROSA')->toContain('Elevación 192 m');
});

/**
 * ANAC and IATA agree at a good few aerodromes — Ezeiza is EZE in both — and
 * "EZE / SAEZ / EZE" reads as a mistake rather than as two registries
 * happening to concur.
 */
it('does not repeat a code two registries agree on', function () {
    Airport::where('anac_code', 'EZE')->update(['iata_code' => 'EZE', 'details_updated_at' => now()]);

    expect(bot()->reply('info eze')->messages[0])
        ->toContain('EZE / SAEZ')
        ->not->toContain('EZE / SAEZ / EZE');
});

/**
 * The ficha has just listed the cabeceras, so "which of these does the wind
 * favour right now" is the next thing the reader wants — the same reason the
 * offer rides under a NOTAM.
 */
it('offers the runway wind under the ficha', function () {
    seedSantaRosaFicha();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('osa', PHONE);

    expect(buttonIds($reply->button))->toBe(['pista:SAZR']);
});

/**
 * A button leading to "no tengo los rumbos de pista" is worse than no button,
 * and a message carrying one is split to the shorter template budget — which
 * would cost extra messages for nothing.
 */
it('does not offer the runway wind on a ficha with no runways', function () {
    seedSantaRosaFicha();
    Runway::where('anac_code', 'OSA')->delete();
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    expect(bot()->reply('osa', PHONE)->button)->toBeNull();
});

it('splits a ficha that carries the button to the smaller template budget', function () {
    seedSantaRosaFicha();
    Airport::where('anac_code', 'OSA')->update([
        // OSA is delegated to the AIP, so it is aip_service_schedule the
        // ficha reads — the plain MADHEL column is never consulted for it.
        'aip_service_schedule' => str_repeat('LUN a VIE 12:00 a 21:00 HR UTC, SAB y DOM O/R. ', 40),
        'aip_details_updated_at' => now(),
    ]);
    config(['services.twilio.content_sid_pista' => 'HXpista']);

    $reply = bot()->reply('osa', PHONE);

    expect(count($reply->messages))->toBeGreaterThan(1);

    foreach ($reply->messages as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(1024);
    }
});

/*
|--------------------------------------------------------------------------
| Las cartas de la AIP
|--------------------------------------------------------------------------
|
| The one answer that hands over a file instead of describing one. Everything
| here turns on two things: that the aerodrome's documents are read out of the
| AIP's own listing at the moment they are asked for, and that a chart still
| goes out when the summary above it could not be written.
|
*/

it('sends every approach chart the AIP publishes', function () {
    fakeAipDocuments();

    $reply = bot()->reply('me podrías dar la carta de aproximación de Santa Rosa?', PHONE);

    expect($reply->documents)->toHaveCount(2)
        ->and($reply->documents[0]->url)->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa-vor')
        ->and($reply->documents[1]->url)->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa-rnav');
});

/**
 * An aerodrome publishes an approach chart per procedure and per runway, so the
 * caption is the only thing telling one attachment from the next.
 */
it('captions each chart with what it is', function () {
    fakeAipDocuments();

    expect(bot()->reply('carta de aproximación de Santa Rosa', PHONE)->documents[0]->caption)
        ->toContain('Carta de aproximación por instrumentos')
        ->toContain('VOR RWY 19');
});

/**
 * Silenced AI is the state every test here runs in, and it is also a real
 * deployment state. The chart goes out either way.
 */
it('sends the chart with its title alone when there is no summary to be had', function () {
    fakeAipDocuments();

    $caption = bot()->reply('carta de aproximación de Santa Rosa', PHONE)->documents[0]->caption;

    expect($caption)->toStartWith('📄 *')->and(mb_strlen($caption))->toBeLessThanOrEqual(1024);
});

it('offers the rest of the documents under the charts it sent', function () {
    fakeAipDocuments();

    $reply = bot()->reply('carta de aproximación de Santa Rosa', PHONE);

    expect(buttonIds($reply->button))->toBe(['doc:SAZR:0', 'doc:SAZR:1'])
        ->and($reply->button->listLabel)->not->toBeNull();
});

/**
 * Asked for documents without saying which, nothing is sent: the list is the
 * answer, and guessing which of a dozen charts was meant would be the one thing
 * worse than asking.
 */
it('offers the whole list when no kind of document was named', function () {
    fakeAipDocuments();

    $reply = bot()->reply('documentos AIP de Santa Rosa', PHONE);

    expect($reply->documents)->toBe([])
        ->and(buttonIds($reply->button))->toBe(['doc:SAZR:0', 'doc:SAZR:1', 'doc:SAZR:2', 'doc:SAZR:3'])
        ->and($reply->messages[0])->toContain('lo que la AIP publica');
});

it('sends the aerodrome plot when that is what was asked for', function () {
    fakeAipDocuments();

    $reply = bot()->reply('plano de aeródromo de Santa Rosa', PHONE);

    expect($reply->documents)->toHaveCount(1)
        ->and($reply->documents[0]->url)->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa-plano');
});

it('says so when the AIP publishes no chart of the kind asked for', function () {
    fakeAipDocuments();

    $reply = bot()->reply('carta de aproximación de Ezeiza', PHONE);

    expect($reply->documents)->toBe([])
        ->and($reply->messages[0])->toContain('no publica cartas de aproximación')
        ->and(buttonIds($reply->button))->toBe(['doc:SAEZ:0']);
});

/**
 * The AIP indexes its documents by OACI code and nothing else, so an aerodrome
 * without one will never appear in that listing — and retrying will never help.
 */
it('explains that an aerodrome with no OACI code has no AIP documents', function () {
    fakeAipDocuments();

    $reply = bot()->reply('carta de aproximación de Alta Gracia', PHONE);

    expect($reply->documents)->toBe([])
        ->and($reply->messages[0])->toContain('no tiene código OACI');
});

it('does not fail the answer when the AIP listing cannot be read', function () {
    Http::fake(['*/aip/ad' => Http::response('', 503)]);

    $reply = bot()->reply('carta de aproximación de Santa Rosa', PHONE);

    expect($reply->documents)->toBe([])
        ->and($reply->messages[0])->toContain('no pude leer el listado');
});

it('routes a request for a chart to the charts', function (string $message) {
    fakeAipDocuments();

    $bot = bot();
    $bot->reply($message, PHONE);

    expect($bot->lastContext()->topic)->toBe('carta');
})->with([
    'the approach chart' => ['me podrías dar la carta de aproximación de Tandil?'],
    'the aerodrome plot' => ['plano de aeródromo de Ezeiza'],
    'the documents in general' => ['documentos AIP de Ezeiza'],
]);

/**
 * "plano de aeródromo" carries a ficha word inside it and "hay notams" does not
 * stop being a NOTAM request for mentioning a chart.
 */
it('does not let the chart words swallow another question', function (string $message, string $expected) {
    fakeAnac();
    fakeMetar();

    $bot = bot();
    $bot->reply($message, PHONE);

    expect($bot->lastContext()->topic)->toBe($expected);
})->with([
    'notams win' => ['hay notams en el aeropuerto de Ezeiza?', 'notam'],
    'the ficha still answers a bare name' => ['ezeiza', 'info'],
    'the METAR is not a chart' => ['metar EZE', 'metar'],
]);

/**
 * The chart words name the topic, and leaving them in the message would let
 * them name an aerodrome too — the same trap "viento en pista osa" fell into.
 */
it('resolves the aerodrome with the chart words taken out', function () {
    fakeAipDocuments();

    $bot = bot();
    $bot->reply('carta de aproximación de Santa Rosa', PHONE);

    expect($bot->lastContext()->anacCode)->toBe('OSA');
});

it('sends the document behind a tapped list row', function () {
    fakeAipDocuments();

    $reply = bot()->reply('', PHONE, 'doc:SAZR:2');

    expect($reply->documents)->toHaveCount(1)
        ->and($reply->documents[0]->url)->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa-vor')
        ->and(buttonIds($reply->button))->toBe(['doc:SAZR:0', 'doc:SAZR:1', 'doc:SAZR:3']);
});

/**
 * A tap can arrive days after the offer, and an AIRAC amendment in between can
 * leave the listing shorter than it was. Sending whatever now sits at that
 * position would be a different chart under the caption that was tapped.
 */
it('says so when a tapped row is no longer in the listing', function () {
    fakeAipDocuments();

    $reply = bot()->reply('', PHONE, 'doc:SAZR:11');

    expect($reply->documents)->toBe([])
        ->and($reply->messages[0])->toContain('ya no está en el listado');
});
