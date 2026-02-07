<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GitHubService
{
    public function postComment(string $repo, int $issueNumber, string $body): void
    {
        Http::withToken(config('services.github.token'))
            ->post("https://api.github.com/repos/{$repo}/issues/{$issueNumber}/comments", [
                'body' => $body,
            ]);
    }
}
