<?php

use App\Support\PronareaFirResolver;

function pronareaFirResolver(): PronareaFirResolver
{
    return app(PronareaFirResolver::class);
}

it('resolves an aerodrome to the FIR that forecasts for it', function () {
    expect(pronareaFirResolver()->firFor('EZE'))->toBe('EZE')
        ->and(pronareaFirResolver()->firFor('SIS'))->toBe('SIS')
        ->and(pronareaFirResolver()->firFor('IRI'))->toBe('SIS')
        ->and(pronareaFirResolver()->firFor('CBA'))->toBe('CBA')
        ->and(pronareaFirResolver()->firFor('DOZ'))->toBe('DOZ')
        ->and(pronareaFirResolver()->firFor('CRV'))->toBe('CRV')
        ->and(pronareaFirResolver()->firFor('USU'))->toBe('CRV');
});

it('is case-insensitive', function () {
    expect(pronareaFirResolver()->firFor('eze'))->toBe('EZE');
});

it('returns null for an aerodrome the SMN does not list under any FIR', function () {
    expect(pronareaFirResolver()->firFor('ZZZ'))->toBeNull();
});
