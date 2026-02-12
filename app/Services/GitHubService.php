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

    public function getRepoTree(string $repo, string $branch = 'main'): array
    {
        $response = Http::withToken(config('services.github.token'))
            ->get("https://api.github.com/repos/{$repo}/git/trees/{$branch}", [
                'recursive' => '1',
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('tree', []))
            ->where('type', 'blob')
            ->pluck('path')
            ->filter(fn (string $path) => ! str_starts_with($path, 'vendor/')
                && ! str_starts_with($path, 'node_modules/')
                && ! str_starts_with($path, '.git/'))
            ->values()
            ->all();
    }
}
