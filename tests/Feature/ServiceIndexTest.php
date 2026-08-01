<?php

it('serves the landing page at the root', function () {
    config()->set('services.whatsapp.number', '14155238886');

    $this->get('/')
        ->assertOk()
        ->assertSee('+14155238886')
        ->assertSee('https://wa.me/14155238886');
});

it('drops the WhatsApp call to action when no number is configured', function () {
    config()->set('services.whatsapp.number', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('wa.me');
});

it('responds to the health check', function () {
    $this->get('/up')->assertOk();
});

/**
 * Meta will not publish an app without one, and it is checked from the outside:
 * it has to answer without a session, a token or a number configured.
 */
it('serves the privacy notice', function () {
    config()->set('services.whatsapp.number', null);

    $this->get('/privacidad')
        ->assertOk()
        ->assertSee('Política de privacidad')
        ->assertSee('dadonefran@gmail.com');
});

it('links the privacy notice from the landing page', function () {
    $this->get('/')->assertOk()->assertSee('/privacidad');
});
