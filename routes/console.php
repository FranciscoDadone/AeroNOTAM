<?php

use Illuminate\Support\Facades\Schedule;

// Keeps the local aerodrome registry current without putting an HTTP call to
// ANAC on the request path. Hourly is plenty: the set of Argentine aerodromes
// changes on a scale of years, and `last_seen_active_at` only needs to be
// approximately right.
Schedule::command('notams:refresh-airports')->hourly()->withoutOverlapping();

// The registry itself — which aerodromes exist at all, as opposed to which have
// a NOTAM today. Weekly because MADHEL changes on the scale of years; the
// committed snapshot already covers a fresh install, so this only picks up
// aerodromes opened or closed since the last release.
Schedule::command('notams:import-madhel')->weeklyOn(1, '04:00')->withoutOverlapping();

// Runway headings, for the wind-component answer. An hour behind the import
// above because that is what decides which aerodromes exist to ask about.
//
// Weekly despite costing one request per aerodrome — MADHEL only lists runways
// on the per-aerodrome record — because runways are the most static thing in
// the whole registry: they move when ANAC repaves or renumbers one, which is a
// scale of years. There is no committed snapshot to fall back on, so a fresh
// install has to run this once by hand before the feature answers anything.
Schedule::command('notams:import-runways')->weeklyOn(1, '05:00')->withoutOverlapping();

// What each aerodrome *is* — distance and bearing from its town, elevation,
// FIR, fuel, telephone — which is the ficha the bot answers with when a message
// names a place and asks nothing in particular about it.
//
// Behind both imports above because it depends on them: import-madhel decides
// which aerodromes exist to ask MADHEL about, and this walks that list. Weekly
// for the same reason as the runways — an aerodrome's elevation and the town it
// serves change on the scale of decades — and pooled for the same one: MADHEL
// only carries these on the per-aerodrome record.
Schedule::command('notams:import-airport-details')->weeklyOn(1, '06:00')->withoutOverlapping();

// Fuel, telephone, hours and ATS frequency for the aerodromes MADHEL delegates
// to the AIP instead of publishing them itself — the import above leaves
// exactly those three fields null for exactly this set, and this is what
// fills them in from the source MADHEL itself points to.
//
// An hour behind notams:import-airport-details for the same reason that one
// is behind notams:import-madhel: it depends on is_aip_delegated, which that
// import is what sets. Weekly for the same reason as the rest — an AIP
// amendment landing mid-week is a rare event, not something worth polling for.
Schedule::command('notams:import-aip-details')->weeklyOn(1, '07:00')->withoutOverlapping();

// Every ten minutes lines up with the METAR cache TTL, so a round costs at most
// one request per watched station however many people are watching it. Faster
// would only re-read the cache; slower would mean learning about a SPECI — the
// off-schedule report issued *because* something changed sharply — long after
// the fact.
Schedule::command('metar:watch')->everyTenMinutes()->withoutOverlapping();

// PRONAREA is reissued at 03/09/15/21 UTC, one hour to seventy minutes before
// its own validity window starts (see the SMN's PRONAREA_Pronostico_Area.pdf).
// Fifteen minutes after each gives the SMN room to have actually published
// before this asks, and the command's own retry loop — far more patient than
// a live WhatsApp reply could ever afford to be — absorbs the 502s its page
// warns happen under load.
Schedule::command('pronarea:refresh-cache')
    ->cron('15 3,9,15,21 * * *')
    ->timezone('UTC')
    ->withoutOverlapping();

// Same cadence as metar:watch, for the same reason: it lines up with
// aeromet.ttl, so each of AerometStationResolver::FIR_GROUPS costs at most
// one request however many of its stations get asked about in between.
Schedule::command('aeromet:refresh-cache')->everyFiveMinutes()->withoutOverlapping();
