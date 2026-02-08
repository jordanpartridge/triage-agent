<?php

return [
    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    'ollama' => [
        'model' => env('OLLAMA_MODEL', 'deepseek-coder:6.7b'),
    ],

    'fix' => [
        'model' => env('FIX_MODEL', 'deepseek/deepseek-chat-v3-0324'),
    ],
];
