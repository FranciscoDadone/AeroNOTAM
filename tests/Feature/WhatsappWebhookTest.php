<?php

use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.whatsapp.app_secret' => 'test-app-secret',
        'services.whatsapp.verify_token' => 'test-verify-token',
    ]);

    Queue::fake();
});

it('queues a reply for a properly signed request', function () {
    postSigned(metaPayload())->assertOk();

    Queue::assertPushed(ProcessWhatsappMessage::class);
});

/**
 * Meta gives the number in bare digits; everything downstream — the log, the
 * subscriptions — expects it the way WhatsApp writes it.
 */
it('restores the whatsapp: prefix on the sender', function () {
    postSigned(metaPayload(from: '5491133334444'))->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'from'))->getValue($job) === 'whatsapp:+5491133334444';
    });
});

/**
 * The id travels with the job: it's what Meta anchors the typing indicator to,
 * and it's only available here.
 */
it('passes the inbound message id through to the job', function () {
    postSigned(metaPayload(messageId: 'wamid.ABC123'))->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'messageSid'))->getValue($job) === 'wamid.ABC123';
    });
});

/**
 * The webhook is a public, unauthenticated URL — signature verification is
 * the only thing standing between it and anyone spending our AI budget.
 */
it('rejects a request without a valid signature', function () {
    $this->withHeaders(['X-Hub-Signature-256' => 'sha256=obviouslywrong'])
        ->postJson('/whatsapp/webhook', metaPayload())
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects everything when no app secret is configured', function () {
    config(['services.whatsapp.app_secret' => null]);

    $this->postJson('/whatsapp/webhook', metaPayload())->assertForbidden();

    Queue::assertNothingPushed();
});

it('acknowledges but queues nothing for an empty body', function () {
    postSigned(metaPayload(text: ''))->assertOk();

    Queue::assertNothingPushed();
});

/**
 * Delivery receipts for messages we sent arrive on the same webhook. Nothing
 * here acts on them, and logging them would fill the panel with rows nobody
 * wrote.
 */
it('ignores a status update', function () {
    postSigned([
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => '1357575039812140', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'statuses' => [['id' => 'wamid.SENT', 'status' => 'delivered', 'recipient_id' => '5491111111111']],
        ]]]]],
    ])->assertOk();

    Queue::assertNothingPushed();
    expect(WhatsappMessage::count())->toBe(0);
});

/**
 * Meta retries a webhook it considers unacknowledged. The same message arriving
 * twice must not answer the user twice.
 */
it('does not answer twice when meta retries the same message', function () {
    postSigned(metaPayload(messageId: 'wamid.RETRY'))->assertOk();
    postSigned(metaPayload(messageId: 'wamid.RETRY'))->assertOk();

    Queue::assertPushed(ProcessWhatsappMessage::class, 1);
    expect(WhatsappMessage::count())->toBe(1);
});

/**
 * Meta batches: several messages can share one request, and reading only the
 * first would silently drop the rest.
 */
it('handles every message in a batched payload', function () {
    $first = metaPayload(from: '5491111111111', text: 'eze', messageId: 'wamid.ONE');
    $second = metaPayload(from: '5492222222222', text: 'aep', messageId: 'wamid.TWO');

    $first['entry'][] = $second['entry'][0];

    postSigned($first)->assertOk();

    Queue::assertPushed(ProcessWhatsappMessage::class, 2);
    expect(WhatsappMessage::count())->toBe(2);
});

/**
 * A tapped quick reply arrives as an inbound message carrying the action id we
 * put on the button ourselves. That id is what makes the tap unambiguous, so it
 * has to reach the job.
 */
it('passes a tapped button payload through to the job', function () {
    postSigned(metaPayload(text: '🔔 Avisarme 12 h', buttonId: 'sub:SAEZ:12'))->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === 'sub:SAEZ:12';
    });
});

/**
 * A row of a list sheet is the same tap under a different key — same ids, same
 * grammar — and reading only button_reply would leave every AIP document
 * unreachable by touch.
 */
it('passes a tapped list row through to the job', function () {
    postSigned(metaPayload(text: 'VOR RWY 19', buttonId: 'doc:SAZR:2', tap: 'list_reply'))->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === 'doc:SAZR:2'
            && (new ReflectionProperty($job, 'body'))->getValue($job) === 'VOR RWY 19';
    });
});

it('leaves the button payload null for a typed message', function () {
    postSigned(metaPayload())->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === null;
    });
});

it('passes a tapped menu payload through to the job', function () {
    postSigned(metaPayload(text: '🔭 TAF', buttonId: 'ask:taf:SAEZ'))->assertOk();

    Queue::assertPushed(function (ProcessWhatsappMessage $job) {
        return (new ReflectionProperty($job, 'buttonPayload'))->getValue($job) === 'ask:taf:SAEZ';
    });
});

/**
 * Meta calls this when the webhook is subscribed and will only accept the
 * endpoint if it echoes the challenge back.
 */
it('echoes the challenge back when the verify token matches', function () {
    $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=1158201444')
        ->assertOk()
        ->assertSee('1158201444');
});

it('refuses the handshake when the verify token is wrong', function () {
    $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=1158201444')
        ->assertForbidden();
});

it('refuses the handshake when no verify token is configured', function () {
    config(['services.whatsapp.verify_token' => null]);

    $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=&hub_challenge=1158201444')
        ->assertForbidden();
});
