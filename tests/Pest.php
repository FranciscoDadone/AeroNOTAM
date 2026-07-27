<?php

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test — unit and feature alike — runs against a migrated database
| seeded with the aerodrome registry. Resolving any airport code or free-text
| mention is a table lookup, so that registry is reference data rather than
| per-test fixture data. See Tests\TestCase.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * HTML captured verbatim from ais.anac.gob.ar. The whole application rests on
 * scraping that markup, so the fixtures are the only thing that tells us when
 * ANAC changes it — the failure mode otherwise is an empty NOTAM list served
 * silently, as if the aerodrome were clear.
 */
function anacFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/anac/{$name}");
}

/**
 * Stub ANAC's two endpoints: the PIB query that returns an aerodrome's NOTAMs
 * and the page whose <select> lists the places that currently have any.
 *
 * Call this inside each test rather than in a beforeEach: Http::fake() merges
 * stubs and the first match wins, so a later fake cannot override an earlier
 * one. Pass $pib to override just the PIB response.
 */
function fakeAnac(mixed $pib = null): void
{
    Http::fake([
        '*/notam/pib' => $pib ?? Http::response(anacFixture('pib-aer.html')),
        '*/notam' => Http::response(anacFixture('notam-page.html')),
    ]);
}

/**
 * HTML captured verbatim from ssl.smn.gob.ar/mensajes — the legacy application
 * that www.smn.gob.ar/metar embeds in an iframe. Same reasoning as the ANAC
 * fixtures: the scraper is the only thing standing between us and a silently
 * empty weather report.
 */
function smnFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/smn/{$name}");
}

/**
 * Stub both METAR sources at once: the SMN's query and NOAA's relay.
 *
 * Faking both by default matters — the point of the failover is that a blocked
 * SMN silently falls through to NOAA, so a test that stubbed only one would
 * quietly reach the live network for the other.
 *
 * Pass $smn to override the SMN's response, most usefully with the captured
 * Cloudflare interstitial (smnFixture('challenge.html')), and $noaa likewise.
 */
function fakeSmn(mixed $smn = null, mixed $noaa = null): void
{
    Http::fake([
        '*/mensajes/index.php*' => $smn ?? Http::response(smnFixture('metar-saez.html')),
        '*aviationweather.gov*' => $noaa ?? Http::response(noaaFixture()),
    ]);
}

/**
 * NOAA's JSON shape, carrying the same report the SMN publishes — as it is
 * relayed over the international exchange, i.e. without the SMN's national
 * "RMK" group or the trailing "=".
 *
 * @return array<int, array<string, mixed>>
 */
function noaaFixture(string $raw = 'METAR SAEZ 271700Z 33007KT 9999 BKN014 OVC017 17/14 Q1007 NOSIG'): array
{
    return [[
        'icaoId' => 'SAEZ',
        'name' => 'Buenos Aires/Pistarini Arpt, B, AR',
        'reportTime' => '2026-07-27T17:00:00.000Z',
        'rawOb' => $raw,
    ]];
}

/**
 * A one-observation SMN response built on the real markup, carrying the given
 * raw METAR.
 */
function smnWith(string $raw, string $airport = 'EZEIZA', string $observedAt = '27 - 14:00'): string
{
    return <<<HTML
    <form name="imprimir" action="imprimir.php" method="POST">
        <div>
            <table>
                <tr class="headerResult"><td colspan="2">Aeropuerto {$airport}</td></tr>
                <tr class="result" valign="middle">
                    <td nowrap><b>{$observedAt}</b></td>
                    <td width="100%">{$raw}</td>
                </tr>
            </table>
        </div>
    </form>
    HTML;
}

/**
 * A one-NOTAM PIB response built on the real markup, carrying the given text.
 */
function pibWith(string $textEn): string
{
    return <<<HTML
    <table id="datatable">
        <tbody id="pibdata">
            <tr>
                <td id="place"><p>A9999/2026</p><p>AEROPARQUE J. NEWBERY</p><p>(AER)</p></td>
                <td id="info">
                    <p>Desde: 2026-07-01 10:00:00</p>
                    <p>Hasta: 2026-07-30 22:00:00</p>
                    <p>{$textEn}</p>
                </td>
            </tr>
        </tbody>
    </table>
    HTML;
}

/**
 * Silence the AI so tests exercise the deterministic paths: the offline
 * dictionary decoder and the code/name matcher. Asserting on a mocked model's
 * opinion would test the mock, not the system.
 */
function withoutAi(): void
{
    config(['ai.providers.openrouter.key' => null]);
}
