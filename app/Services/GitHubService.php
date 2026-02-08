<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubService
{
    private const EXCLUDED_PREFIXES = ['vendor/', 'node_modules/', '.git/', 'storage/'];

    private const MAX_RETRIES = 3;

    private const BASE_DELAY_SECONDS = 1;

    protected ?\Closure $sleeper = null;

    public function setSleeper(\Closure $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function postComment(string $repo, int $issueNumber, string $body): void
    {
        $this->withRetry(function () use ($repo, $issueNumber, $body) {
            $response = Http::withToken(config('services.github.token'))
                ->post("https://api.github.com/repos/{$repo}/issues/{$issueNumber}/comments", [
                    'body' => $body,
                ]);

            $response->throw();
        }, "postComment({$repo}#{$issueNumber})");
    }

    public function getRepoTree(string $repo, string $branch = 'main'): array
    {
        try {
            return $this->withRetry(function () use ($repo, $branch) {
                $response = Http::withToken(config('services.github.token'))
                    ->get("https://api.github.com/repos/{$repo}/git/trees/{$branch}", [
                        'recursive' => '1',
                    ]);

                $response->throw();

                $tree = $response->json('tree', []);

                return collect($tree)
                    ->where('type', 'blob')
                    ->pluck('path')
                    ->reject(fn (string $path) => $this->isExcluded($path))
                    ->take(50)
                    ->values()
                    ->all();
            }, "getRepoTree({$repo})");
        } catch (\Throwable) {
            return [];
        }
    }

    public function getIssue(string $repo, int $issueNumber): array
    {
        return $this->withRetry(function () use ($repo, $issueNumber) {
            $response = Http::withToken(config('services.github.token'))
                ->get("https://api.github.com/repos/{$repo}/issues/{$issueNumber}");

            $response->throw();

            return $response->json();
        }, "getIssue({$repo}#{$issueNumber})");
    }

    public function getPullRequest(string $repo, int $prNumber): array
    {
        return $this->withRetry(function () use ($repo, $prNumber) {
            $response = Http::withToken(config('services.github.token'))
                ->get("https://api.github.com/repos/{$repo}/pulls/{$prNumber}");

            $response->throw();

            return $response->json();
        }, "getPullRequest({$repo}#{$prNumber})");
    }

    public function getPullRequestFiles(string $repo, int $prNumber): array
    {
        return $this->withRetry(function () use ($repo, $prNumber) {
            $response = Http::withToken(config('services.github.token'))
                ->get("https://api.github.com/repos/{$repo}/pulls/{$prNumber}/files");

            $response->throw();

            return $response->json();
        }, "getPullRequestFiles({$repo}#{$prNumber})");
    }

    public function getFileContent(string $repo, string $path, string $branch = 'main'): array
    {
        return $this->withRetry(function () use ($repo, $path, $branch) {
            $response = Http::withToken(config('services.github.token'))
                ->get("https://api.github.com/repos/{$repo}/contents/{$path}", [
                    'ref' => $branch,
                ]);

            $response->throw();

            return [
                'content' => base64_decode($response->json('content')),
                'sha' => $response->json('sha'),
            ];
        }, "getFileContent({$repo}/{$path})");
    }

    public function createBranch(string $repo, string $branchName, string $fromBranch): void
    {
        $this->withRetry(function () use ($repo, $branchName, $fromBranch) {
            $response = Http::withToken(config('services.github.token'))
                ->get("https://api.github.com/repos/{$repo}/git/ref/heads/{$fromBranch}");

            $response->throw();

            $sha = $response->json('object.sha');

            $response = Http::withToken(config('services.github.token'))
                ->post("https://api.github.com/repos/{$repo}/git/refs", [
                    'ref' => "refs/heads/{$branchName}",
                    'sha' => $sha,
                ]);

            $response->throw();
        }, "createBranch({$repo}/{$branchName})");
    }

    public function commitFile(string $repo, string $path, string $content, string $message, string $branch, ?string $sha = null): void
    {
        $this->withRetry(function () use ($repo, $path, $content, $message, $branch, $sha) {
            $payload = [
                'message' => $message,
                'content' => base64_encode($content),
                'branch' => $branch,
            ];

            if ($sha !== null) {
                $payload['sha'] = $sha;
            }

            $response = Http::withToken(config('services.github.token'))
                ->put("https://api.github.com/repos/{$repo}/contents/{$path}", $payload);

            $response->throw();
        }, "commitFile({$repo}/{$path})");
    }

    public function createPullRequest(string $repo, string $head, string $base, string $title, string $body): array
    {
        return $this->withRetry(function () use ($repo, $head, $base, $title, $body) {
            $response = Http::withToken(config('services.github.token'))
                ->post("https://api.github.com/repos/{$repo}/pulls", [
                    'head' => $head,
                    'base' => $base,
                    'title' => $title,
                    'body' => $body,
                ]);

            $response->throw();

            return $response->json();
        }, "createPullRequest({$repo})");
    }

    /**
     * Execute a callback with retry logic and exponential backoff.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws \Throwable
     */
    protected function withRetry(callable $callback, string $operation): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::BASE_DELAY_SECONDS * (2 ** ($attempt - 1));
                    Log::warning("Retry {$attempt}/".self::MAX_RETRIES." for {$operation}: {$e->getMessage()} (waiting {$delay}s)");

                    $this->sleep($delay);
                } else {
                    Log::error('All '.self::MAX_RETRIES." retries exhausted for {$operation}: {$e->getMessage()}");
                }
            }
        }

        throw $lastException;
    }

    protected function sleep(int $seconds): void
    {
        if ($this->sleeper) {
            ($this->sleeper)($seconds);
        } else {
            sleep($seconds);
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
