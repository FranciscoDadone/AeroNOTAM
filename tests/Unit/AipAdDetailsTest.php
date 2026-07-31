<?php

use App\Support\AipAdDetails;

/**
 * The AIP publishes fuel, telephone, hours and ATS frequency for exactly the
 * aerodromes MADHEL delegates to it — these are the fields MadhelDetails
 * always reads as null for that set. The fixtures are real "Datos del AD"
 * text, not hand-written, because the whole risk here is the AD-2 form's own
 * inconsistency: the same label shows up capitalised one way on one
 * aerodrome's PDF and another way on the next.
 */
it('reads fuel, telephone, hours and ATS frequency off a single-service aerodrome', function () {
    $details = AipAdDetails::parse(aipAdTextFixture('OSA'));

    expect($details)->toBe([
        'fuel' => 'AVGAS 100LL y/and JET A-1',
        'telephone' => [
            '(+54 2954) 434690',
            '(+54 2954) 434490',
            '(+54 9 2954) 506705',
            '(+54 9 2954) 506740',
        ],
        'service_schedule' => 'LUN a VIE 12:00 a 23:00 UTC. SÁB 17:00 a 23:00 UTC. DOM 13:00 a 21:00 UTC',
        'ats_frequency' => 'TWR/APP SANTA ROSA TORRE — 118.30 MHz (CPPL) · 119.70 MHz (CAUX)',
    ]);
});

/**
 * A busier aerodrome lists several ATS rows on the same table — TWR, then
 * APP, then ATIS. Only the first belongs under its call sign; the others'
 * frequencies leaking in under "TWR" would be worse than the line not
 * appearing at all, since a pilot reading it has no way to tell the
 * difference from the real thing.
 */
it('reads only the first ATS row when an aerodrome publishes several', function () {
    $details = AipAdDetails::parse(aipAdTextFixture('EZE'));

    expect($details['ats_frequency'])->toBe('TWR EZEIZA TORRE — 118.60 MHz (CPPL) · 118.05 MHz (CAUX)')
        ->and($details['ats_frequency'])->not->toContain('119.90')
        ->and($details['ats_frequency'])->not->toContain('127.80');
});

/**
 * The AD-2 form is a Word export, and its own capitalisation is not
 * consistent between aerodromes — this one prints "AD operator" where OSA's
 * prints "AD Operator" — nor is its designation always a single service:
 * "TMA/APP/TWR" is one radio covering three jobs.
 */
it('survives a row label capitalised differently and a three-part ATS designation', function () {
    $details = AipAdDetails::parse(aipAdTextFixture('BAR'));

    expect($details['service_schedule'])->toBe('10:00-17:00 UTC días hábiles.')
        ->and($details['ats_frequency'])->toBe('TMA/APP/TWR Bariloche Torre — 119.10 MHz (CPPL) · 118.65 MHz (CAUX)');
});

it('treats NIL and a bare No as nothing published', function () {
    $text = 'Tipos de combustible, lubricantes / Fuel and oil types NIL 3 Instalaciones y capacidad de abastecimiento de combustible / Fuelling facilities and capacity NIL '
        .'1 Explotador del AD / AD Operator No 2 Aduanas / Customs No (O/R)';

    $details = AipAdDetails::parse($text);

    expect($details['fuel'])->toBeNull()
        ->and($details['service_schedule'])->toBeNull();
});

it('returns null for every field when the text has none of the AD-2 form in it', function () {
    expect(AipAdDetails::parse('página en blanco'))->toBe([
        'fuel' => null,
        'telephone' => null,
        'service_schedule' => null,
        'ats_frequency' => null,
    ]);
});
