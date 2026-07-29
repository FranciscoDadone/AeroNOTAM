<?php

use App\Support\MadhelIdentifier;

it('splits name, OACI code and classification', function () {
    $parsed = MadhelIdentifier::parse('EZEIZA / MINISTRO PISTARINI - (EZE / SAEZ) - DRCE - PÚBLICO CONTROLADO INTERNACIONAL');

    expect($parsed)->toMatchArray([
        'name' => 'EZEIZA / MINISTRO PISTARINI',
        'icao_code' => 'SAEZ',
        'kind' => 'AD',
        'access' => 'publico',
        'is_controlled' => true,
        'is_closed' => false,
    ]);
});

it('leaves the OACI code null when MADHEL publishes none', function () {
    // The aerodrome that motivated importing the registry in the first place.
    $parsed = MadhelIdentifier::parse('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO - (ELP) - DRCE - PÚBLICO NO CONTROLADO');

    expect($parsed['name'])->toBe('CLUB DE PLANEADORES SANTA ROSA / AERÓDROMO EL PAMPERO')
        ->and($parsed['icao_code'])->toBeNull()
        ->and($parsed['access'])->toBe('publico')
        ->and($parsed['is_controlled'])->toBeFalse();
});

it('does not read "NO CONTROLADO" as controlled', function () {
    expect(MadhelIdentifier::parse('BALCARCE - (BAL) - DRCE - PÚBLICO NO CONTROLADO')['is_controlled'])->toBeFalse();
});

it('flags closed aerodromes', function () {
    $parsed = MadhelIdentifier::parse('CURUZÚ CUATIÁ - (CCA / SATU) - DRNE - PÚBLICO NO CONTROLADO [** AD CERRADO (CLSD) **]');

    expect($parsed['is_closed'])->toBeTrue()
        ->and($parsed['icao_code'])->toBe('SATU');
});

it('tells heliports apart from aerodromes whose code merely starts with H', function () {
    expect(MadhelIdentifier::parse('ALPA CORRAL / HELIPUERTO JUAN Y LUCI - (HAC) - DRNO - PRIVADO NO CONTROLADO')['kind'])->toBe('HLP')
        ->and(MadhelIdentifier::parse('HUINCA RENANCÓ - (HUI) - DRCE - PÚBLICO NO CONTROLADO')['kind'])->toBe('AD');
});

it('survives the separators MADHEL is inconsistent about', function () {
    // No dash at all before the code.
    expect(MadhelIdentifier::parse('STOL COMODORO RIVADAVIA / CHACRAS DEL FARO (CDF) - DRSU - PRIVADO NO CONTROLADO')['name'])
        ->toBe('STOL COMODORO RIVADAVIA / CHACRAS DEL FARO')
        // An en dash rather than a hyphen.
        ->and(MadhelIdentifier::parse('CALCHAQUÍ – (CCI) - DRNE - PRIVADO NO CONTROLADO')['name'])
        ->toBe('CALCHAQUÍ');
});

it('strips the stray BOM some entries carry', function () {
    expect(MadhelIdentifier::parse("\u{FEFF}PARANÁ / AEROCLUB - (ANA) - DRCE - PÚBLICO NO CONTROLADO")['name'])
        ->toBe('PARANÁ / AEROCLUB');
});

it('falls back rather than throwing on the entry that does not parenthesise its code', function () {
    $parsed = MadhelIdentifier::parse('HELIPUERTO EZEIZA / IFE - INSTITUTO DE FORMACIÓN EZEIZA - FIR EZE - MILITAR NO CONTROLADO (HABILITADO POR LA FUERZA AÉREA ARGENTINA)');

    expect($parsed['name'])->toBe('HELIPUERTO EZEIZA / IFE - INSTITUTO DE FORMACIÓN EZEIZA')
        ->and($parsed['kind'])->toBe('HLP')
        ->and($parsed['access'])->toBe('militar')
        ->and($parsed['icao_code'])->toBeNull();
});
