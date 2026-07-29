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
