<?php

use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

describe('GitHubService', function () {
    it('posts a comment to the github api', function () {
        Http::fake([
            'api.github.com/*' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->postComment('jordanpartridge/triage-agent', 1, 'Triage complete.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/jordanpartridge/triage-agent/issues/1/comments'
                && $request['body'] === 'Triage complete.';
        });
    });

    it('includes the auth token in the request', function () {
        Http::fake([
            'api.github.com/*' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'gh-secret-123');

        $service = new GitHubService;
        $service->postComment('owner/repo', 5, 'Hello');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer gh-secret-123');
        });
    });

    it('throws on failed comment post after retries', function () {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Validation Failed'], 422),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->setSleeper(fn (int $s) => null);
        $service->postComment('owner/repo', 1, 'Test');
    })->throws(\Illuminate\Http\Client\RequestException::class);

    it('fetches repo tree from github api', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/git/trees/*' => Http::response([
                'tree' => [
                    ['path' => 'app/Models/User.php', 'type' => 'blob'],
                    ['path' => 'app/Services/PaymentService.php', 'type' => 'blob'],
                    ['path' => 'composer.json', 'type' => 'blob'],
                    ['path' => 'vendor/autoload.php', 'type' => 'blob'],
                    ['path' => 'node_modules/lodash/index.js', 'type' => 'blob'],
                    ['path' => '.git/config', 'type' => 'blob'],
                    ['path' => 'storage/logs/laravel.log', 'type' => 'blob'],
                    ['path' => 'app', 'type' => 'tree'],
                ],
            ], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $tree = $service->getRepoTree('owner/repo', 'main');

        expect($tree)->toBe([
            'app/Models/User.php',
            'app/Services/PaymentService.php',
            'composer.json',
        ]);
    });

    it('fetches issue details', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/42' => Http::response([
                'number' => 42,
                'title' => 'Fix null pointer',
                'body' => 'Crashes on null input',
            ], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $issue = $service->getIssue('owner/repo', 42);

        expect($issue)
            ->toHaveKey('number', 42)
            ->toHaveKey('title', 'Fix null pointer');
    });

    it('fetches and decodes file content', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/contents/*' => Http::response([
                'content' => base64_encode('<?php echo "hello";'),
                'sha' => 'abc123',
            ], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $file = $service->getFileContent('owner/repo', 'app/Hello.php', 'main');

        expect($file['content'])->toBe('<?php echo "hello";')
            ->and($file['sha'])->toBe('abc123');
    });

    it('creates a branch from another branch', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'deadbeef'],
            ], 200),
            'api.github.com/repos/owner/repo/git/refs' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->createBranch('owner/repo', 'fix/issue-42', 'main');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/git/refs'
                && $request['ref'] === 'refs/heads/fix/issue-42'
                && $request['sha'] === 'deadbeef';
        });
    });

    it('commits a file to a branch', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/contents/*' => Http::response([], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->commitFile('owner/repo', 'app/Hello.php', '<?php echo "fixed";', 'Fix bug', 'fix/issue-42', 'abc123');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/contents/app/Hello.php'
                && $request['message'] === 'Fix bug'
                && $request['content'] === base64_encode('<?php echo "fixed";')
                && $request['branch'] === 'fix/issue-42'
                && $request['sha'] === 'abc123';
        });
    });

    it('commits a new file without sha', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/contents/*' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->commitFile('owner/repo', 'app/NewFile.php', '<?php', 'Add new file', 'fix/issue-42');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/contents/app/NewFile.php'
                && ! array_key_exists('sha', $request->data());
        });
    });

    it('creates a pull request', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls' => Http::response([
                'html_url' => 'https://github.com/owner/repo/pull/1',
                'number' => 1,
            ], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $pr = $service->createPullRequest('owner/repo', 'fix/issue-42', 'main', 'Fix #42', 'Fixes the bug');

        expect($pr)->toHaveKey('html_url', 'https://github.com/owner/repo/pull/1');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/pulls'
                && $request['head'] === 'fix/issue-42'
                && $request['base'] === 'main'
                && $request['title'] === 'Fix #42';
        });
    });

    it('returns empty array on api failure', function () {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->setSleeper(fn (int $s) => null);
        $tree = $service->getRepoTree('owner/nonexistent', 'main');

        expect($tree)->toBe([]);
    });

    it('fetches pull request details', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls/10' => Http::response([
                'number' => 10,
                'title' => 'Add feature',
                'body' => 'Adds a great feature',
            ], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $pr = $service->getPullRequest('owner/repo', 10);

        expect($pr)
            ->toHaveKey('number', 10)
            ->toHaveKey('title', 'Add feature');
    });

    it('fetches pull request files', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls/10/files' => Http::response([
                ['filename' => 'app/Test.php', 'status' => 'modified'],
            ], 200),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $files = $service->getPullRequestFiles('owner/repo', 10);

        expect($files)->toHaveCount(1)
            ->and($files[0]['filename'])->toBe('app/Test.php');
    });
});

describe('GitHubService retry logic', function () {
    it('retries on failure and succeeds on third attempt', function () {
        $sequence = Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['message' => 'Server Error'], 500)
            ->push([], 201);

        Http::fake([
            'api.github.com/*' => $sequence,
        ]);

        config()->set('services.github.token', 'test-token');

        $sleepCalls = [];
        $service = new GitHubService;
        $service->setSleeper(function (int $seconds) use (&$sleepCalls) {
            $sleepCalls[] = $seconds;
        });

        $service->postComment('owner/repo', 1, 'Hello');

        expect($sleepCalls)->toBe([1, 2]);

        Http::assertSentCount(3);
    });

    it('throws after all retries are exhausted', function () {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Server Error'], 500),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->setSleeper(fn (int $s) => null);

        $service->postComment('owner/repo', 1, 'Hello');
    })->throws(\Illuminate\Http\Client\RequestException::class);

    it('logs retry attempts', function () {
        Log::shouldReceive('warning')
            ->twice()
            ->withArgs(function ($message) {
                return str_contains($message, 'Retry') && str_contains($message, 'postComment');
            });

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'retries exhausted') && str_contains($message, 'postComment');
            });

        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Server Error'], 500),
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->setSleeper(fn (int $s) => null);

        try {
            $service->postComment('owner/repo', 1, 'Hello');
        } catch (\Throwable) {
            // expected
        }
    });

    it('uses exponential backoff delays', function () {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Error'], 500),
        ]);

        config()->set('services.github.token', 'test-token');

        $sleepCalls = [];
        $service = new GitHubService;
        $service->setSleeper(function (int $seconds) use (&$sleepCalls) {
            $sleepCalls[] = $seconds;
        });

        try {
            $service->postComment('owner/repo', 1, 'Test');
        } catch (\Throwable) {
            // expected
        }

        expect($sleepCalls)->toBe([1, 2]);
    });

    it('retries getIssue and succeeds on second attempt', function () {
        $sequence = Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['number' => 42, 'title' => 'Bug'], 200);

        Http::fake([
            'api.github.com/*' => $sequence,
        ]);

        config()->set('services.github.token', 'test-token');

        $service = new GitHubService;
        $service->setSleeper(fn (int $s) => null);

        $issue = $service->getIssue('owner/repo', 42);

        expect($issue)->toHaveKey('number', 42);
        Http::assertSentCount(2);
    });

    it('does not retry on first success', function () {
        Http::fake([
            'api.github.com/*' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $sleepCalls = [];
        $service = new GitHubService;
        $service->setSleeper(function (int $seconds) use (&$sleepCalls) {
            $sleepCalls[] = $seconds;
        });

        $service->postComment('owner/repo', 1, 'Hello');

        expect($sleepCalls)->toBe([]);
        Http::assertSentCount(1);
    });
});
