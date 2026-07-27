<?php

use App\Services\WhatsappNotamBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    withoutAi();
});

function bot(): WhatsappNotamBotService
{
    return app(WhatsappNotamBotService::class);
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
