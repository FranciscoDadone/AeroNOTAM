<?php

use App\Console\Commands\CreateContentTemplates;
use App\Services\WhatsappBotService;

/**
 * The captions and action ids live in the command, and the grammar that
 * reads them back lives in WhatsappBotService — nothing at runtime ties the
 * two together, so this is the guardrail against them drifting apart.
 */
function botConstant(string $name): string
{
    return (new ReflectionClass(WhatsappBotService::class))->getConstant($name);
}

it('registers action ids the bot knows how to read', function () {
    $askRegex = botConstant('BUTTON_ASK');
    $aerometRegex = botConstant('BUTTON_AEROMET');
    $runwayWindRegex = botConstant('BUTTON_RUNWAY_WIND');

    foreach ((new CreateContentTemplates)->templates() as $template) {
        foreach ($template['actions'] as $action) {
            if (str_starts_with($action['id'], 'ask:')) {
                expect(preg_match($askRegex, str_replace('{{2}}', 'SAEZ', $action['id'])))->toBe(1);
            } elseif (str_starts_with($action['id'], 'aeromet:')) {
                expect(preg_match($aerometRegex, str_replace('{{2}}', '87548', $action['id'])))->toBe(1);
            } elseif (str_starts_with($action['id'], 'pista:')) {
                expect(preg_match($runwayWindRegex, str_replace('{{2}}', 'SAEZ', $action['id'])))->toBe(1);
            }

            // sub:/unsub: have their own, already-tested regexes.
        }
    }
});

/**
 * WhatsApp renders at most three quick replies on a message. Registering a
 * fourth does not fail loudly — it silently produces a template that will not
 * render — so the cap is asserted here.
 */
it('keeps every template inside the three-button limit', function () {
    foreach ((new CreateContentTemplates)->templates() as $envKey => $template) {
        expect(count($template['actions']))->toBeLessThanOrEqual(3, $envKey);
    }
});

/**
 * The runway-wind offer rides on the METAR itself, and has to survive the case
 * where the watch offer cannot be sent because a watch is already running.
 */
it('offers the runway wind both alongside the watch button and on its own', function () {
    $templates = (new CreateContentTemplates)->templates();

    expect(array_column($templates['TWILIO_CONTENT_SID_METAR']['actions'], 'id'))
        ->toBe(['sub:{{2}}:12', 'pista:{{2}}'])
        ->and(array_column($templates['TWILIO_CONTENT_SID_PISTA']['actions'], 'id'))
        ->toBe(['pista:{{2}}']);
});

it('never offers the topic the message just answered', function () {
    $menus = [
        'notam' => 'TWILIO_CONTENT_SID_MENU_NOTAM',
        'metar' => 'TWILIO_CONTENT_SID_MENU_METAR',
        'taf' => 'TWILIO_CONTENT_SID_MENU_TAF',
        'crepusculo' => 'TWILIO_CONTENT_SID_MENU_CREPUSCULO',
    ];

    $templates = (new CreateContentTemplates)->templates();

    foreach ($menus as $topic => $envKey) {
        $ids = implode(' ', array_column($templates[$envKey]['actions'], 'id'));

        expect($ids)->not->toContain("ask:{$topic}:");
    }
});

it('keeps every button title inside the whatsapp limit', function () {
    foreach ((new CreateContentTemplates)->templates() as $template) {
        foreach ($template['actions'] as $action) {
            expect(mb_strlen($action['title']))->toBeLessThanOrEqual(20);
        }
    }
});
