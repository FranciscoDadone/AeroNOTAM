<?php

use Illuminate\Support\Facades\Schedule;

// Keeps the local aerodrome registry current without putting an HTTP call to
// ANAC on the request path. Hourly is plenty: the set of Argentine aerodromes
// changes on a scale of years, and `last_seen_active_at` only needs to be
// approximately right.
Schedule::command('notams:refresh-airports')->hourly()->withoutOverlapping();
