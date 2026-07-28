<?php

use App\Jobs\NotifyMetarChange;
use App\Jobs\SendWhatsappMessage;
use App\Models\MetarSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const CALM = 'METAR SAEZ 271200Z 18008KT 9999 SCT020 22/14 Q1013';

const WARMER = 'METAR SAEZ 271300Z 18010KT 9999 SCT020 24/15 Q1013';

const STORMY = 'METAR SAEZ 271300Z 20025G40KT 0800 +TSRA OVC003 21/20 Q1002';

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

function watching(string $lastRaw = CALM, string $phone = 'whatsapp:+5491122334455', string $anac = 'EZE', string $icao = 'SAEZ'): MetarSubscription
{
    return MetarSubscription::create([
        'phone' => $phone,
        'anac_code' => $anac,
        'icao_code' => $icao,
        'expires_at' => now()->addHours(6),
        'last_raw' => $lastRaw,
    ]);
}

it('notifies a watcher when the weather has actually changed', function () {
    $subscription = watching();
    fakeMetar(Http::response(smnMetarWith(STORMY)));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertPushed(NotifyMetarChange::class, 1);

    expect($subscription->refresh()->last_raw)->toBe(STORMY)
        ->and($subscription->last_notified_at)->not->toBeNull();
});

/**
 * The ordinary hourly case, and the one the whole feature turns on: a new
 * report that says the same thing must not ring anybody's phone.
 */
it('stays quiet when the new report says the same thing', function () {
    $subscription = watching();
    fakeMetar(Http::response(smnMetarWith(WARMER)));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertNotPushed(NotifyMetarChange::class);

    // The baseline still advances. Leaving it at the last *notified* report
    // would turn a slow drift into an alert hours later about a change nobody
    // would have noticed happening.
    expect($subscription->refresh()->last_raw)->toBe(WARMER)
        ->and($subscription->last_notified_at)->toBeNull();
});

it('does nothing at all when the report has not been reissued', function () {
    $subscription = watching();
    fakeMetar(Http::response(smnMetarWith(CALM)));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertNotPushed(NotifyMetarChange::class);
    expect($subscription->refresh()->last_raw)->toBe(CALM);
});

/**
 * A source failure must not consume the baseline: the next round would then
 * compare the current weather against a report it never saw.
 */
it('leaves the baseline alone when no source answers', function () {
    $subscription = watching();
    fakeMetar(Http::response(smnFixture('challenge.html'), 403), Http::response('down', 503));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertNotPushed(NotifyMetarChange::class);
    expect($subscription->refresh()->last_raw)->toBe(CALM);
});

it('leaves the baseline alone when the station publishes nothing', function () {
    $subscription = watching();
    fakeMetar(Http::response(smnFixture('metar-empty.html')));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertNotPushed(NotifyMetarChange::class);
    expect($subscription->refresh()->last_raw)->toBe(CALM);
});

/**
 * One request per watched station, not per watcher — the whole reason the round
 * groups by ICAO code before asking anyone anything.
 */
it('asks the SMN once however many people are watching a station', function () {
    watching(phone: 'whatsapp:+5491111111111');
    watching(phone: 'whatsapp:+5492222222222');
    fakeMetar(Http::response(smnMetarWith(STORMY)));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertPushed(NotifyMetarChange::class, 2);

    $smnRequests = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'mensajes/index.php'))
        ->count();

    expect($smnRequests)->toBe(1);
});

/**
 * Each watcher is measured from their own baseline, so two people watching the
 * same aerodrome from different starting points get different answers.
 */
it('compares each watcher against their own baseline', function () {
    watching(lastRaw: CALM, phone: 'whatsapp:+5491111111111');
    watching(lastRaw: STORMY, phone: 'whatsapp:+5492222222222');
    fakeMetar(Http::response(smnMetarWith(STORMY)));

    $this->artisan('metar:watch')->assertSuccessful();

    // The second was already told about the storm; only the first hears of it.
    Queue::assertPushed(NotifyMetarChange::class, 1);
});

it('closes an expired watch and says so', function () {
    $subscription = watching();
    $subscription->update(['expires_at' => now()->subMinute()]);

    fakeMetar();

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertPushed(SendWhatsappMessage::class, 1);
    expect(MetarSubscription::query()->count())->toBe(0);
});

/**
 * Expiry is settled before anything is fetched, so a round never queries the
 * SMN on behalf of a watch that has already ended.
 */
it('does not query the SMN for a watch that has expired', function () {
    $subscription = watching();
    $subscription->update(['expires_at' => now()->subMinute()]);

    fakeMetar();

    $this->artisan('metar:watch')->assertSuccessful();

    Http::assertNothingSent();
});

it('carries the change list and the report through to the job', function () {
    watching();
    fakeMetar(Http::response(smnMetarWith(STORMY)));

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertPushed(function (NotifyMetarChange $job) {
        $changes = (new ReflectionProperty($job, 'changes'))->getValue($job);
        $metar = (new ReflectionProperty($job, 'metar'))->getValue($job);

        return $metar['raw'] === STORMY
            && collect($changes)->contains(fn (string $c) => str_contains($c, 'Categoría de vuelo'));
    });
});

it('watches several stations in one round', function () {
    watching(anac: 'EZE', icao: 'SAEZ');
    watching(anac: 'AER', icao: 'SABE');

    Http::fake([
        '*CODIGO=SAEZ*' => Http::response(smnMetarWith(STORMY)),
        '*CODIGO=SABE*' => Http::response(smnMetarWith(str_replace('SAEZ', 'SABE', STORMY), 'AEROPARQUE')),
        '*aviationweather.gov*' => Http::response([]),
    ]);

    $this->artisan('metar:watch')->assertSuccessful();

    Queue::assertPushed(NotifyMetarChange::class, 2);
});
