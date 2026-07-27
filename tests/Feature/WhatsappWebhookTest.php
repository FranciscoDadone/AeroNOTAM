<?php

use App\Jobs\ProcessWhatsappMessage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Twilio\Security\RequestValidator;

beforeEach(function () {
    config(['services.twilio.token' => 'test-auth-token']);

    Queue::fake();
});

/**
 * @param  array<string, string>  $payload
 */
function postSigned(array $payload): TestResponse
{
    $url = url('/whatsapp/webhook');
    $signature = (new RequestValidator(config('services.twilio.token')))
        ->computeSignature($url, $payload);

    return test()
        ->withHeaders(['X-Twilio-Signature' => $signature])
        ->post('/whatsapp/webhook', $payload);
}

it('queues a reply for a properly signed request', function () {
    postSigned(['From' => 'whatsapp:+5491111111111', 'Body' => 'ezeiza'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

    Queue::assertPushed(ProcessWhatsappMessage::class);
});

/**
 * The webhook is a public, unauthenticated URL — signature verification is
 * the only thing standing between it and anyone spending our AI budget.
 */
it('rejects a request without a valid signature', function () {
    $this->withHeaders(['X-Twilio-Signature' => 'obviously-wrong'])
        ->post('/whatsapp/webhook', ['From' => 'whatsapp:+5491111111111', 'Body' => 'ezeiza'])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects everything when no twilio token is configured', function () {
    config(['services.twilio.token' => null]);

    $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+5491111111111', 'Body' => 'ezeiza'])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('acknowledges but queues nothing for an empty body', function () {
    postSigned(['From' => 'whatsapp:+5491111111111', 'Body' => ''])
        ->assertOk();

    Queue::assertNothingPushed();
});
