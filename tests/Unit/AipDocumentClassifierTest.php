<?php

use App\DataObjects\AipDocument;
use App\Support\AipDocumentClassifier;

function classifier(): AipDocumentClassifier
{
    return new AipDocumentClassifier;
}

function aipDocument(string $title, string $code = 'AD-2.C'): AipDocument
{
    return new AipDocument('SAZR', $code, $title, 'https://ais.anac.gob.ar/descarga/x');
}

it('recognises an approach chart by its title', function (string $title) {
    expect(classifier()->isOfKind(aipDocument($title), AipDocumentClassifier::APPROACH))->toBeTrue();
})->with([
    'as the AIP writes it' => ['Cartas relativas al aeródromo - Carta de aproximación por instrumentos - OACI - VOR RWY 19'],
    'still encoded' => ['Cartas relativas al aer&oacute;dromo - Carta de aproximaci&oacute;n por instrumentos'],
    'in the plural' => ['Cartas de aproximaciones por instrumentos'],
    'by its ICAO abbreviation' => ['IAC RWY 01'],
    'in English' => ['Instrument Approach Chart - ILS RWY 11'],
]);

it('does not take every chart for an approach one', function (string $title) {
    expect(classifier()->isOfKind(aipDocument($title), AipDocumentClassifier::APPROACH))->toBeFalse();
})->with([
    'the aerodrome plot' => ['Cartas relativas al aeródromo - Plano de aeródromo/helipuerto - OACI'],
    'the AD record' => ['Aeródromos - Datos del AD SANTA ROSA'],
    'a departure chart' => ['Carta de salida normalizada - vuelo por instrumentos (SID) - OACI'],
]);

it('recognises the aerodrome plot', function () {
    expect(classifier()->isOfKind(
        aipDocument('Cartas relativas al aeródromo - Plano de aeródromo/helipuerto - OACI', 'AD-2.A'),
        AipDocumentClassifier::AERODROME,
    ))->toBeTrue();
});

it('reads which kind of document a message is asking for', function (string $message, ?string $expected) {
    expect(classifier()->requestedKind($message))->toBe($expected);
})->with([
    'the approach chart' => ['me podrías dar la carta de aproximación de Tandil?', AipDocumentClassifier::APPROACH],
    'without accents' => ['carta de aproximacion tandil', AipDocumentClassifier::APPROACH],
    'by its abbreviation' => ['la IAC de Tandil', AipDocumentClassifier::APPROACH],
    'the aerodrome plot' => ['el plano de aeródromo de Tandil', AipDocumentClassifier::AERODROME],
    'the plot, even naming both' => ['plano de aeródromo y cartas de aproximación', AipDocumentClassifier::AERODROME],
    'documents in general' => ['documentos AIP de Tandil', null],
    'nothing of the sort' => ['metar tandil', null],
]);

/**
 * The reason nothing here matches on a bare substring: "IAC" lives inside
 * "aviación" and "ADC" inside "adcuación" of anything an aerodrome might be
 * called. A word boundary is what makes a three-letter abbreviation safe to
 * have on the list at all.
 */
it('does not find an abbreviation inside a longer word', function (string $message) {
    expect(classifier()->requestedKind($message))->toBeNull();
})->with([
    'aviación' => ['escuela de aviacion civil'],
    'radiación' => ['radiacion en el aerodromo'],
]);
