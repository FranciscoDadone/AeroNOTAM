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

    'metar' => [
        // METARs are issued hourly (SPECIs in between), so a short cache still
        // serves current data while keeping request volume low — which is what
        // actually keeps us out of the SMN's bot challenge.
        'ttl' => env('METAR_TTL', 600),

        // How long to leave a source alone after it fails. The SMN's challenge
        // tightens the more it is hit, so backing off is what lets the block
        // expire; meanwhile the next source answers. Set to 0 to disable.
        'source_cooldown' => env('METAR_SOURCE_COOLDOWN', 900),
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
        'hours' => env('NOAA_METAR_HOURS', 2),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

];
