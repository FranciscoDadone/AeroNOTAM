<?php

use App\Contracts\WhatsappSender;
use App\Jobs\NotifyMetarChange;
use App\Jobs\SendWhatsappMessage;
use App\Services\WhatsappBotService;
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
    config(['services.twilio.content_sid_alert' => 'HXalert']);

    alertJob()->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sentWithButtons)->toHaveCount(1)
        ->and($this->sender->sentWithButtons[0]['to'])->toBe('whatsapp:+5491122334455')
        ->and($this->sender->sentWithButtons[0]['contentSid'])->toBe('HXalert')
        // The aerodrome the button acts on comes back to us as ButtonPayload.
        ->and($this->sender->sentWithButtons[0]['variables'][2])->toBe('SAEZ')
        ->and($this->sender->templatedBodies()[0])
        ->toContain('Qué cambió')
        ->toContain('Visibilidad: 10 km o más → 4000 m.')
        ->toContain('METAR SAEZ 271400Z 20018G30KT');
});

/**
 * The button is a convenience over an interface that has always been text. With
 * no template registered the alert still goes out, carrying the command that
 * does the same thing.
 */
it('falls back to plain text when no template is registered', function () {
    config(['services.twilio.content_sid_alert' => null]);

    alertJob()->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sentWithButtons)->toBeEmpty()
        ->and($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->bodies()[0])
        ->toContain('METAR SAEZ 271400Z 20018G30KT')
        ->toContain('baja SAEZ');
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
