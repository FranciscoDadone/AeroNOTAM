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

    'smn' => [
        // www.smn.gob.ar/metar is an iframe onto this legacy application; it
        // is the same data one hop closer.
        'base_url' => env('SMN_METAR_BASE_URL', 'https://ssl.smn.gob.ar'),

        // Retries for Cloudflare's isolated interstitial only. Kept low on
        // purpose: retrying hard tightens the block rather than clearing it,
        // and a sustained block is handled by failing over, not by insisting.
        'attempts' => env('SMN_METAR_ATTEMPTS', 2),
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

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),

        // Content templates for the two messages that carry a button. WhatsApp
        // has no free-text way to send one, so these are created once per Twilio
        // account with `php artisan whatsapp:content-templates` and their SIDs
        // pasted here. Left blank, both messages still go out as plain text with
        // the equivalent written command — the button is a convenience on top of
        // an interface that works without it.
        'content_sid_metar' => env('TWILIO_CONTENT_SID_METAR'),
        'content_sid_alert' => env('TWILIO_CONTENT_SID_ALERT'),
    ],

];
