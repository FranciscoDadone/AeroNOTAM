<?php

use App\Services\AipService;
use Illuminate\Support\Facades\Http;

/**
 * The AIP's "Ad" listing is one table of every document it publishes, and it is
 * the only index there is. Everything downstream — the ficha import, the charts
 * the bot hands over — is a filter over what this parses out of it, so the
 * fixture is what tells us when ANAC rearranges that markup.
 */
function aip(): AipService
{
    return app(AipService::class);
}

it('reads every AD-2 document an aerodrome has', function () {
    fakeAipDocuments();

    $documents = aip()->documentsFor('SAZR');

    expect($documents)->toHaveCount(4)
        ->and(array_column($documents, 'code'))->toBe(['AD-2.0', 'AD-2.A', 'AD-2.C', 'AD-2.D'])
        ->and($documents[0]->icaoCode)->toBe('SAZR')
        ->and($documents[0]->url)->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa');
});

/**
 * The AIP writes its titles HTML-encoded. A half-decoded one reads fine in a
 * list and silently fails every keyword match, which is the failure this
 * guards.
 */
it('decodes the entities the AIP writes its titles with', function () {
    fakeAipDocuments();

    expect(aip()->documentsFor('SAZR')[1]->title)
        ->toBe('Cartas relativas al aeródromo - Plano de aeródromo/helipuerto - OACI');
});

/**
 * AD-1 is the country-wide preamble: no aerodrome code to group it under, and
 * nothing an answer about one aerodrome could do with it.
 */
it('ignores the rows that belong to no aerodrome', function () {
    fakeAipDocuments();

    $service = aip();

    expect($service->documentsFor('SAEZ'))->toHaveCount(1)
        ->and($service->documentsFor('SAZR'))->not->toContain('Índice de aeródromos');
});

it('has nothing for an aerodrome the AIP does not publish', function () {
    fakeAipDocuments();

    expect(aip()->documentsFor('SABE'))->toBe([]);
});

/**
 * The shape notams:import-aip-details reads is deliberately unchanged by the
 * listing behind it having grown: one URL per aerodrome, the "Datos del AD"
 * row and none of the charts around it.
 */
it('still hands the import the AD-2.0 row alone', function () {
    fakeAipDocuments();

    expect(aip()->adDocuments())->toBe([
        'SAZR' => 'https://ais.anac.gob.ar/descarga/aip-test-osa',
        'SAEZ' => 'https://ais.anac.gob.ar/descarga/aip-test-eze',
    ]);
});

it('refuses a listing too short to be the real one', function () {
    fakeAipDocuments();
    config(['services.aip.minimum_ad_documents' => 30]);

    expect(fn () => aip()->adDocuments())->toThrow(RuntimeException::class, 'menos de lo plausible');
});

/**
 * One reply asks for the listing twice — the charts to send, then everything
 * else to offer underneath — and it is one response either way.
 */
it('reads the listing once per instance', function () {
    fakeAipDocuments();

    $service = aip();
    $service->documentsFor('SAZR');
    $service->documentsFor('SAEZ');
    $service->adDocuments();

    Http::assertSentCount(1);
});

/**
 * And once across replies, which is the part that matters now: every answer
 * about an aerodrome asks whether the AIP has charts for it, and each of those
 * is a fresh service out of the container.
 */
it('reads the listing once across instances', function () {
    fakeAipDocuments();

    aip()->documentsFor('SAZR');
    app()->forgetInstance(AipService::class);
    (new AipService)->documentsFor('SAEZ');

    Http::assertSentCount(1);
});

/**
 * Having an ICAO code is not the same as being in the AIP. Junín (SAAJ) has one
 * and nothing published under it, which is why the charts row is decided by
 * this rather than by is_aip_delegated — a field that marks MADHEL's grant of
 * the ficha data and answers a different question. Tandil is not delegated and
 * publishes four documents.
 */
it('says whether the aip publishes anything for an aerodrome', function () {
    fakeAipDocuments();

    expect(aip()->hasDocuments('SAZR'))->toBeTrue()
        ->and(aip()->hasDocuments('SABE'))->toBeFalse()
        ->and(aip()->hasDocuments('sazr'))->toBeTrue();
});

it('fails loudly when the AIP does not answer', function () {
    Http::fake(['*/aip/ad' => Http::response('', 503)]);

    expect(fn () => aip()->documentsFor('SAZR'))->toThrow(RuntimeException::class, 'HTTP 503');
});
