<?php

use App\Support\AipAdDetails;

/**
 * The AIP publishes fuel, telephone, hours, ATS frequency and radio navigation
 * aids for exactly the aerodromes MADHEL delegates to it — the first three are
 * the fields MadhelDetails always reads as null for that set, and the last two
 * are ones MADHEL never carried for anybody. The fixtures are real "Datos del
 * AD" text, not hand-written, because the whole risk here is the AD-2 form's
 * own inconsistency: the same label shows up capitalised one way on one
 * aerodrome's PDF and another way on the next.
 */
it('reads fuel, telephone, hours, ATS frequency and navaids off a single-service aerodrome', function () {
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
        'navaids' => [
            ['type' => 'VOR/DME', 'id' => 'OSA', 'frequency' => '112.5', 'unit' => 'MHz', 'hours' => 'H24'],
            ['type' => 'ILS/LOC', 'id' => 'SR', 'frequency' => '110.3', 'unit' => 'MHz', 'hours' => 'H24'],
        ],
    ]);
});

/**
 * Santa Rosa's AD 2.19 has a third row, "GP/DME 335.0 MHz" — the glide path
 * that comes paired with the ILS localiser above it. Nobody tunes it by hand,
 * and the ficha it would land on is already close to WhatsApp's cap for a
 * message carrying buttons.
 */
it('leaves the glide path out of the navaids', function () {
    $navaids = AipAdDetails::parse(aipAdTextFixture('OSA'))['navaids'];

    expect($navaids)->toHaveCount(2)
        ->and(json_encode($navaids))->not->toContain('GP')
        ->and(json_encode($navaids))->not->toContain('335.0');
});

/**
 * Ezeiza writes the same aid as "VOR DME" where Santa Rosa and Bariloche write
 * "VOR/DME", and publishes two localisers, one per runway end — the second
 * without the hours column its neighbours fill in.
 */
it('reads a space-separated aid type and every localiser an aerodrome publishes', function () {
    $navaids = AipAdDetails::parse(aipAdTextFixture('EZE'))['navaids'];

    expect($navaids)->toBe([
        ['type' => 'VOR/DME', 'id' => 'EZE', 'frequency' => '116.5', 'unit' => 'MHz', 'hours' => 'H24'],
        ['type' => 'ILS/LOC', 'id' => 'PC', 'frequency' => '110.1', 'unit' => 'MHz', 'hours' => 'H24'],
        ['type' => 'ILS/LOC', 'id' => 'EZ', 'frequency' => '108.7', 'unit' => 'MHz', 'hours' => null],
    ]);
});

/**
 * Bariloche's Observaciones column repeats its own aid — "VOR BAR 117.4 MHZ
 * OPR RESTRICTED BTN RDL 020-060…" — which, once the table has been flattened
 * to text, reads exactly like a second row. Listing the VOR twice would look
 * like the aerodrome has two of them.
 */
it('does not list an aid twice when the remarks column repeats it', function () {
    $navaids = AipAdDetails::parse(aipAdTextFixture('BAR'))['navaids'];

    expect($navaids)->toBe([
        ['type' => 'VOR/DME', 'id' => 'BAR', 'frequency' => '117.4', 'unit' => 'MHz', 'hours' => 'H24'],
        ['type' => 'ILS/LOC', 'id' => 'BR', 'frequency' => '109.5', 'unit' => 'MHz', 'hours' => 'H24'],
    ]);
});

/**
 * Esquel's ficha prints the unit as "MH" on both its rows — "VOR/DME ESQ
 * 117.8 MH H24" — where every other aerodrome's prints "MHz". A typo in a
 * Word template nobody re-reads, and the difference between listing Esquel's
 * aids and listing none of them.
 */
it('reads an aid whose unit the AIP typed without its z', function () {
    expect(AipAdDetails::parse(aipAdTextFixture('ESQ'))['navaids'])->toBe([
        ['type' => 'VOR/DME', 'id' => 'ESQ', 'frequency' => '117.8', 'unit' => 'MHz', 'hours' => 'H24'],
        ['type' => 'ILS/LOC', 'id' => 'ES', 'frequency' => '109.7', 'unit' => 'MHz', 'hours' => 'H24'],
    ]);
});

/**
 * The AD-2 section numbering is not contiguous: Viedma publishes no local
 * regulations, so its 2.19 is followed by 2.23 rather than by the 2.20 every
 * other fixture here has. Reading to "the next section" has to mean that
 * literally — and still not mean the running footer, which prints its own
 * page's section number ("SAVV AD 2.9") in the middle of the table.
 */
it('finds the table when the section after it is not AD 2.20', function () {
    expect(AipAdDetails::parse(aipAdTextFixture('VIE'))['navaids'])->toBe([
        ['type' => 'VOR', 'id' => 'VIE', 'frequency' => '117.1', 'unit' => 'MHz', 'hours' => 'H24'],
    ]);
});

/**
 * "DME CH 72X (350 km)", "GP 3°", "HGT REF 16.20 m" — the remarks column is
 * free prose written in the same alphabet as the aid types, and every pattern
 * looser than "a three-digit number followed by MHz or kHz" reads a channel
 * number as an aid of its own.
 */
it('does not read a DME channel or a glide path angle as an aid', function () {
    $text = 'AD 2.19 RADIOAYUDAS PARA LA NAVEGACIÓN Y EL ATERRIZAJE / NAVIGATIONAL AND LANDING AIDS '
        .'1 2 3 4 5 6 7 NDB SR 380 kHz HJ 363501.4 S 0641622.1 W NIL DME CH 72X (350 km) GP 3° '
        .'AD 2.20 REGLAMENTO LOCAL DEL AERÓDROMO';

    expect(AipAdDetails::parse($text)['navaids'])->toBe([
        ['type' => 'NDB', 'id' => 'SR', 'frequency' => '380', 'unit' => 'kHz', 'hours' => 'HJ'],
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
        'navaids' => null,
    ]);
});
