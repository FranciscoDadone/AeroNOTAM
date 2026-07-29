<?php

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyContext;
use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeWhatsappSender;
use Twilio\Security\RequestValidator;

/**
 * @param  array<string, string>  $payload
 */
function postInbound(array $payload): TestResponse
{
    config(['services.twilio.token' => 'test-auth-token']);

    $url = url('/whatsapp/webhook');
    $signature = (new RequestValidator('test-auth-token'))->computeSignature($url, $payload);

    return test()->withHeaders(['X-Twilio-Signature' => $signature])->post('/whatsapp/webhook', $payload);
}

it('logs the incoming message with the phone number and the profile name', function () {
    Queue::fake();

    postInbound([
        'From' => 'whatsapp:+5491133334444',
        'Body' => 'notams ezeiza',
        'MessageSid' => 'SM123',
        'ProfileName' => 'Ana Pilot',
    ])->assertOk();

    $logged = WhatsappMessage::sole();

    expect($logged->phone)->toBe('whatsapp:+5491133334444')
        ->and($logged->profile_name)->toBe('Ana Pilot')
        ->and($logged->message_sid)->toBe('SM123')
        ->and($logged->body)->toBe('notams ezeiza')
        ->and($logged->status)->toBe(WhatsappMessage::STATUS_PENDING);
});

/**
 * WhatsApp does not always send one, and it is the user's to change — the row
 * has to survive its absence.
 */
it('logs a message from someone with no profile name', function () {
    Queue::fake();

    postInbound(['From' => 'whatsapp:+5491133334444', 'Body' => 'eze'])->assertOk();

    expect(WhatsappMessage::sole()->profile_name)->toBeNull();
});

it('logs nothing for a request that is not from twilio', function () {
    Queue::fake();

    $this->withHeaders(['X-Twilio-Signature' => 'obviously-wrong'])
        ->post('/whatsapp/webhook', ['From' => 'whatsapp:+5491111111111', 'Body' => 'eze'])
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
        ->and($logged->reply)->toHaveCount(3)
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
