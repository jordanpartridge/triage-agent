<?php

use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('posts a comment to the correct GitHub API endpoint with auth', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 201),
    ]);

    config()->set('services.github.token', 'test-token');

    $service = new GitHubService;
    $service->postComment('jordanpartridge/triage-agent', 1, 'Triage complete.');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/jordanpartridge/triage-agent/issues/1/comments'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['body'] === 'Triage complete.';
    });
});
