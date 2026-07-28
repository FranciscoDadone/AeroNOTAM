<?php

use Illuminate\Support\Facades\Schedule;

// Keeps the local aerodrome registry current without putting an HTTP call to
// ANAC on the request path. Hourly is plenty: the set of Argentine aerodromes
// changes on a scale of years, and `last_seen_active_at` only needs to be
// approximately right.
Schedule::command('notams:refresh-airports')->hourly()->withoutOverlapping();

// Every ten minutes lines up with the METAR cache TTL, so a round costs at most
// one request per watched station however many people are watching it. Faster
// would only re-read the cache; slower would mean learning about a SPECI — the
// off-schedule report issued *because* something changed sharply — long after
// the fact.
Schedule::command('metar:watch')->everyTenMinutes()->withoutOverlapping();
