<?php

return [
    'state_ttl_seconds' => (int) env('SSO_STATE_TTL_SECONDS', 300),

    'claim_map' => [
        'subject' => env('SSO_CLAIM_SUBJECT', 'sub'),
        'email' => env('SSO_CLAIM_EMAIL', 'email'),
        'first_name' => env('SSO_CLAIM_FIRST_NAME', 'given_name'),
        'last_name' => env('SSO_CLAIM_LAST_NAME', 'family_name'),
        'roles' => array_filter(array_map('trim', explode(',', env('SSO_CLAIM_ROLES', 'custom:user_type,cognito:groups')))),
    ],

    'consumers' => [
        'wordpress_backstage' => [
            'label' => env('SSO_SITE_WORDPRESS_LABEL', 'Rockschool Backstage'),
            'driver' => env('SSO_SITE_WORDPRESS_DRIVER', 'wordpress'),
            'base_url' => env('SSO_SITE_WORDPRESS_BASE_URL'),
            'login_path' => env('SSO_SITE_WORDPRESS_LOGIN_PATH', '/wp-login.php'),
            'callback_path' => env('SSO_SITE_WORDPRESS_CALLBACK_PATH', '/cognito-login'),
            'logout_path' => env('SSO_SITE_WORDPRESS_LOGOUT_PATH', '/logout'),
            'allowed_return_hosts' => array_values(array_filter(array_map('trim', explode(',', env('SSO_SITE_WORDPRESS_ALLOWED_RETURN_HOSTS', ''))))),
        ],
    ],
];
