<?php

use App\Services\MetaWhatsappSender;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.whatsapp.phone_number_id' => '123456789',
        'services.whatsapp.token' => 'test-token',
        'services.whatsapp.graph_version' => 'v25.0',
    ]);
});

/**
 * Registered per test rather than in beforeEach: Http::fake() merges its stubs
 * and the first match for a pattern wins, so a shared success stub would
 * shadow the failure ones.
 */
function fakeGraph(int $status = 200, array $body = ['messages' => [['id' => 'wamid.SENT']]]): void
{
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
}

function sender(): MetaWhatsappSender
{
    return new MetaWhatsappSender;
}

it('posts a plain message to the number endpoint', function () {
    fakeGraph();

    sender()->send('whatsapp:+5491122334455', 'METAR SAEZ 271400Z');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://graph.facebook.com/v25.0/123456789/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'METAR SAEZ 271400Z';
    });
});

/**
 * Everything upstream stores the address the way WhatsApp writes it; Meta wants
 * bare digits, and this is the one place that knows it.
 */
it('strips the prefix and the plus off the recipient', function () {
    fakeGraph();

    sender()->send('whatsapp:+5491122334455', 'hola');

    Http::assertSent(fn (Request $request) => $request['to'] === '5491122334455');
});

it('sends buttons inline with the body', function () {
    fakeGraph();

    sender()->sendWithButtons('whatsapp:+5491122334455', 'METAR SAEZ', [
        ['id' => 'sub:SAEZ:12', 'title' => '🔔 Avisarme 12 h'],
        ['id' => 'pista:SAEZ', 'title' => '🛬 Viento en pista'],
    ]);

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'button'
            && $request['interactive']['body']['text'] === 'METAR SAEZ'
            && $request['interactive']['action']['buttons'] === [
                ['type' => 'reply', 'reply' => ['id' => 'sub:SAEZ:12', 'title' => '🔔 Avisarme 12 h']],
                ['type' => 'reply', 'reply' => ['id' => 'pista:SAEZ', 'title' => '🛬 Viento en pista']],
            ];
    });
});

it('marks the inbound message read to raise the typing indicator', function () {
    fakeGraph();

    sender()->indicateTyping('wamid.INBOUND');

    Http::assertSent(function (Request $request) {
        return $request['status'] === 'read'
            && $request['message_id'] === 'wamid.INBOUND'
            && $request['typing_indicator']['type'] === 'text';
    });
});

/**
 * A delivery failure has to reach the queue so it retries — the reply job and
 * the alert job both lean on that.
 */
it('throws when the api rejects a send', function () {
    fakeGraph(400, ['error' => ['code' => 131026]]);

    sender()->send('whatsapp:+5491122334455', 'hola');
})->throws(RequestException::class);

/**
 * The indicator is a courtesy while the answer is being built. Losing it is not
 * a reason to retry the answer, let alone fail it.
 */
it('swallows a failure to show the typing indicator', function () {
    fakeGraph(400, ['error' => ['code' => 131026]]);

    sender()->indicateTyping('wamid.INBOUND');
})->throwsNoExceptions();

/**
 * Resolving the class must not require credentials: the container builds it for
 * anything type-hinting the port, including in tests that never send.
 */
it('only demands credentials when it actually sends', function () {
    fakeGraph();
    config(['services.whatsapp.token' => null]);

    $sender = sender();

    expect(fn () => $sender->send('whatsapp:+5491122334455', 'hola'))
        ->toThrow(RuntimeException::class);
});
