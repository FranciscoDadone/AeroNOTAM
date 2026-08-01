<?php

use App\DataObjects\ReplyButton;

/**
 * The grammar these ids are written in is the same one WhatsappBotService
 * parses a tap with (its BUTTON_* patterns), and nothing at runtime ties the
 * two together: a button whose id the bot cannot parse looks fine until
 * somebody taps it. These tests are that tie.
 */
it('writes every id in the grammar the bot parses taps with', function (ReplyButton $button, string $pattern) {
    foreach (array_column($button->buttons, 'id') as $id) {
        expect($id)->toMatch($pattern);
    }
})->with([
    'subscribe' => [fn () => ReplyButton::subscribe('SAEZ'), '/^(sub:[A-Z]{4}:\d{1,2}|pista:[A-Z]{3,4})$/'],
    'runway wind' => [fn () => ReplyButton::runwayWind('SAEZ'), '/^pista:[A-Z]{3,4}$/'],
    'runway wind, no icao' => [fn () => ReplyButton::runwayWind('AGR'), '/^pista:[A-Z]{3,4}$/'],
    'unsubscribe' => [fn () => ReplyButton::unsubscribe('SAEZ'), '/^unsub:[A-Z]{4}$/'],
    'aeromet' => [fn () => ReplyButton::aeromet('87548', 'JUNÍN'), '/^aeromet:\d{5}$/'],
    'aeromet with aerodrome' => [fn () => ReplyButton::aeromet('87548', 'JUNÍN', 'NIN'), '/^aeromet:\d{5}:[A-Z]{3,4}$/'],
    'menu after notam' => [fn () => ReplyButton::menu('notam', 'SAEZ'), '/^ask:(notam|metar|taf|crepusculo|info):[A-Z]{3,4}$/'],
    'menu after metar' => [fn () => ReplyButton::menu('metar', 'SAEZ'), '/^ask:(notam|metar|taf|crepusculo|info):[A-Z]{3,4}$/'],
    'menu after taf' => [fn () => ReplyButton::menu('taf', 'SAEZ'), '/^ask:(notam|metar|taf|crepusculo|info):[A-Z]{3,4}$/'],
    'menu after crepusculo' => [fn () => ReplyButton::menu('crepusculo', 'SAEZ'), '/^ask:(notam|metar|taf|crepusculo|info):[A-Z]{3,4}$/'],
    'menu after the ficha' => [fn () => ReplyButton::menu('info', 'AGR'), '/^ask:(notam|metar|taf|crepusculo|info):[A-Z]{3,4}$/'],
]);

/**
 * WhatsApp renders at most three buttons on a message and truncates a caption
 * past twenty characters — and a truncated caption is a caption nobody read.
 */
it('stays inside what WhatsApp will render', function (ReplyButton $button) {
    expect(count($button->buttons))->toBeLessThanOrEqual(3);

    foreach ($button->buttons as $action) {
        expect(mb_strlen($action['title']))->toBeLessThanOrEqual(20)
            ->and(mb_strlen($action['id']))->toBeLessThanOrEqual(256);
    }
})->with([
    'subscribe' => [fn () => ReplyButton::subscribe('SAEZ')],
    'runway wind' => [fn () => ReplyButton::runwayWind('SAEZ')],
    'unsubscribe' => [fn () => ReplyButton::unsubscribe('SAEZ')],
    'aeromet' => [fn () => ReplyButton::aeromet('87548', 'JUNÍN', 'NIN')],
    'menu after notam' => [fn () => ReplyButton::menu('notam', 'SAEZ')],
    'menu after metar' => [fn () => ReplyButton::menu('metar', 'SAEZ')],
    'menu after taf' => [fn () => ReplyButton::menu('taf', 'SAEZ')],
    'menu after crepusculo' => [fn () => ReplyButton::menu('crepusculo', 'SAEZ')],
    'menu after the ficha' => [fn () => ReplyButton::menu('info', 'SAEZ')],
]);

/**
 * A menu never re-offers the topic it follows: the reader just got it.
 */
it('never re-offers the topic it follows', function (string $topic) {
    foreach (array_column(ReplyButton::menu($topic, 'SAEZ')->buttons, 'id') as $id) {
        expect($id)->not->toStartWith("ask:{$topic}:");
    }
})->with(['notam', 'metar', 'taf', 'crepusculo']);
