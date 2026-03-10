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

    'engine' => [
        'secret' => env('ENGINE_CALLBACK_SECRET', ''),
        'callback_ttl' => env('ENGINE_CALLBACK_TTL', 300),
        'api_url' => env('ENGINE_API_URL', 'http://linkflow-api:8000'),
        'partition_count' => env('ENGINE_PARTITION_COUNT', 16),
        'stream_maxlen' => env('ENGINE_STREAM_MAXLEN', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 App Credentials (per provider)
    |--------------------------------------------------------------------------
    |
    | These are YOUR app's OAuth2 client_id / client_secret registered with
    | each provider's developer console. Users will be redirected through
    | these apps when connecting their accounts via the OAuth2 flow.
    |
    */

    'oauth' => [
        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        ],
        'github' => [
            'client_id' => env('GITHUB_CLIENT_ID', ''),
            'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        ],
        'slack' => [
            'client_id' => env('SLACK_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('SLACK_OAUTH_CLIENT_SECRET', ''),
        ],
        'notion' => [
            'client_id' => env('NOTION_CLIENT_ID', ''),
            'client_secret' => env('NOTION_CLIENT_SECRET', ''),
        ],
        'twitter' => [
            'client_id' => env('TWITTER_CLIENT_ID', ''),
            'client_secret' => env('TWITTER_CLIENT_SECRET', ''),
        ],
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID', ''),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET', ''),
        ],
        'dropbox' => [
            'client_id' => env('DROPBOX_CLIENT_ID', ''),
            'client_secret' => env('DROPBOX_CLIENT_SECRET', ''),
        ],
        'microsoft' => [
            'client_id' => env('MICROSOFT_CLIENT_ID', ''),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET', ''),
            'tenant_id' => env('MICROSOFT_TENANT_ID', 'common'),
        ],
        'hubspot' => [
            'client_id' => env('HUBSPOT_CLIENT_ID', ''),
            'client_secret' => env('HUBSPOT_CLIENT_SECRET', ''),
        ],
        'salesforce' => [
            'client_id' => env('SALESFORCE_CLIENT_ID', ''),
            'client_secret' => env('SALESFORCE_CLIENT_SECRET', ''),
        ],
        'shopify' => [
            'client_id' => env('SHOPIFY_CLIENT_ID', ''),
            'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
        ],
        'discord' => [
            'client_id' => env('DISCORD_CLIENT_ID', ''),
            'client_secret' => env('DISCORD_CLIENT_SECRET', ''),
        ],
        'zoom' => [
            'client_id' => env('ZOOM_CLIENT_ID', ''),
            'client_secret' => env('ZOOM_CLIENT_SECRET', ''),
        ],
        'airtable' => [
            'client_id' => env('AIRTABLE_CLIENT_ID', ''),
            'client_secret' => env('AIRTABLE_CLIENT_SECRET', ''),
        ],
    ],

];
