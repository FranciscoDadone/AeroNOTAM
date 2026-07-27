<?php

it('describes the available endpoints at the root', function () {
    $this->getJson('/')
        ->assertOk()
        ->assertJsonStructure(['servicio', 'endpoints']);
});

it('responds to the health check', function () {
    $this->get('/up')->assertOk();
});
