<?php

use App\DataObjects\AipDocument;
use App\DataObjects\ReplyButton;

/**
 * @param  array<int, string>  $titles
 * @return array<int, AipDocument>
 */
function aipDocuments(array $titles): array
{
    $documents = [];

    foreach ($titles as $index => $title) {
        $documents[$index] = new AipDocument('SAZR', 'AD-2.C', $title, "https://ais.anac.gob.ar/descarga/x{$index}");
    }

    return $documents;
}

/**
 * @return array<int, AipDocument>
 */
function manyAipDocuments(int $count): array
{
    return aipDocuments(array_map(
        fn (int $i) => "Cartas relativas al aeródromo - Carta de aproximación por instrumentos - OACI - RNAV RWY {$i}",
        range(1, $count),
    ));
}

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
    'AIP documents' => [fn () => ReplyButton::documents(manyAipDocuments(4)), '/^doc:[A-Z]{4}:\d{1,2}$/'],
]);

/**
 * The position, not the URL: a download link embeds a hash that changes with
 * every AIRAC amendment, and the row a tap points at has to survive that.
 */
it('points a document row at its position in the listing', function () {
    $rows = ReplyButton::documents([3 => manyAipDocuments(4)[3], 7 => manyAipDocuments(8)[7]])->buttons;

    expect(array_column($rows, 'id'))->toBe(['doc:SAZR:3', 'doc:SAZR:7']);
});

/**
 * WhatsApp draws ten rows and no more — past that it draws nothing at all,
 * which would take the whole offer down with it.
 */
it('stays inside what a list sheet will render', function () {
    $button = ReplyButton::documents(manyAipDocuments(14));

    expect($button->buttons)->toHaveCount(10)
        ->and(mb_strlen((string) $button->listLabel))->toBeLessThanOrEqual(20);

    foreach ($button->buttons as $row) {
        expect(mb_strlen($row['title']))->toBeLessThanOrEqual(24)
            ->and(mb_strlen($row['description']))->toBeLessThanOrEqual(72)
            ->and(mb_strlen($row['id']))->toBeLessThanOrEqual(200);
    }
});

/**
 * A row has room for a good deal less than an AIP title, and every document of
 * an aerodrome shares the same opening words — so the caption is cut down to
 * the part that tells one from another, with the whole title underneath.
 */
it('labels a document row with the part that distinguishes it', function () {
    $rows = ReplyButton::documents(aipDocuments([
        'Cartas relativas al aeródromo - Plano de aeródromo/helipuerto - OACI',
        'Aeródromos - Datos del AD SANTA ROSA',
    ]))->buttons;

    expect($rows[0]['title'])->toStartWith('Plano de aeródromo')
        ->and($rows[0]['description'])->toStartWith('Cartas relativas al aeródromo')
        ->and($rows[1]['title'])->toBe('Datos del AD SANTA ROSA');
});

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
