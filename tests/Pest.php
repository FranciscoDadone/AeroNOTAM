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
 * Stub the SMN's METAR query. Pass $response to override it — most usefully
 * with the captured Cloudflare interstitial (smnFixture('challenge.html')),
 * which the service has to recognise and retry past.
 */
function fakeSmn(mixed $response = null): void
{
    Http::fake([
        '*/mensajes/index.php*' => $response ?? Http::response(smnFixture('metar-saez.html')),
    ]);
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
