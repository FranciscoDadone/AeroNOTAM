<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anac' => [
        'base_url' => env('ANAC_NOTAM_BASE_URL', 'https://ais.anac.gob.ar'),
        'indicators_ttl' => env('ANAC_NOTAM_INDICATORS_TTL', 3600),
        'notams_ttl' => env('ANAC_NOTAM_TTL', 300),
    ],

    // ANAC's aerodrome registry (MADHEL), on a different host than the NOTAM
    // service and read only by notams:import-madhel.
    'madhel' => [
        'base_url' => env('MADHEL_BASE_URL', 'https://datos.anac.gob.ar'),

        // Below this the response is treated as truncated and the import is
        // abandoned, rather than letting a bad reply shrink the registry.
        'minimum_count' => env('MADHEL_MINIMUM_COUNT', 500),
    ],

    'weather' => [
        // How long to leave a source alone after it fails. The SMN's challenge
        // tightens the more it is hit, so backing off is what lets the block
        // expire; meanwhile the next source answers. Set to 0 to disable.
        //
        // Shared by METAR and TAF: the block is against us at the source's
        // front door, not against one of its pages.
        'source_cooldown' => env('WEATHER_SOURCE_COOLDOWN', env('METAR_SOURCE_COOLDOWN', 900)),
    ],

    'metar' => [
        // METARs are issued hourly (SPECIs in between), so a short cache still
        // serves current data while keeping request volume low — which is what
        // actually keeps us out of the SMN's bot challenge.
        'ttl' => env('METAR_TTL', 600),

        // Standing "tell me when this changes" subscriptions.
        'watch' => [
            // How long a subscription lasts when nobody says otherwise. Twelve
            // hours is the span of a flight-planning day, and it is what the
            // one-tap button on every METAR reply grants.
            'default_ttl' => env('METAR_WATCH_TTL', 43200),

            // The ceiling, and not an arbitrary one: WhatsApp only lets us send
            // freely inside 24 hours of the user's last message, and every alert
            // has to land inside that window or it needs an approved template.
            // A subscription that outlives the window would go silent exactly
            // when it mattered.
            'max_ttl' => env('METAR_WATCH_MAX_TTL', 86400),

            // Origin, destination, alternate, and room to spare — with a ceiling
            // on how much one number can cost us in messages.
            'max_per_phone' => env('METAR_WATCH_MAX', 5),
        ],
    ],

    'taf' => [
        // TAFs are issued every six hours, so the cache can be far longer than
        // the METAR one without ever serving a superseded forecast. Amendments
        // are the reason it is not longer still: an AMD can go out at any time,
        // and it goes out precisely when the forecast changed enough to matter.
        'ttl' => env('TAF_TTL', 1800),
    ],

    'aeromet' => [
        // Same cadence as METAR — AEROMET is a SYNOP-derived surface
        // observation, issued hourly like an ordinary METAR.
        'ttl' => env('AEROMET_TTL', 600),

        // How long a station's own last good reading is kept around to
        // answer with — marked stale — once it drops out of a round of
        // OgimetAerometSource's fetch. There is no second source for AEROMET
        // the way NOAA relays METAR/TAF, so this is what stands between that
        // and an outright failure. An hour: long enough to outlast a
        // several-minute gap, short enough that a reading this old is still
        // saying something true about the weather.
        'stale_ttl' => env('AEROMET_STALE_TTL', 3600),

        // How hard aeromet:refresh-cache retries one FIR group before moving
        // on to the next, and how long it waits between tries. Lower than
        // pronarea.refresh_attempts, and per group rather than for the whole
        // run (see RefreshAerometCache), since OGIMET has shown no sign of
        // the SMN's own sustained blocks — this only needs to ride out an
        // isolated blip, not insist through minutes of a backend under load.
        'refresh_attempts' => env('AEROMET_REFRESH_ATTEMPTS', 3),
        'refresh_retry_seconds' => env('AEROMET_REFRESH_RETRY_SECONDS', 30),
    ],

    // AEROMET's only source — a public aggregator of the WMO's Global
    // Telecommunication System. The SMN was tried here first for a while,
    // but ssl.smn.gob.ar blocks every automated client regardless of which
    // network it runs from (confirmed live), while this does not. See
    // OgimetAerometSource.
    'ogimet' => [
        'base_url' => env('OGIMET_BASE_URL', 'https://www.ogimet.com'),

        // Confirmed live: the whole country in one request answers in well
        // under a second. Generous anyway, since a slow answer here still
        // beats not answering at all.
        'timeout' => env('OGIMET_TIMEOUT', 30),

        // How far back to ask for each station's SYNOP. Confirmed live: a
        // single hour misses stations that report on a slower cadence than
        // hourly (out of 119, 91 answered in a 1-hour window, 117 in six) —
        // six hours is what it takes to catch nearly all of them without
        // serving a reading old enough to no longer describe the current
        // weather.
        'lookback_hours' => env('OGIMET_LOOKBACK_HOURS', 6),
    ],

    'smn' => [
        // www.smn.gob.ar/metar is an iframe onto this legacy application; it
        // is the same data one hop closer.
        'base_url' => env('SMN_METAR_BASE_URL', 'https://ssl.smn.gob.ar'),

        // Retries for Cloudflare's isolated interstitial only. Kept low on
        // purpose: retrying hard tightens the block rather than clearing it,
        // and a sustained block is handled by failing over, not by insisting.
        'attempts' => env('SMN_METAR_ATTEMPTS', 2),

        // The legacy "mensajes" app itself is slow well before Cloudflare
        // gets involved — a live request during development took 8+ seconds
        // to answer and, once, timed out with a Cloudflare 522 on the first
        // try. 15s left too little room for that, especially for AEROMET,
        // which (unlike METAR/TAF) has no NOAA fallback to catch a request
        // that gave up too early.
        'timeout' => env('SMN_METAR_TIMEOUT', 30),
    ],

    'pronarea' => [
        // Reissued every six hours, same cadence as the TAF, so the same TTL
        // order of magnitude serves current data without hammering the SMN.
        'ttl' => env('PRONAREA_TTL', 1800),

        // How long the last successfully fetched bulletin is kept around to
        // answer with — marked stale — when the SMN cannot be reached at all.
        // There is no second source for PRONAREA the way NOAA relays METAR/TAF,
        // so this is what stands between a sustained Cloudflare block and an
        // empty reply. A day comfortably outlives one block.
        'stale_ttl' => env('PRONAREA_STALE_TTL', 86400),

        // How hard pronarea:refresh-cache retries one FIR before moving on,
        // and how long it waits between tries. Deliberately much higher than
        // smn.attempts: that one guards a live WhatsApp reply and has to stay
        // fast, while this runs on the scheduler with nobody waiting on it —
        // and the SMN's own PRONAREA page warns it 502s under load, which is
        // exactly what this is meant to outlast.
        'refresh_attempts' => env('PRONAREA_REFRESH_ATTEMPTS', 20),
        'refresh_retry_seconds' => env('PRONAREA_REFRESH_RETRY_SECONDS', 30),
    ],

    'noaa' => [
        // Fallback for when the SMN is blocking us. Serves the same
        // SMN-issued reports, relayed over the WMO OPMET exchange.
        'base_url' => env('NOAA_METAR_BASE_URL', 'https://aviationweather.gov'),
        'metar_hours' => env('NOAA_METAR_HOURS', 2),

        // Wider than the METAR window because the issue cycle is: a TAF goes
        // out every six hours, so a shorter look-back would come back empty for
        // a station that simply had not reissued yet.
        'taf_hours' => env('NOAA_TAF_HOURS', 8),
    ],

    // Servicio de Hidrografía Naval: salida y puesta del sol, y crepúsculo
    // civil, por ciudad. Es la autoridad argentina en la materia.
    'shn' => [
        'base_url' => env('SHN_BASE_URL', 'https://www.hidro.gov.ar'),

        // Una consulta trae el mes entero, y la efeméride de un mes está
        // publicada de antemano: no cambia nunca. El TTL largo existe para no
        // volver a molestar a un servicio del Estado por una tabla que ya
        // tenemos. Treinta días cubre el mes consultado con margen.
        'ttl' => env('SHN_SUN_TTL', 2592000),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),

        // Content templates for the messages that carry a button. WhatsApp has
        // no free-text way to send one, so these are created once per Twilio
        // account with `php artisan whatsapp:content-templates` and their SIDs
        // pasted here. Left blank, every message still goes out as plain text
        // with the equivalent written command — the button is a convenience on
        // top of an interface that works without it.
        'content_sid_metar' => env('TWILIO_CONTENT_SID_METAR'),
        'content_sid_alert' => env('TWILIO_CONTENT_SID_ALERT'),

        // Offered under a METAR that came back empty, for the aerodromes
        // AEROMET also covers under the same name — a next thing to try
        // instead of a dead end.
        'content_sid_aeromet' => env('TWILIO_CONTENT_SID_AEROMET'),

        // One menu template per topic, offering the other three for the same
        // aerodrome. There are four rather than one because a quick-reply
        // template's captions are fixed when it is registered: the only thing
        // that can vary at send time is the aerodrome, which rides in {{2}}.
        // Left blank, the menu is not sent at all — unlike the two above it has
        // no message to ride on, and an extra message repeating three commands
        // the help text already lists would be noise rather than graceful
        // degradation.
        'content_sid_menu_notam' => env('TWILIO_CONTENT_SID_MENU_NOTAM'),
        'content_sid_menu_metar' => env('TWILIO_CONTENT_SID_MENU_METAR'),
        'content_sid_menu_taf' => env('TWILIO_CONTENT_SID_MENU_TAF'),
        'content_sid_menu_crepusculo' => env('TWILIO_CONTENT_SID_MENU_CREPUSCULO'),
    ],

];
