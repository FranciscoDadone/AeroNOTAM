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

/**
 * The number goes out exactly as WhatsApp reports it. A live number has no
 * allow-list, so the workaround below must never be what production does.
 */
it('leaves an argentine mobile untouched by default', function () {
    fakeGraph();

    sender()->send('whatsapp:+5492954465433', 'hola');

    Http::assertSent(fn (Request $request) => $request['to'] === '5492954465433');
});

/**
 * Only when testing against a test number, whose allow-list matches the string
 * as sent rather than the wa_id it resolves to.
 */
it('drops the nine of an argentine mobile when told to', function () {
    fakeGraph();
    config(['services.whatsapp.strip_ar_mobile_nine' => true]);

    sender()->send('whatsapp:+5492954465433', 'hola');

    Http::assertSent(fn (Request $request) => $request['to'] === '542954465433');
});

/**
 * The prefix is Argentina's alone. A number from anywhere else, or one that is
 * already in the shape the allow-list wants, has to come out untouched — the
 * rule is a workaround, and a workaround that reaches past its case is a bug.
 */
it('leaves a number that is not an argentine mobile alone', function (string $number) {
    fakeGraph();
    config(['services.whatsapp.strip_ar_mobile_nine' => true]);

    sender()->send("whatsapp:+{$number}", 'hola');

    Http::assertSent(fn (Request $request) => $request['to'] === $number);
})->with([
    'already without the nine' => '542954465433',
    'united states' => '13055550123',
    'brazil' => '5511998765432',
    'spain' => '34612345678',
]);

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

/**
 * The way past the three-button ceiling: one labelled button opening a sheet of
 * rows. A single section, because there is only ever one thing being listed —
 * and its title is required as soon as there is more than one.
 */
it('sends a list sheet inline with the body', function () {
    fakeGraph();

    sender()->sendWithList('whatsapp:+5491122334455', 'Los demás documentos', '📄 Ver documentos', [
        ['id' => 'doc:SAZR:1', 'title' => 'Plano de aeródromo', 'description' => 'Cartas relativas al aeródromo'],
        ['id' => 'doc:SAZR:2', 'title' => 'VOR RWY 19'],
    ]);

    Http::assertSent(function (Request $request) {
        $action = $request['interactive']['action'];

        return $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'list'
            && $request['interactive']['body']['text'] === 'Los demás documentos'
            && $action['button'] === '📄 Ver documentos'
            && count($action['sections']) === 1
            && $action['sections'][0]['title'] === '📄 Ver documentos'
            && $action['sections'][0]['rows'] === [
                ['id' => 'doc:SAZR:1', 'title' => 'Plano de aeródromo', 'description' => 'Cartas relativas al aeródromo'],
                ['id' => 'doc:SAZR:2', 'title' => 'VOR RWY 19'],
            ];
    });
});

/**
 * WhatsApp fetches the URL itself, so nothing is uploaded from here — which is
 * what lets a chart be sent without keeping a copy of it that could go stale
 * against the amendment that replaced it.
 */
it('sends a document by link', function () {
    fakeGraph();

    sender()->sendDocument(
        'whatsapp:+5491122334455',
        'https://ais.anac.gob.ar/descarga/aip-test-osa-vor',
        '📄 *VOR RWY 19*',
        'sazr-vor-rwy-19.pdf',
    );

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'document'
            && $request['document']['link'] === 'https://ais.anac.gob.ar/descarga/aip-test-osa-vor'
            && $request['document']['caption'] === '📄 *VOR RWY 19*'
            && $request['document']['filename'] === 'sazr-vor-rwy-19.pdf';
    });
});

/**
 * A pin rather than a link: Meta's own location message, which opens in the
 * reader's maps app instead of a browser.
 */
it('sends a location as coordinates with a name under them', function () {
    fakeGraph();

    sender()->sendLocation('whatsapp:+5491122334455', -36.5883333, -64.2758333, 'SANTA ROSA', 'Santa Rosa (La Pampa)');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'location'
            && $request['location'] === [
                'latitude' => -36.5883333,
                'longitude' => -64.2758333,
                'name' => 'SANTA ROSA',
                'address' => 'Santa Rosa (La Pampa)',
            ];
    });
});

/**
 * The address is optional to Meta and unknown for an aerodrome MADHEL places
 * by nothing but its coordinates — sent empty it would draw a blank line.
 */
it('leaves an empty address out of a location', function () {
    fakeGraph();

    sender()->sendLocation('whatsapp:+5491122334455', -36.5883333, -64.2758333, 'SANTA ROSA', '');

    Http::assertSent(fn (Request $request) => ! array_key_exists('address', $request['location']));
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
