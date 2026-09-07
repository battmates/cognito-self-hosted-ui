<?php

return [
    'region' => env('SES_REPORTING_REGION', env('COGNITO_REGION', env('AWS_DEFAULT_REGION', 'eu-west-2'))),
    'configuration_set' => env('SES_REPORTING_CONFIGURATION_SET'),
    'from_address' => env('SES_REPORTING_FROM_ADDRESS'),
    'cache_seconds' => 300,
];
