<?php

$csv = fn ($value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    'state_ttl_seconds' => (int) env('SSO_STATE_TTL_SECONDS', 900),
    'code_ttl_seconds' => 60,
    'claim_map' => [
        'subject' => 'sub',
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'roles' => $csv(env('SSO_CLAIM_ROLES', 'custom:user_role,custom:user_type,cognito:groups')),
    ],
    // Every enabled consumer currently uses this portal's existing Cognito client.
    // Exact callback URLs, not wildcard hosts, are the authorization boundary.
    'consumers' => [
        'guestlist' => [
            'label' => 'Guestlist',
            'base_url' => env('SSO_GUESTLIST_URL', 'https://staging.guestlist.rockschool.io'),
            'callback_urls' => $csv(env('SSO_GUESTLIST_CALLBACK_URLS', 'https://staging.guestlist.rockschool.io/cognito/callback')),
            'logout_urls' => $csv(env('SSO_GUESTLIST_LOGOUT_URLS', 'https://staging.guestlist.rockschool.io/logout')),
        ],
        'backstage' => [
            'label' => 'Backstage',
            'base_url' => env('SSO_BACKSTAGE_URL', 'https://staging.rockschool.io'),
            'callback_urls' => $csv(env('SSO_BACKSTAGE_CALLBACK_URLS', 'https://staging.rockschool.io/cognito-login')),
            'logout_urls' => $csv(env('SSO_BACKSTAGE_LOGOUT_URLS', 'https://staging.rockschool.io/logout')),
        ],
        'cloud' => ['label' => 'RSL Cloud', 'base_url' => env('SSO_CLOUD_URL'), 'callback_urls' => $csv(env('SSO_CLOUD_CALLBACK_URLS')), 'logout_urls' => $csv(env('SSO_CLOUD_LOGOUT_URLS'))],
        'musicteacher' => ['label' => 'MusicTeacher.com', 'base_url' => env('SSO_MUSICTEACHER_URL'), 'callback_urls' => $csv(env('SSO_MUSICTEACHER_CALLBACK_URLS')), 'logout_urls' => $csv(env('SSO_MUSICTEACHER_LOGOUT_URLS'))],
        'spotlight' => ['label' => 'Spotlight', 'base_url' => env('SSO_SPOTLIGHT_URL'), 'callback_urls' => $csv(env('SSO_SPOTLIGHT_CALLBACK_URLS')), 'logout_urls' => $csv(env('SSO_SPOTLIGHT_LOGOUT_URLS'))],
    ],
    'management_roles' => ['admin', 'administrator', 'ops_manager'],
    'admin_role_attributes' => $csv(env('COGNITO_ADMIN_ROLE_ATTRIBUTES', 'custom:user_role,custom:user_type')),
    'management_writes_enabled' => (bool) env('COGNITO_MANAGEMENT_WRITES_ENABLED', true),
];
