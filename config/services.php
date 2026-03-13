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

    'cognito' => [
        'region' => env('COGNITO_REGION', env('AWS_DEFAULT_REGION', 'eu-west-2')),
        'user_pool_id' => env('COGNITO_USER_POOL_ID'),
        'client_id' => env('COGNITO_CLIENT_ID'),
        'client_secret' => env('COGNITO_CLIENT_SECRET'),
        'auth_flow' => env('COGNITO_AUTH_FLOW', 'USER_PASSWORD_AUTH'),
        'domain' => env('COGNITO_DOMAIN'),
        'redirect_uri' => env('COGNITO_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/auth/callback'),
        'logout_uri' => env('COGNITO_LOGOUT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/logout'),
        'scopes' => array_values(array_filter(array_map('trim', explode(' ', env('COGNITO_SCOPES', 'openid email profile'))))),
        'social_providers' => [
            [
                'slug' => 'google',
                'label' => 'Google',
                'identity_provider' => 'Google',
            ],
            [
                'slug' => 'microsoft',
                'label' => 'Microsoft',
                'identity_provider' => 'Microsoft',
            ],
            [
                'slug' => 'facebook',
                'label' => 'Facebook',
                'identity_provider' => 'Facebook',
            ],
            [
                'slug' => 'apple',
                'label' => 'Apple',
                'identity_provider' => 'SignInWithApple',
            ],
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
