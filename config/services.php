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

    'google_indexing' => [
        'base_uri' => env('GOOGLE_INDEXING_BASE_URI', 'https://indexing.googleapis.com'),
        'credentials_path' => env('GOOGLE_INDEXING_CREDENTIALS_PATH'),
    ],

    'indexnow' => [
        'base_uri' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        'key' => env('INDEXNOW_KEY'),
        'key_location' => env('INDEXNOW_KEY_LOCATION'),
    ],

    'cloudflare' => [
        'base_uri' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'email' => env('CLOUDFLARE_EMAIL'),
    ],

    'catalog_sync' => [
        'token' => env('CATALOG_SYNC_TOKEN', 'x-catalog-sync-token-2026'),
    ],

    'amazon_scraper' => [
        'base_uri' => env('AMAZON_SCRAPER_BASE_URI', 'https://amazon-deals-telegram-bot.andrew-petr132.workers.dev/api/test-single-scrape'),
        'timeout' => env('AMAZON_SCRAPER_TIMEOUT', 60),
        'platform' => env('AMAZON_SCRAPER_PLATFORM', 'amazon'),
    ],

];
