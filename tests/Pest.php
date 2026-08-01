<?php

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyButton;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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
 * Http::fake() only stubs the patterns it is handed; anything else goes out to
 * the real network. That was tolerable while only a handful of paths made
 * requests, but every answer about an aerodrome now asks the AIP whether it
 * publishes charts for it, and the suite would quietly hammer ANAC. Straying
 * is an exception from here on: a test that means to make a request stubs it.
 */
uses()->beforeEach(fn () => Http::preventStrayRequests())->in('Feature', 'Unit');

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
 * One aerodrome's MADHEL detail response, captured verbatim from
 * datos.anac.gob.ar and trimmed to the blocks the ficha reads.
 *
 * Two of them, because the registry has two shapes and the difference is the
 * whole design problem: OSA (Santa Rosa) is delegated to the AIP, so its
 * `metadata` is full and its `data` is empty; CIF (Arrecifes) is not, so it
 * carries the runway, the fuel and the telephone that the delegated ones never
 * do.
 *
 * @return array<string, mixed>
 */
function madhelDetailFixture(string $anacCode): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/madhel/detail/{$anacCode}.json"), true);
}

/**
 * Stub MADHEL's per-aerodrome endpoint with those fixtures, plus whatever else
 * a test wants to add. Anything not named comes back as a record with empty
 * blocks — which is a real MADHEL response, not a failure.
 *
 * Separate from fakeMadhel(): that one stubs the list endpoint the registry
 * import reads, and its pattern would swallow these.
 *
 * @param  array<string, mixed>  $extra  Extra records to serve, keyed by ANAC code.
 */
function fakeMadhelDetails(array $codes = ['OSA', 'CIF'], array $extra = []): void
{
    $fakes = [];

    foreach ($codes as $code) {
        $fakes["*/madhel/api/v2/airports/{$code}/*"] = Http::response(madhelDetailFixture($code));
    }

    foreach ($extra as $code => $record) {
        $fakes["*/madhel/api/v2/airports/{$code}/*"] = is_array($record) ? Http::response($record) : $record;
    }

    $fakes['*'] = Http::response(['data' => [], 'metadata' => []]);

    Http::fake($fakes);
}

/**
 * A slice of the AIP's "Ad" document listing (GET /aip/ad), matching the
 * shape captured from ais.anac.gob.ar: two AD-2.0 rows — OSA (Santa Rosa) and
 * EZE (Ezeiza) — three of Santa Rosa's charts, and one AD-1 row with no
 * aerodrome code that AipService::parseListing() must ignore. The real listing
 * carries 51 AD-2.0 documents; this one carries two on purpose, so
 * fakeAipDocuments() lowers the minimum-document floor to match rather than
 * padding the fixture out to a realistic size.
 */
function aipAdListingFixture(): string
{
    return file_get_contents(__DIR__.'/Fixtures/aip/ad_listing.html');
}

/**
 * The plain text notams:import-aip-details actually parses — what
 * smalot/pdfparser extracts from one real "Datos del AD" PDF. Kept as text
 * rather than the PDF itself so AipAdDetailsTest is not coupled to which PDF
 * library does the extracting.
 */
function aipAdTextFixture(string $anacCode): string
{
    return file_get_contents(__DIR__."/Fixtures/aip/text/{$anacCode}.txt");
}

/**
 * OSA's real "Datos del AD" PDF, captured verbatim — for the one test that
 * has to exercise the actual download-then-parse pipeline rather than the
 * parser alone.
 */
function aipPdfFixture(): string
{
    return file_get_contents(__DIR__.'/Fixtures/aip/pdf/OSA.pdf');
}

/**
 * Stubs the AIP's document listing and, for each code in $codes, its PDF
 * download — both at the exact URLs ad_listing.html embeds. Every code
 * defaults to serving OSA's PDF, since the command never reads an aerodrome's
 * fields back out of the bytes it downloaded for a *different* one; a test
 * that cares which PDF a code got should pass it in $extra.
 *
 * The chart downloads are stubbed with a catch-all rather than named, because
 * a test about the charts cares that one arrived, not which bytes it carried:
 * what the bot does with those bytes is ask a model about them, and the model
 * is silenced in tests.
 *
 * @param  array<int, string>  $codes
 * @param  array<string, mixed>  $extra  Code (or 'listing') => raw Http::response() to serve instead.
 */
function fakeAipDocuments(array $codes = ['OSA'], array $extra = []): void
{
    config(['services.aip.minimum_ad_documents' => 1]);

    $hrefs = ['OSA' => 'aip-test-osa', 'EZE' => 'aip-test-eze'];

    $fakes = [
        '*/aip/ad' => $extra['listing'] ?? Http::response(aipAdListingFixture()),
    ];

    foreach ($codes as $code) {
        $fakes["*/descarga/{$hrefs[$code]}"] = $extra[$code] ?? Http::response(aipPdfFixture());
    }

    $fakes['*/descarga/*'] = Http::response(aipPdfFixture());

    Http::fake($fakes);
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
 * Stub PRONAREA's query on the same legacy SMN screen METAR/TAF use. Unlike
 * fakeMetar()/fakeTaf(), there is no second host to fake alongside it: PRONAREA
 * has no NOAA-relayed equivalent, so a test that wants to exercise the stale
 * fallback fakes this to fail (e.g. with smnFixture('challenge.html')) instead.
 */
function fakePronarea(mixed $response = null): void
{
    Http::fake([
        '*observacion=pronarea*' => $response ?? Http::response(smnFixture('pronarea-eze.html')),
    ]);
}

/**
 * Raw SYNOP lines captured verbatim from ogimet.com's getsynop CGI — same
 * reasoning as smnFixture(): the parser is the only thing standing between us
 * and a silently empty AEROMET reply.
 */
function ogimetFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/ogimet/{$name}");
}

/**
 * Stub AEROMET's only source — OGIMET's getsynop CGI. No second host to fake
 * alongside it, same reasoning as fakePronarea(): AEROMET has no NOAA-relayed
 * equivalent, and the SMN, tried here first for a while, is no longer a
 * source at all (see OgimetAerometSource's own docblock for why).
 */
function fakeAeromet(mixed $response = null): void
{
    Http::fake([
        '*ogimet.com*' => $response ?? Http::response(ogimetFixture('ogimet-junin.txt')),
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
 * The ids of the actions a button offers — what a tap sends back to us, and
 * the only part of a button the bot's own grammar has to agree with.
 *
 * @return array<int, string>
 */
function buttonIds(?ReplyButton $button): array
{
    return $button === null ? [] : array_column($button->buttons, 'id');
}

/**
 * One inbound WhatsApp message, shaped the way Meta delivers it: nested under
 * entry → changes → value, with the number in bare digits and the body under
 * either "text" or the tapped button.
 *
 * $tap says which of the two shapes a tap arrives in — a quick-reply button or
 * a row of a list sheet. They differ only in the key the id sits under, which
 * is exactly why the webhook has to be shown reading both.
 *
 * @return array<string, mixed>
 */
function metaPayload(string $from = '5491111111111', ?string $text = 'ezeiza', ?string $buttonId = null, string $messageId = 'wamid.TEST', ?string $profileName = null, string $tap = 'button_reply'): array
{
    $message = ['from' => $from, 'id' => $messageId, 'timestamp' => '1753920000'];

    if ($buttonId !== null) {
        $message['type'] = 'interactive';
        $message['interactive'] = [
            'type' => $tap,
            $tap => ['id' => $buttonId, 'title' => $text ?? $buttonId],
        ];
    } else {
        $message['type'] = 'text';
        $message['text'] = ['body' => (string) $text];
    }

    $value = ['messaging_product' => 'whatsapp', 'messages' => [$message]];

    if ($profileName !== null) {
        $value['contacts'] = [['profile' => ['name' => $profileName], 'wa_id' => $from]];
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => '1357575039812140', 'changes' => [['field' => 'messages', 'value' => $value]]]],
    ];
}

/**
 * POST a payload to the webhook signed the way Meta signs it: HMAC-SHA256 of
 * the raw body under the app secret.
 *
 * @param  array<string, mixed>  $payload
 */
function postSigned(array $payload): TestResponse
{
    $body = (string) json_encode($payload);

    // The header goes in as a server variable rather than through
    // withHeaders(): call() is the only helper that lets us hand over the raw
    // body Meta signs, and it reads server variables alone.
    return test()->call('POST', '/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) config('services.whatsapp.app_secret')),
    ], $body);
}

/**
 * A sender that fails the way Meta does when a message is refused: an HTTP
 * error carrying an error code in its body. 131047 is the one that means the
 * 24-hour service window has closed.
 */
function rejectingSender(int $errorCode): WhatsappSender
{
    return new class($errorCode) implements WhatsappSender
    {
        public function __construct(protected int $errorCode) {}

        public function send(string $to, string $body): void
        {
            $this->reject();
        }

        public function sendWithButtons(string $to, string $body, array $buttons): void
        {
            $this->reject();
        }

        public function sendWithList(string $to, string $body, string $label, array $rows): void
        {
            $this->reject();
        }

        public function sendDocument(string $to, string $url, string $caption, string $filename): void
        {
            $this->reject();
        }

        public function sendLocation(string $to, float $latitude, float $longitude, string $name, string $address): void
        {
            $this->reject();
        }

        public function indicateTyping(string $inboundMessageId): void {}

        protected function reject(): void
        {
            throw new RequestException(new Illuminate\Http\Client\Response(
                new Response(400, [], (string) json_encode(['error' => ['code' => $this->errorCode]])),
            ));
        }
    };
}

function outOfWindowSender(): WhatsappSender
{
    return rejectingSender(131047);
}
