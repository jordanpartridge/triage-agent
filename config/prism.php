<?php

return [
    'providers' => [
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://100.68.122.24:11434'),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY', ''),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],
    ],
];
