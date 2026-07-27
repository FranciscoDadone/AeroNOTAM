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

    'smn' => [
        // www.smn.gob.ar/metar is an iframe onto this legacy application; it
        // is the same data one hop closer.
        'base_url' => env('SMN_METAR_BASE_URL', 'https://ssl.smn.gob.ar'),

        // METARs are issued hourly (SPECIs in between), so a short cache still
        // serves current data while keeping us well clear of the rate limiting
        // that sits in front of the SMN.
        'metar_ttl' => env('SMN_METAR_TTL', 600),

        // Retries for Cloudflare's intermittent interstitial. Kept low on
        // purpose: retrying hard tightens the block rather than clearing it.
        'attempts' => env('SMN_METAR_ATTEMPTS', 3),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

];
