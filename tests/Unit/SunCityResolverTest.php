<?php

use App\Support\SunCityResolver;

/**
 * The SHN publishes 34 localities and ANAC 712 aerodromes, so this resolver is
 * where the two vocabularies meet. The cases that matter are the ones where a
 * near miss would be worse than no answer at all.
 */
function sunCities(): SunCityResolver
{
    return app(SunCityResolver::class);
}

it('matches a locality from free text', function (string $message, ?string $expected) {
    expect(sunCities()->matchFromText($message))->toBe($expected);
})->with([
    'plain city' => ['crepusculo santa rosa', 'SANTA ROSA'],
    'city in a sentence' => ['a que hora atardece en Santa Rosa?', 'SANTA ROSA'],
    'accented' => ['crepusculo en Córdoba', 'CORDOBA'],
    'alias' => ['a que hora anochece en bariloche', 'SAN CARLOS DE BARILOCHE'],
    'short alias' => ['crepusculo tucuman', 'SAN MIGUEL DE TUCUMAN'],
    'anac code' => ['crepusculo OSA', 'SANTA ROSA'],
    'icao code' => ['crepusculo SAZR', 'SANTA ROSA'],
    'aerodrome that serves a city' => ['crepusculo EZE', 'BUENOS AIRES'],
    'aerodrome by name' => ['a que hora atardece en aeroparque', 'BUENOS AIRES'],
    'city the SHN does not publish' => ['crepusculo tandil', null],
    'no place at all' => ['a que hora atardece?', null],
]);

/**
 * "Santa Rosa" and "Santa Fe" share a word, and "Santiago del Estero" contains
 * one of them if you squint. The longest match is what keeps them apart.
 */
it('does not confuse localities that share a word', function () {
    expect(sunCities()->matchFromText('crepusculo santa fe'))->toBe('SANTA FE')
        ->and(sunCities()->matchFromText('crepusculo santiago del estero'))->toBe('SANTIAGO DEL ESTERO');
});

/**
 * There is an Esperanza in Santa Fe and a naval base called Esperanza in
 * Antarctica, 3.500 km apart. Answering the wrong one would be off by hours, so
 * the bare word resolves to neither.
 */
it('refuses to guess between the two Esperanzas', function () {
    expect(sunCities()->matchFromText('crepusculo esperanza'))->toBeNull()
        ->and(sunCities()->matchFromText('crepusculo base esperanza'))->toBe('DESTACAMENTO NAVAL ESPERANZA');
});

it('lists the localities the SHN publishes', function () {
    expect(sunCities()->cities())->toContain('SANTA ROSA', 'USHUAIA', 'BUENOS AIRES')
        ->and(sunCities()->cities())->toHaveCount(34);
});
