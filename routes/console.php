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
Schedule::command('aeromet:refresh-cache')->everyTenMinutes()->withoutOverlapping();
