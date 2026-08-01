<?php

use App\Services\ShnSunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The SHN's table is the only source for this feature, and it arrives as HTML.
 * What is asserted here is the two things that can go wrong quietly: reading the
 * wrong column, and reading the right one in the wrong timezone — the page
 * prints Argentine official time and the bot answers in UTC.
 */
beforeEach(function () {
    Cache::flush();
});

function sunService(): ShnSunService
{
    return app(ShnSunService::class);
}

it('reads a day out of the real SHN markup', function () {
    fakeShnSun();

    $times = sunService()->forDate('SANTA ROSA', CarbonImmutable::parse('2026-07-01'));

    // What hidro.gov.ar shows for Santa Rosa on 01/07/2026: 08:01, 08:30,
    // 18:12 and 18:40, hora oficial argentina.
    expect($times->dawn->toDateTimeString())->toBe('2026-07-01 11:01:00')
        ->and($times->sunrise->toDateTimeString())->toBe('2026-07-01 11:30:00')
        ->and($times->sunset->toDateTimeString())->toBe('2026-07-01 21:12:00')
        ->and($times->dusk->toDateTimeString())->toBe('2026-07-01 21:40:00')
        ->and($times->place)->toBe('SANTA ROSA');
});

it('reads every day of the month', function () {
    fakeShnSun();

    $rows = sunService()->monthRows('SANTA ROSA', 2026, 7);

    expect($rows)->toHaveCount(31)
        ->and($rows[31])->toBe(['dawn' => '07:48', 'sunrise' => '08:15', 'sunset' => '18:31', 'dusk' => '18:59']);
});

it('posts the locality, month and year the form expects', function () {
    fakeShnSun();

    sunService()->monthRows('SAN CARLOS DE BARILOCHE', 2026, 12);

    Http::assertSent(fn ($request) => $request['Localidad'] === 'SAN CARLOS DE BARILOCHE'
        && $request['Mes'] === 'Dic'
        && $request['Fanio'] === 2026);
});

/**
 * One request carries the whole month, so asking for two days of it must not
 * mean two visits to a government service.
 */
it('asks the SHN once per month', function () {
    fakeShnSun();

    sunService()->forDate('SANTA ROSA', CarbonImmutable::parse('2026-07-01'));
    sunService()->forDate('SANTA ROSA', CarbonImmutable::parse('2026-07-28'));

    Http::assertSentCount(1);
});

it('fails loudly when the SHN rejects the query', function () {
    fakeShnSun(Http::response('Server Error', 500));

    sunService()->monthRows('SANTA ROSA', 2026, 7);
})->throws(RuntimeException::class);

it('fails loudly when the table comes back empty', function () {
    fakeShnSun(Http::response('<html><body>Nada</body></html>'));

    sunService()->monthRows('SANTA ROSA', 2026, 7);
})->throws(RuntimeException::class);

it('keeps the SHN symbol when there is no hour to read', function () {
    fakeShnSun(Http::response(shnSunPageWith('////', '----', '----', '////')));

    $times = sunService()->forDate('DESTACAMENTO NAVAL ESPERANZA', CarbonImmutable::parse('2026-06-01'));

    expect($times->sunrise)->toBeNull()
        ->and($times->sunset)->toBeNull()
        ->and($times->symbolFor('sunrise'))->toBe('----')
        ->and($times->symbolFor('dawn'))->toBe('////');
});

it('returns nothing for a day the month does not have', function () {
    fakeShnSun();

    expect(sunService()->forDate('SANTA ROSA', CarbonImmutable::parse('2026-07-31')))->not->toBeNull();

    // The July fixture has 31 rows; February would have 28.
    expect(sunService()->monthRows('SANTA ROSA', 2026, 7))->not->toHaveKey(32);
});
