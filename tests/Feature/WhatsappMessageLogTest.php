<?php

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyContext;
use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeWhatsappSender;

beforeEach(function () {
    config(['services.whatsapp.app_secret' => 'test-app-secret']);
});

it('logs the incoming message with the phone number and the profile name', function () {
    Queue::fake();

    postSigned(metaPayload(
        from: '5491133334444',
        text: 'notams ezeiza',
        messageId: 'wamid.ABC123',
        profileName: 'Ana Pilot',
    ))->assertOk();

    $logged = WhatsappMessage::sole();

    expect($logged->phone)->toBe('whatsapp:+5491133334444')
        ->and($logged->profile_name)->toBe('Ana Pilot')
        ->and($logged->message_sid)->toBe('wamid.ABC123')
        ->and($logged->body)->toBe('notams ezeiza')
        ->and($logged->status)->toBe(WhatsappMessage::STATUS_PENDING);
});

/**
 * WhatsApp does not always send one, and it is the user's to change — the row
 * has to survive its absence.
 */
it('logs a message from someone with no profile name', function () {
    Queue::fake();

    postSigned(metaPayload(from: '5491133334444', text: 'eze'))->assertOk();

    expect(WhatsappMessage::sole()->profile_name)->toBeNull();
});

it('logs nothing for a request that is not from meta', function () {
    Queue::fake();

    $this->withHeaders(['X-Hub-Signature-256' => 'sha256=obviouslywrong'])
        ->postJson('/whatsapp/webhook', metaPayload())
        ->assertForbidden();

    expect(WhatsappMessage::count())->toBe(0);
});

it('records the reply, the topic and the aerodrome once the job answers', function () {
    Cache::flush();
    withoutAi();
    fakeAnac();

    $sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $sender);

    $logged = WhatsappMessage::factory()->pending()->create([
        'phone' => 'whatsapp:+5491111111111',
        'body' => 'notams aeroparque',
    ]);

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque', null, null, $logged))
        ->handle(app(WhatsappBotService::class), $sender);

    $logged->refresh();

    expect($logged->status)->toBe(WhatsappMessage::STATUS_ANSWERED)
        ->and($logged->topic)->toBe('notam')
        ->and($logged->anac_code)->toBe('AER')
        ->and($logged->icao_code)->toBe('SABE')
        // Three NOTAM messages plus the follow-up menu.
        ->and($logged->reply)->toHaveCount(4)
        ->and($logged->reply[0])->toContain('A2187/2026')
        ->and($logged->duration_ms)->toBeGreaterThanOrEqual(0);
});

/**
 * A message the bot could not make sense of still has to be findable: those are
 * the ones worth reading to improve the matcher.
 */
it('leaves the aerodrome empty when nothing matched', function () {
    withoutAi();

    $sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $sender);

    $logged = WhatsappMessage::factory()->pending()->create(['body' => 'hola']);

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'hola', null, null, $logged))
        ->handle(app(WhatsappBotService::class), $sender);

    $logged->refresh();

    expect($logged->anac_code)->toBeNull()
        ->and($logged->status)->toBe(WhatsappMessage::STATUS_ANSWERED)
        ->and($logged->isUnmatched())->toBeTrue();
});

it('marks the row as failed when the reply cannot be built', function () {
    withoutAi();
    fakeAnac(Http::response('', 500));

    $sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $sender);

    $bot = Mockery::mock(WhatsappBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('ANAC no responde.'));
    $bot->shouldReceive('lastContext')->andReturn(new ReplyContext);

    $logged = WhatsappMessage::factory()->pending()->create(['body' => 'eze']);

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'eze', null, null, $logged))
        ->handle($bot, $sender);

    $logged->refresh();

    expect($logged->status)->toBe(WhatsappMessage::STATUS_FAILED)
        ->and($logged->error)->toBe('ANAC no responde.')
        ->and($logged->reply[0])->toContain('Tuve un problema');
});
