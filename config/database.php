<?php

return [
    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'default' => [
            'host' => env('REDIS_HOST', '100.68.122.24'),
            'port' => env('REDIS_PORT', 6380),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', 0),
        ],
    ],
];
