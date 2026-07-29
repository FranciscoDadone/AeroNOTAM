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
 * The SID travels with the job: it's what Twilio anchors the typing
 * indicator to, and it's only available here.
 */
it('passes the inbound message sid through to the job', function () {
    postSigned([
        'From' => 'whatsapp:+5491111111111',
        'Body' => 'ezeiza',
        'MessageSid' => 'SM123',
    ])->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'messageSid'))->getValue($job) === 'SM123';
    });
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

/**
 * A tapped quick reply arrives as an ordinary inbound message whose Body is the
 * button's caption, plus the action id we put on the button ourselves. That id
 * is what makes the tap unambiguous, so it has to reach the job.
 */
it('passes a tapped button payload through to the job', function () {
    postSigned([
        'From' => 'whatsapp:+5491111111111',
        'Body' => '🔔 Avisarme 12 h',
        'ButtonPayload' => 'sub:SAEZ:12',
    ])->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === 'sub:SAEZ:12';
    });
});

it('leaves the button payload null for a typed message', function () {
    postSigned(['From' => 'whatsapp:+5491111111111', 'Body' => 'ezeiza'])->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === null;
    });
});

it('passes a tapped menu payload through to the job', function () {
    postSigned([
        'From' => 'whatsapp:+5491111111111',
        'Body' => '🔭 TAF',
        'ButtonPayload' => 'ask:taf:SAEZ',
    ])->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === 'ask:taf:SAEZ';
    });
});
