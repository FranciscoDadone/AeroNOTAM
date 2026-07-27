<?php

use App\Services\AnacNotamService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The whole application rests on scraping ANAC's HTML, so these tests run
 * against fixtures captured verbatim from the live site. If ANAC changes its
 * markup, these are what tell us — the failure mode otherwise is an empty
 * NOTAM list served silently as if the aerodrome were clear.
 */
beforeEach(function () {
    Cache::flush();
});

function anac(): AnacNotamService
{
    return app(AnacNotamService::class);
}

it('parses notams from the real ANAC markup', function () {
    fakeAnac();

    $notams = anac()->getNotams('AER');

    expect($notams)->toHaveCount(3)
        ->and($notams[0]->number)->toBe('A2187/2026')
        ->and($notams[0]->locationCode)->toBe('AER')
        ->and($notams[0]->locationName)->toBe('AEROPARQUE J. NEWBERY')
        ->and($notams[0]->validFrom)->toBe('2026-08-25 12:00:00')
        ->and($notams[0]->validUntil)->toBe('2026-08-27 19:00:00')
        ->and($notams[0]->permanent)->toBeFalse();
});

it('splits the bilingual text on the versionbreak marker', function () {
    fakeAnac();

    $notams = anac()->getNotams('AER');

    // The English half must not leak any of the Spanish half, and the
    // "Versión en Español:" marker itself must not survive into either.
    expect($notams[1]->textEn)
        ->toBe('RWY 13/31 CLSD WIP MAINT 1, 4, 6, 8, 11, 13, 15, 18, 20, 22 AND 29 0400-0700')
        ->not->toContain('Versión')
        ->and($notams[1]->textEs)
        ->toBe('RWY 13/31 CLSD WIP MAINT 1, 4, 6, 8, 11, 13, 15, 18, 20, 22 Y 29 0400-0700');
});

it('marks Perm notams as permanent with no expiry', function () {
    fakeAnac(Http::response(anacFixture('pib-permanent.html')));

    $notams = anac()->getNotams('EZE');

    expect($notams[0]->permanent)->toBeTrue()
        ->and($notams[0]->validUntil)->toBeNull()
        ->and($notams[1]->permanent)->toBeFalse()
        ->and($notams[1]->validUntil)->toBe('2026-12-31 23:59:00');
});

it('returns null spanish text when there is no versionbreak', function () {
    fakeAnac(Http::response(anacFixture('pib-permanent.html')));

    $notams = anac()->getNotams('EZE');

    expect($notams[1]->textEs)->toBeNull()
        ->and($notams[1]->textEn)->toBe('CTC TEL 011-4480-1234 OR AEROPTALAPLATA(A)YPF.COM');
});

/**
 * ANAC's backend returns a 500 — not an empty table — for a place with no
 * active NOTAMs. Treating that as an error would mean the bot reporting a
 * service outage every time an aerodrome is simply clear.
 */
it('treats a 500 as no active notams rather than a failure', function () {
    fakeAnac(Http::response('<html>Error</html>', 500));

    expect(anac()->getNotams('PEH'))->toBe([]);
});

it('raises on other http failures', function () {
    fakeAnac(Http::response('gateway down', 503));

    anac()->getNotams('AER');
})->throws(RuntimeException::class);

it('does not hit ANAC twice for the same indicator', function () {
    fakeAnac();

    anac()->getNotams('AER');
    anac()->getNotams('AER');

    Http::assertSentCount(1);
});

it('reads the indicator list from the locations select', function () {
    fakeAnac();

    $indicators = anac()->getValidIndicators();

    expect($indicators)->toHaveKey('AER', 'AEROPARQUE J. NEWBERY')
        // The placeholder "Seleccione un lugar" option carries no value and
        // must not become a bogus aerodrome.
        ->and($indicators)->not->toHaveKey('')
        ->and($indicators)->not->toContain('Seleccione un lugar');
});
