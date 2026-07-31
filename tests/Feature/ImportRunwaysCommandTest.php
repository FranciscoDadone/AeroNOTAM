<?php

use App\Models\Airport;
use App\Models\Runway;
use Illuminate\Support\Facades\Http;

/**
 * The import is where every runway heading the bot ever quotes comes from, and
 * the two sources it reconciles disagree in specific, known ways. These pin the
 * reconciliation: which source wins, what counts as a runway in MADHEL's prose,
 * and what happens to a published heading that cannot be true.
 */
function fakeRunwaySources(array $madhel, string $csv = ''): void
{
    $fakes = ['*ngdc.noaa.gov*' => Http::response(['result' => [['declination' => -9.0]]])];

    foreach ($madhel as $anacCode => $rwy) {
        $fakes["*/madhel/api/v2/airports/{$anacCode}/*"] = Http::response([
            'data' => ['rwy' => $rwy],
        ]);
    }

    $fakes['*ourairports-data/runways.csv*'] = Http::response($csv !== '' ? $csv : ourAirportsCsv());
    $fakes['*'] = Http::response(['data' => ['rwy' => []]]);

    Http::fake($fakes);
}

function ourAirportsCsv(array $rows = []): string
{
    $header = '"id","airport_ref","airport_ident","length_ft","width_ft","surface","lighted","closed",'
        .'"le_ident","le_latitude_deg","le_longitude_deg","le_elevation_ft","le_heading_degT","le_displaced_threshold_ft",'
        .'"he_ident","he_latitude_deg","he_longitude_deg","he_elevation_ft","he_heading_degT","he_displaced_threshold_ft"';

    $lines = array_map(
        fn (array $r) => sprintf(
            '1,1,"%s",9842,200,"CON",1,%d,"%s",,,,%s,,"%s",,,,%s,',
            $r['icao'],
            $r['closed'] ?? 0,
            $r['le'],
            $r['le_heading'] ?? '',
            $r['he'],
            $r['he_heading'] ?? '',
        ),
        $rows,
    );

    return implode("\n", [$header, ...$lines])."\n";
}

beforeEach(function () {
    // The registry is seeded for every test; the import walks all of it, so
    // each case narrows to the aerodromes it is actually about.
    Airport::query()->whereNotIn('anac_code', ['EZE', 'NIN', 'IRA', 'ENO', 'TRC'])->delete();
});

it('reads runway designators out of MADHEL and derives their true headings', function () {
    fakeRunwaySources(['NIN' => ['18/36 1500x30 M - Tierra.']]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    // Designator 18 is 180° magnetic; with 9° of westerly variation that is
    // 171° true.
    expect(Runway::where('anac_code', 'NIN')->pluck('heading_true', 'designator')->all())
        ->toBe(['18' => 171, '36' => 351]);

    expect(Runway::where('anac_code', 'NIN')->pluck('source')->unique()->all())->toBe(['madhel']);
});

/**
 * MADHEL's rwy list is prose with a designator at the front of *some* lines.
 * The rest talk about runways — a strip width, a threshold coordinate — and
 * would each invent a runway that does not exist if they were read as one.
 */
it('ignores the notes MADHEL keeps in the same list as its runways', function () {
    fakeRunwaySources(['NIN' => [
        '18/36 1500x30 M - Tierra.',
        'Franja de RWY 18/36 1.711x140 M.',
        'Extremo RWY 36 343252,36S 0590451,26W - ELEV 23 M AMSL (75 FT).',
        'RESA 90x56 M.',
        'Pendiente de RWY 18/36 0,015%.',
    ]]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    expect(Runway::where('anac_code', 'NIN')->pluck('designator')->sort()->values()->all())
        ->toBe(['18', '36']);
});

/**
 * Mariano Moreno is the one entry in the whole registry that writes its
 * parallel runways with a space before the L/R. Without it the aerodrome has no
 * runways at all.
 */
it('parses parallel runways written with a space before the side', function () {
    fakeRunwaySources(['ENO' => [
        '01 R/19 L 1377x23 M - CONC - AUW 13t/1 20t/2.',
        '01 L/19 R 1107x44 M - Tierra.',
    ]]);

    $this->artisan('notams:import-runways --only=ENO')->assertSuccessful();

    expect(Runway::where('anac_code', 'ENO')->pluck('designator')->sort()->values()->all())
        ->toBe(['01L', '01R', '19L', '19R']);
});

/**
 * A closed runway is operational information. A pilot who sees three runways on
 * the chart and two in the answer has been told something false.
 */
it('keeps closed runways and marks them rather than dropping them', function () {
    fakeRunwaySources(['IRA' => [
        '04/22 619x30 M - Tierra.',
        '09/27 600x30 M - Tierra (CLSD).',
    ]]);

    $this->artisan('notams:import-runways --only=IRA')->assertSuccessful();

    expect(Runway::where('anac_code', 'IRA')->count())->toBe(4)
        ->and(Runway::where('anac_code', 'IRA')->where('is_closed', true)->pluck('designator')->sort()->values()->all())
        ->toBe(['09', '27']);
});

/**
 * MADHEL publishes an empty runway list for the busiest aerodromes — they defer
 * to the AIP — which is the entire reason a second source exists.
 */
it('falls back to OurAirports when MADHEL lists no runways', function () {
    fakeRunwaySources(
        ['EZE' => []],
        ourAirportsCsv([
            ['icao' => 'SAEZ', 'le' => '11', 'le_heading' => '102.3', 'he' => '29', 'he_heading' => '282.3'],
        ]),
    );

    $this->artisan('notams:import-runways --only=EZE')->assertSuccessful();

    // The published heading is not rounded to ten degrees the way a designator
    // is, so it wins over the derived one whenever the two agree.
    expect(Runway::where('anac_code', 'EZE')->pluck('heading_true', 'designator')->all())
        ->toBe(['11' => 102, '29' => 282])
        ->and(Runway::where('anac_code', 'EZE')->pluck('source')->unique()->all())
        ->toBe(['ourairports']);
});

/**
 * OurAirports has SAOC's runway 05 at a true heading of 178°, which is 128°
 * from anywhere a runway numbered 05 can point. Believing it would put the
 * crosswind on the wrong side of the aircraft.
 */
it('disbelieves a published heading that contradicts its own designator', function () {
    fakeRunwaySources(
        ['TRC' => []],
        ourAirportsCsv([
            ['icao' => 'SAOC', 'le' => '05', 'le_heading' => '178.0', 'he' => '23', 'he_heading' => '358.0'],
        ]),
    );

    $this->artisan('notams:import-runways --only=TRC')->assertSuccessful();

    // 50° magnetic − 9° of variation = 41° true, from the designator alone.
    expect(Runway::where('anac_code', 'TRC')->pluck('heading_true', 'designator')->all())
        ->toBe(['05' => 41, '23' => 221]);
});

/**
 * Magnetic drift eventually forces ANAC to renumber a runway. Without the
 * prune, the old pair would linger beside the new one and the bot would offer
 * a cabecera that no longer exists.
 */
it('drops runway ends that are no longer published', function () {
    Runway::create(['anac_code' => 'NIN', 'designator' => '17', 'heading_true' => 161, 'is_closed' => false, 'source' => 'madhel']);
    Runway::create(['anac_code' => 'NIN', 'designator' => '35', 'heading_true' => 341, 'is_closed' => false, 'source' => 'madhel']);

    fakeRunwaySources(['NIN' => ['18/36 1500x30 M - Tierra.']]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    expect(Runway::where('anac_code', 'NIN')->pluck('designator')->sort()->values()->all())
        ->toBe(['18', '36']);
});

/**
 * The ficha quotes the strip, not just its heading, and both sources carry it —
 * MADHEL in the prose it writes after the designator, OurAirports in four
 * columns the import was reading past. Both ends of a runway get the figures,
 * because the table is one row per end and 18 is as long as 36 is.
 */
it('reads the dimensions and the surface out of MADHEL', function () {
    fakeRunwaySources(['NIN' => ['18/36 1500x30 M - Tierra.']]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    foreach (Runway::where('anac_code', 'NIN')->get() as $runway) {
        expect($runway->length_m)->toBe(1500)
            ->and($runway->width_m)->toBe(30)
            ->and($runway->surface)->toBe('tierra');
    }
});

/**
 * OurAirports publishes feet; MADHEL publishes metres and so do Argentine
 * charts, so the conversion happens on write and a reader never has to ask
 * which source a number came from.
 */
it('converts the OurAirports dimensions to metres', function () {
    fakeRunwaySources(
        ['EZE' => []],
        ourAirportsCsv([
            ['icao' => 'SAEZ', 'le' => '11', 'le_heading' => '102.3', 'he' => '29', 'he_heading' => '282.3'],
        ]),
    );

    $this->artisan('notams:import-runways --only=EZE')->assertSuccessful();

    // 9842 ft × 0,3048 = 3000 m; 200 ft = 61 m; "CON" is hormigón in both
    // sources' shared vocabulary, and lighted=1 is a statement.
    $runway = Runway::where('anac_code', 'EZE')->firstOrFail();

    expect($runway->length_m)->toBe(3000)
        ->and($runway->width_m)->toBe(61)
        ->and($runway->surface)->toBe('hormigón')
        ->and($runway->is_lighted)->toBeTrue();
});

/**
 * MADHEL decides which runways exist — it is ANAC's own registry — but where it
 * publishes a designator and nothing else, a figure from the open registry
 * beats no figure at all. What it must never do is replace one MADHEL did
 * publish.
 */
it('fills in what MADHEL left blank from OurAirports without overwriting it', function () {
    fakeRunwaySources(
        ['NIN' => ['18/36 1500x30 M - Tierra.']],
        ourAirportsCsv([
            ['icao' => 'SAAJ', 'le' => '18', 'le_heading' => '171', 'he' => '36', 'he_heading' => '351'],
        ]),
    );

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    $runway = Runway::where('anac_code', 'NIN')->where('designator', '18')->firstOrFail();

    expect($runway->length_m)->toBe(1500)          // MADHEL's, not the CSV's 3000
        ->and($runway->surface)->toBe('tierra')    // not the CSV's hormigón
        ->and($runway->is_lighted)->toBeTrue()     // the gap MADHEL left
        ->and($runway->source)->toBe('madhel');
});

it('caches the magnetic variation on the aerodrome', function () {
    fakeRunwaySources(['NIN' => ['18/36 1500x30 M - Tierra.']]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    expect(Airport::where('anac_code', 'NIN')->value('magnetic_variation'))->toEqual(-9.0);

    // A second run must not ask NOAA again: the value drifts a tenth of a
    // degree a year, and the weekly schedule would otherwise hammer it.
    Http::fake(['*ngdc.noaa.gov*' => Http::response(['result' => [['declination' => 99.0]]])]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    expect(Airport::where('anac_code', 'NIN')->value('magnetic_variation'))->toEqual(-9.0);
});

/**
 * MADHEL alone still covers most of the registry, so a partial import beats
 * none — the aerodromes that need OurAirports keep whatever they already had.
 */
it('still imports from MADHEL when OurAirports cannot be read', function () {
    Http::fake([
        '*ngdc.noaa.gov*' => Http::response(['result' => [['declination' => -9.0]]]),
        '*ourairports-data/runways.csv*' => Http::response('', 503),
        '*/madhel/api/v2/airports/NIN/*' => Http::response(['data' => ['rwy' => ['18/36 1500x30 M - Tierra.']]]),
        '*' => Http::response(['data' => ['rwy' => []]]),
    ]);

    $this->artisan('notams:import-runways --only=NIN')->assertSuccessful();

    expect(Runway::where('anac_code', 'NIN')->count())->toBe(2);
});
