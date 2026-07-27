<?php

use App\Contracts\WhatsappSender;
use App\Jobs\ProcessWhatsappNotamMessage;
use App\Services\WhatsappNotamBotService;
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
    (new ProcessWhatsappNotamMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappNotamBotService::class), $this->sender);

    expect($this->sender->sent)->toHaveCount(3)
        ->and($this->sender->sent[0]['to'])->toBe('whatsapp:+5491111111111')
        ->and($this->sender->bodies()[0])->toContain('A2187/2026');
});

/**
 * A failure while *building* the reply must still produce an answer — the
 * user is waiting in a chat and silence is the worst outcome.
 */
it('apologises instead of going silent when the reply cannot be built', function () {
    $bot = Mockery::mock(WhatsappNotamBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('boom'));

    (new ProcessWhatsappNotamMessage('whatsapp:+5491111111111', 'ezeiza'))
        ->handle($bot, $this->sender);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->bodies()[0])->toContain('Tuve un problema');
});

/**
 * Delivery failures, by contrast, must propagate so the queue retries them.
 */
it('propagates a delivery failure so the queue can retry', function () {
    (new ProcessWhatsappNotamMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappNotamBotService::class), new FakeWhatsappSender(shouldFail: true));
})->throws(RuntimeException::class);

it('declares a retry policy', function () {
    $job = new ProcessWhatsappNotamMessage('whatsapp:+5491111111111', 'eze');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});
