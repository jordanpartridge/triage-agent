<?php

return [
    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    'ollama' => [
        'model' => env('OLLAMA_MODEL', 'deepseek-coder:6.7b'),
    ],
];
