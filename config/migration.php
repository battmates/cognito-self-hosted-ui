<?php

return [
    'enabled' => (bool) env('LEGACY_MIGRATION_ENABLED', false),
    'providers' => [
        // Array order is authentication fallback order: MusicTeacher, then RSL Cloud.
        'musicteacher' => ['driver' => 'wordpress_hmac', 'enabled' => (bool) env('MIGRATION_WORDPRESS_ENABLED', false), 'endpoint' => env('MIGRATION_WORDPRESS_ENDPOINT'), 'secret' => env('MIGRATION_WORDPRESS_SHARED_SECRET')],
        'cloud' => ['driver' => 'json_bearer', 'enabled' => (bool) env('MIGRATION_CLOUD_ENABLED', false), 'endpoint' => env('MIGRATION_CLOUD_ENDPOINT'), 'token' => env('MIGRATION_CLOUD_API_TOKEN')],
    ],
];
