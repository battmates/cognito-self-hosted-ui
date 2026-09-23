<?php

return [
    'enabled' => (bool) env('LEGACY_MIGRATION_ENABLED', false),
    'password_recovery_enabled' => (bool) env('LEGACY_PASSWORD_RECOVERY_ENABLED', false),
    'providers' => [
        'wordpress' => [
            'enabled' => (bool) env('MIGRATION_WORDPRESS_ENABLED', false),
            'endpoint' => env('MIGRATION_WORDPRESS_ENDPOINT'),
            'lookup_endpoint' => env('MIGRATION_WORDPRESS_LOOKUP_ENDPOINT'),
            'token' => env('MIGRATION_WORDPRESS_API_TOKEN'),
        ],
        'cloud' => [
            'enabled' => (bool) env('MIGRATION_CLOUD_ENABLED', false),
            'endpoint' => env('MIGRATION_CLOUD_ENDPOINT'), 
            'token' => env('MIGRATION_CLOUD_API_TOKEN')
        ],
    ],
];
