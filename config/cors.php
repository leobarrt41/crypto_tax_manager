<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'dashboard/*', 'trading-bot/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];