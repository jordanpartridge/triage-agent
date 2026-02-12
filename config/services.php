<?php

return [
    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    'ollama' => [
        'model' => env('OLLAMA_MODEL', 'deepseek-coder:6.7b'),
    ],

    'triage' => [
        'model' => env('TRIAGE_MODEL', 'x-ai/grok-4.1-fast'),
    ],

    'agentctl' => [
        'label' => env('AGENT_READY_LABEL', 'agent-ready'),
        'allowed_repos' => array_filter(explode(',', env('AGENT_ALLOWED_REPOS', 'jordanpartridge/agentctl,jordanpartridge/triage-agent,conduit-ui/knowledge,conduit-ui/connector,conduit-ui/issue,conduit-ui/pr,conduit-ui/commit,conduit-ui/repo'))),
    ],
];
