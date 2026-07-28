<?php

it('serves the landing page at the root', function () {
    config()->set('services.twilio.whatsapp_from', 'whatsapp:+14155238886');

    $this->get('/')
        ->assertOk()
        ->assertSee('+14155238886')
        ->assertSee('https://wa.me/14155238886');
});

it('drops the WhatsApp call to action when no number is configured', function () {
    config()->set('services.twilio.whatsapp_from', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('wa.me');
});

it('responds to the health check', function () {
    $this->get('/up')->assertOk();
});
