<?php

use App\Services\WhatsappBotService;
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

    expect(bot()->reply($message)[0])->toContain("({$expectedCode})");
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

    $reply = bot()->reply('');

    expect($reply)->toHaveCount(1)
        ->and($reply[0])->toContain('Decime el aeropuerto');
});

it('returns the help text for an unrecognizable message', function () {
    fakeAnac();

    expect(bot()->reply('cual es la capital de francia')[0])->toContain('Decime el aeropuerto');
});

/**
 * ANAC's list includes FIR-wide advisory pseudo-codes ("---", "-EF") whose
 * names contain city names. Those are bulletins, not places, and must
 * never be offered as if they were an airport.
 */
it('does not match FIR-wide advisory pseudo-codes', function () {
    fakeAnac();

    expect(bot()->reply('cordoba')[0])
        ->not->toContain('(-CF)')
        ->not->toContain('(---)');
});

/**
 * Córdoba has three aerodromes. Silently picking one could send a pilot
 * the wrong aerodrome's NOTAMs, so the bot asks instead.
 */
it('asks which aerodrome when the name is ambiguous', function () {
    fakeAnac();

    $reply = bot()->reply('cordoba');

    expect($reply)->toHaveCount(1)
        ->and($reply[0])
        ->toContain('varios aeródromos')
        ->toContain('*CBA*')
        ->toContain('Respondeme con el código');
});

it('resolves the ambiguity when answered with a code', function () {
    fakeAnac();

    expect(bot()->reply('CBA')[0])->toContain('(CBA)');
});

it('numbers each notam as its own message', function () {
    fakeAnac();

    $reply = bot()->reply('aeroparque');

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
    expect(bot()->reply('aeroparque')[0])->toContain('Pista 13/31 cerrada');
});

/**
 * A long NOTAM used to be truncated with an ellipsis, silently dropping
 * whatever came last — often the closure window or a contact number.
 */
it('splits a long notam across messages without losing text', function () {
    $tail = 'CONTACTO TEL 011-5555-9999 PARA COORDINAR';
    $long = str_repeat('OBST CRANE ERECTED NEAR THR RWY 13 HGT 45M AGL. ', 80).$tail;

    fakeAnac(Http::response(pibWith($long)));

    $reply = bot()->reply('aeroparque');

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

    expect(bot()->reply('eze')[0])->toContain('no pude obtener sus NOTAM');
});

/*
|--------------------------------------------------------------------------
| METAR
|--------------------------------------------------------------------------
*/

it('answers with the metar when the message asks about the weather', function (string $message) {
    fakeAnac();
    fakeMetar();

    $reply = bot()->reply($message);

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
    expect(implode(' ', bot()->reply('hay notams en ezeiza?')))
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

    expect(bot()->reply('pronostico para ezeiza')[0])->not->toContain('METAR SAEZ');
});

it('explains the metar in spanish under the raw report', function () {
    fakeAnac();
    fakeMetar();

    expect(bot()->reply('metar ezeiza')[0])
        ->toContain('Qué dice')
        ->toContain('Viento del 030° (NNE) a 9 nudos.')
        ->toContain('Temperatura 15 °C')
        ->toContain('Presión QNH 1009 hPa.');
});

it('flags a SPECI as an off-schedule report', function () {
    fakeAnac();
    fakeMetar(Http::response(smnMetarWith('SPECI SAEZ 271530Z 18015G28KT 3000 +TSRA OVC012 19/18 Q1002 =')));

    expect(bot()->reply('metar ezeiza')[0])
        ->toContain('Informe especial (SPECI)')
        ->toContain('tormenta con lluvia fuerte');
});

it('says so when the aerodrome has no ICAO code to look up', function () {
    fakeAnac();
    fakeMetar();

    // Alta Gracia is in ANAC's registry but has no OACI code, so the SMN has
    // nothing to index an observation by.
    expect(bot()->reply('metar alta gracia')[0])->toContain('no tiene código OACI');

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

    $reply = bot()->reply('metar eze')[0];

    expect($reply)
        ->toContain('METAR SAEZ 271700Z')
        ->toContain('Servicio Meteorológico Nacional')
        ->not->toContain('no pude obtener');
});

it('reports a service problem only when every source is unreachable', function () {
    fakeAnac();
    fakeMetar(Http::response('down', 503), Http::response('down', 503));

    expect(bot()->reply('metar eze')[0])->toContain('no pude obtener su METAR');
});

it('says so when there is no observation published', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    expect(bot()->reply('metar eze')[0])->toContain('No hay METAR publicado');
});

it('offers notams, metar and taf in the help text', function () {
    fakeAnac();

    $help = bot()->reply('')[0];

    expect($help)->toContain('NOTAM')->toContain('METAR')->toContain('TAF');
});

it('keeps every metar message within the twilio limit', function () {
    fakeAnac();
    fakeMetar(Http::response(smnFixture('metar-multi.html')));

    $reply = bot()->reply('metar aeroparque');

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

    $reply = bot()->reply($message);

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

    expect(bot()->reply('que tiempo va a haber mañana en ezeiza?')[0])
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

    expect(implode(' ', bot()->reply('hay notams para mañana en ezeiza?')))
        ->toContain('Fuente: ANAC')
        ->not->toContain('TAF SAEZ');
});

it('explains the taf in spanish under the raw forecast', function () {
    fakeAnac();
    fakeTaf();

    expect(bot()->reply('taf ezeiza')[0])
        ->toContain('Qué dice')
        ->toContain('Válido desde el día 27 a las 18:00 hasta el día 28 a las 18:00 UTC.')
        ->toContain('Fluctuaciones temporarias (TEMPO) el día 28 entre las 08:00 y las 12:00 UTC');
});

it('flags an amended forecast', function () {
    fakeAnac();
    fakeTaf(Http::response(smnTafWith('TAF AMD SAEZ 271900Z 2719/2818 18025G40KT 3000 TSRA BKN008CB =')));

    expect(bot()->reply('taf ezeiza')[0])
        ->toContain('Pronóstico enmendado (AMD)')
        ->toContain('tormenta con lluvia');
});

it('flags a cancelled forecast', function () {
    fakeAnac();
    fakeTaf(Http::response(smnTafWith('TAF SAEZ 271700Z 2718/2818 CNL =')));

    expect(bot()->reply('taf ezeiza')[0])->toContain('Pronóstico cancelado (CNL)');
});

it('says so when the aerodrome has no ICAO code for a forecast', function () {
    fakeAnac();
    fakeTaf();

    expect(bot()->reply('pronostico alta gracia')[0])->toContain('no tiene código OACI');

    Http::assertNothingSent();
});

it('still answers with the taf when the SMN is blocking', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('challenge.html'), 403));

    expect(bot()->reply('taf eze')[0])
        ->toContain('TAF SAEZ 271700Z')
        ->toContain('Servicio Meteorológico Nacional')
        ->not->toContain('no pude obtener');
});

it('reports a service problem only when every forecast source is unreachable', function () {
    fakeAnac();
    fakeTaf(Http::response('down', 503), Http::response('down', 503));

    expect(bot()->reply('taf eze')[0])->toContain('no pude obtener su pronóstico TAF');
});

it('says so when there is no forecast published', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('taf-empty.html')));

    expect(bot()->reply('taf eze')[0])->toContain('No hay TAF publicado');
});

it('keeps every taf message within the twilio limit', function () {
    fakeAnac();
    fakeTaf(Http::response(smnFixture('taf-multi.html')));

    $reply = bot()->reply('taf aeroparque');

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
