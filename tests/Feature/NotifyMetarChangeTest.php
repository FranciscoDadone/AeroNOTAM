<?php

use App\Contracts\WhatsappSender;
use App\Jobs\NotifyMetarChange;
use App\Jobs\SendWhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Http\Client\RequestException;
use Tests\Support\FakeWhatsappSender;

beforeEach(function () {
    withoutAi();

    $this->sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $this->sender);
});

/**
 * @param  array<int, string>  $changes
 */
function alertJob(array $changes = ['Visibilidad: 10 km o más → 4000 m.']): NotifyMetarChange
{
    return new NotifyMetarChange(
        'whatsapp:+5491122334455',
        'EZE',
        [
            'station' => 'SAEZ',
            'airport_name' => 'EZEIZA',
            'issued_at' => '27 - 14:00',
            'raw' => 'METAR SAEZ 271400Z 20018G30KT 4000 -RA BKN008 21/17 Q1010',
            'source' => 'smn',
        ],
        $changes,
        '28/02 02:00 UTC',
    );
}

it('sends the alert with the unsubscribe button attached', function () {
    alertJob()->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sentWithButtons)->toHaveCount(1)
        ->and($this->sender->sentWithButtons[0]['to'])->toBe('whatsapp:+5491122334455')
        // The aerodrome the button acts on comes back to us as the tapped id.
        ->and($this->sender->buttonIds())->toBe(['unsub:SAEZ'])
        ->and($this->sender->buttonedBodies()[0])
        ->toContain('Qué cambió')
        ->toContain('Visibilidad: 10 km o más → 4000 m.')
        ->toContain('METAR SAEZ 271400Z 20018G30KT');
});

/**
 * Delivery failures propagate so the queue retries them — the same rule the
 * reply job follows, and for the same reason.
 */
it('propagates a delivery failure so the queue can retry', function () {
    alertJob()->handle(app(WhatsappBotService::class), new FakeWhatsappSender(shouldFail: true));
})->throws(RuntimeException::class);

it('declares a retry policy', function () {
    expect(alertJob()->tries)->toBe(3)
        ->and(alertJob()->backoff)->toBe([10, 60]);
});

it('sends the expiry notice as plain text', function () {
    (new SendWhatsappMessage('whatsapp:+5491122334455', app(WhatsappBotService::class)->expiryNotice('EZE')))
        ->handle($this->sender);

    expect($this->sender->bodies()[0])
        ->toContain('Se venció tu alerta')
        ->toContain('EZE');
});

/**
 * A recipient who let the 24-hour window close cannot be written to at all, so
 * this is not a delivery that might work next time — it is one that will not.
 * Retrying it twice more only delays the failure and burns the queue's patience
 * on a message nobody can receive.
 */
it('gives up rather than retries when the 24-hour window has closed', function () {
    $this->app->instance(WhatsappSender::class, $sender = outOfWindowSender());

    alertJob()->handle(app(WhatsappBotService::class), $sender);
})->throwsNoExceptions();

/**
 * Every other API failure is worth another go: the number is still reachable.
 */
it('propagates any other api failure so the queue can retry', function () {
    $this->app->instance(WhatsappSender::class, $sender = rejectingSender(131026));

    alertJob()->handle(app(WhatsappBotService::class), $sender);
})->throws(RequestException::class);
