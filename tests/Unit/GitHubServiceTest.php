<?php

use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;

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
});
