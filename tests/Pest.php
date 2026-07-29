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
 * A slice of ANAC's MADHEL registry, captured verbatim from
 * datos.anac.gob.ar. The entries were chosen for the shapes the parser has to
 * survive: with and without an OACI code, an unparenthesised code, a dash that
 * is really an en dash, and the two aerodromes whose OACI codes MADHEL assigns
 * differently than our old hand-written seed did.
 */
function fakeMadhel(mixed $response = null): void
{
    Http::fake([
        '*/madhel/api/v2/airports/*' => $response ?? Http::response(
            json_decode(file_get_contents(__DIR__.'/Fixtures/madhel/airports.json'), true),
        ),
    ]);
}

/**
 * HTML captured verbatim from ssl.smn.gob.ar/mensajes — the legacy application
 * that www.smn.gob.ar/metar and /taf embed in an iframe. Same reasoning as the
 * ANAC fixtures: the scraper is the only thing standing between us and a
 * silently empty weather report.
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
 *
 * The patterns match on the endpoint rather than on the host: observations and
 * forecasts come off the same SMN page, so a host-wide stub would answer a TAF
 * query with a METAR.
 */
function fakeMetar(mixed $smn = null, mixed $noaa = null): void
{
    Http::fake([
        '*observacion=metar*' => $smn ?? Http::response(smnFixture('metar-saez.html')),
        '*aviationweather.gov/api/data/metar*' => $noaa ?? Http::response(noaaMetarFixture()),
    ]);
}

/**
 * The same for the forecast channel. Both fakes can coexist in one test —
 * Http::fake() merges stubs, and these patterns do not overlap.
 */
function fakeTaf(mixed $smn = null, mixed $noaa = null): void
{
    Http::fake([
        '*observacion=taf*' => $smn ?? Http::response(smnFixture('taf-saez.html')),
        '*aviationweather.gov/api/data/taf*' => $noaa ?? Http::response(noaaTafFixture()),
    ]);
}

/**
 * HTML captured verbatim from hidro.gov.ar — the SHN's sun table for Santa Rosa,
 * July 2026, the whole month in one page. Same reasoning as the other fixtures:
 * it is what tells us when the SHN rearranges its markup, and the failure mode
 * otherwise is a bot that stops knowing when the sun goes down.
 */
function shnFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/shn/{$name}");
}

/**
 * Stub the SHN's sun query. One request answers a whole month, so a test that
 * asks for two days of July hits this once.
 */
function fakeShnSun(mixed $response = null): void
{
    Http::fake([
        '*hidro.gov.ar/observatorio/REsol.asp*' => $response ?? Http::response(shnFixture('sol-santa-rosa-jul-2026.html')),
    ]);
}

/**
 * The SHN's table with the symbols it prints instead of an hour where the sun
 * never rises, never sets, or never leaves civil twilight. Synthetic rather than
 * captured: no locality on its list sits far enough south to produce them today,
 * but the page documents them and the parser has to survive one appearing.
 */
function shnSunPageWith(string $dawn, string $sunrise, string $sunset, string $dusk): string
{
    return <<<HTML
    <table class="table table-bordered interlineado">
        <thead>
            <tr><th>Día del mes</th><th>Crep. Matutino</th><th>Salida</th><th>Azimut Salida</th><th>Puesta</th><th>Azimut Puesta</th><th>Crep. Vespertino</th></tr>
        </thead>
        <tbody>
            <tr><td>01</td><td>{$dawn}</td><td>{$sunrise}</td><td> 35</td><td>{$sunset}</td><td>324</td><td>{$dusk}</td></tr>
        </tbody>
    </table>
    HTML;
}

/**
 * NOAA's JSON shape, carrying the same report the SMN publishes — as it is
 * relayed over the international exchange, i.e. without the SMN's national
 * "RMK" group or the trailing "=".
 *
 * @return array<int, array<string, mixed>>
 */
function noaaMetarFixture(string $raw = 'METAR SAEZ 271700Z 33007KT 9999 BKN014 OVC017 17/14 Q1007 NOSIG'): array
{
    return [[
        'icaoId' => 'SAEZ',
        'name' => 'Buenos Aires/Pistarini Arpt, B, AR',
        'reportTime' => '2026-07-27T17:00:00.000Z',
        'rawOb' => $raw,
    ]];
}

/**
 * The forecast equivalent. NOAA hands the TAF back under a different key
 * ("rawTAF") and alongside its own decoded breakdown, which we ignore — the
 * point of a relay is to pass the SMN's text on unaltered.
 *
 * @return array<int, array<string, mixed>>
 */
function noaaTafFixture(string $raw = 'TAF SAEZ 271700Z 2718/2818 02005KT 9999 BKN020 TX18/2719Z TN12/2810Z BECMG 2802/2804 VRB03KT 4000 BR BKN010'): array
{
    return [[
        'icaoId' => 'SAEZ',
        'name' => 'Buenos Aires/Pistarini Arpt',
        'issueTime' => '2026-07-27T17:00:00.000Z',
        'rawTAF' => $raw,
    ]];
}

/**
 * A one-observation SMN response built on the real markup, carrying the given
 * raw METAR.
 */
function smnMetarWith(string $raw, string $airport = 'EZEIZA', string $observedAt = '27 - 14:00'): string
{
    return smnResultPage("Aeropuerto {$airport}", $observedAt, $raw);
}

/**
 * The same for a TAF. The SMN labels the header cell "Estacion" on the forecast
 * screen and "Aeropuerto" on the observation one; both are real, which is why
 * the scraper strips either.
 */
function smnTafWith(string $raw, string $airport = 'EZEIZA', string $issuedAt = '27 - 17:00'): string
{
    return smnResultPage("Estacion {$airport}", $issuedAt, $raw);
}

function smnResultPage(string $header, string $issuedAt, string $raw): string
{
    return <<<HTML
    <form name="imprimir" action="imprimir.php" method="POST">
        <div>
            <table>
                <tr class="headerResult"><td colspan="2">{$header}</td></tr>
                <tr class="result" valign="middle">
                    <td nowrap><b>{$issuedAt}</b></td>
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

/**
 * Register a SID for every button template. Blank is the default everywhere
 * else on purpose — that is what a fresh Twilio account looks like, and the
 * bot has to answer without them.
 */
function withButtonTemplates(): void
{
    config([
        'services.twilio.content_sid_metar' => 'HXsub',
        'services.twilio.content_sid_alert' => 'HXalert',
        'services.twilio.content_sid_menu_notam' => 'HXmenunotam',
        'services.twilio.content_sid_menu_metar' => 'HXmenumetar',
        'services.twilio.content_sid_menu_taf' => 'HXmenutaf',
        'services.twilio.content_sid_menu_crepusculo' => 'HXmenusol',
    ]);
}
