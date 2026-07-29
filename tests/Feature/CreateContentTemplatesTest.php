<?php

use App\Console\Commands\CreateContentTemplates;
use App\Services\WhatsappBotService;

/**
 * The captions and action ids live in the command, and the grammar that
 * reads them back lives in WhatsappBotService — nothing at runtime ties the
 * two together, so this is the guardrail against them drifting apart.
 */
function menuButtonAskRegex(): string
{
    return (new ReflectionClass(WhatsappBotService::class))->getConstant('BUTTON_ASK');
}

it('registers action ids the bot knows how to read', function () {
    $regex = menuButtonAskRegex();

    foreach ((new CreateContentTemplates)->templates() as $template) {
        foreach ($template['actions'] as $action) {
            $id = str_replace('{{2}}', 'SAEZ', $action['id']);

            if (! str_starts_with($id, 'ask:')) {
                // sub:/unsub: have their own, already-tested regexes.
                continue;
            }

            expect(preg_match($regex, $id))->toBe(1);
        }
    }
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
