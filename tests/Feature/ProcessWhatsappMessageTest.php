<?php

use App\Contracts\WhatsappSender;
use App\Jobs\ProcessWhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeWhatsappSender;

beforeEach(function () {
    Cache::flush();
    withoutAi();
    fakeAnac();

    $this->sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $this->sender);
});

it('sends one message per notam to the original sender', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sent)->toHaveCount(3)
        ->and($this->sender->sent[0]['to'])->toBe('whatsapp:+5491111111111')
        ->and($this->sender->bodies()[0])->toContain('A2187/2026');
});

it('shows the typing indicator while the reply is being built', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque', 'SM123'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->typing)->toBe(['SM123']);
});

it('skips the typing indicator when the inbound message id is unknown', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->typing)->toBeEmpty()
        ->and($this->sender->sent)->not->toBeEmpty();
});

/**
 * The dots are decoration; the answer is not.
 */
it('still replies when the typing indicator cannot be shown', function () {
    $sender = new class extends FakeWhatsappSender
    {
        public function indicateTyping(string $inboundMessageId): void
        {
            throw new RuntimeException('Twilio no disponible.');
        }
    };

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque', 'SM123'))
        ->handle(app(WhatsappBotService::class), $sender);

    expect($sender->sent)->toHaveCount(3);
});

/**
 * A failure while *building* the reply must still produce an answer — the
 * user is waiting in a chat and silence is the worst outcome.
 */
it('apologises instead of going silent when the reply cannot be built', function () {
    $bot = Mockery::mock(WhatsappBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('boom'));

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'ezeiza'))
        ->handle($bot, $this->sender);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->bodies()[0])->toContain('Tuve un problema');
});

/**
 * Delivery failures, by contrast, must propagate so the queue retries them.
 */
it('propagates a delivery failure so the queue can retry', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappBotService::class), new FakeWhatsappSender(shouldFail: true));
})->throws(RuntimeException::class);

it('declares a retry policy', function () {
    $job = new ProcessWhatsappMessage('whatsapp:+5491111111111', 'eze');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});

/**
 * The follow-up menu is a message of its own, sent after the answer — never
 * merged onto it, since WhatsApp renders one set of buttons per message.
 */
it('sends the menu as a message of its own after the answer', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sent)->toHaveCount(3)
        ->and($this->sender->sentWithButtons)->toHaveCount(1)
        ->and($this->sender->buttonedBodies()[0])->toContain('AEROPARQUE')
        ->and($this->sender->buttonIds())->toBe(['ask:metar:SABE', 'ask:taf:SABE', 'ask:crepusculo:SABE']);
});

it('sends the watch offer and the menu as two separate messages', function () {
    fakeMetar();

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'metar EZE'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sentWithButtons)->toHaveCount(2)
        ->and(array_column($this->sender->sentWithButtons[0]['buttons'], 'id'))
        ->toBe(['sub:SAEZ:12', 'pista:SAEZ'])
        ->and(array_column($this->sender->sentWithButtons[1]['buttons'], 'id'))
        ->toBe(['ask:notam:SAEZ', 'ask:taf:SAEZ', 'ask:crepusculo:SAEZ']);
});
