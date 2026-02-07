<?php

use App\Commands\TriageListenCommand;
use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

function makeEvent(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'eventType' => 'issues',
        'payload' => [
            'action' => 'opened',
            'repository' => ['full_name' => 'jordanpartridge/triage-agent'],
            'issue' => [
                'number' => 42,
                'title' => 'Add dark mode',
                'body' => 'We need a dark mode toggle in the settings page.',
            ],
        ],
    ], $overrides));
}

function fakePrism(string $text = '## Plan\nImplement dark mode.'): void
{
    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: $text,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 20),
            meta: new Meta('ollama', 'deepseek-coder:6.7b'),
            messages: collect([]),
            additionalContent: [],
        ),
    ]);
}

describe('TriageListenCommand', function () {
    it('processes a github issue opened event', function () {
        fakePrism('## Plan\nImplement dark mode.');

        Http::fake([
            'api.github.com/*' => Http::response([], 201),
        ]);

        $command = app(TriageListenCommand::class);
        $event = json_decode(makeEvent(), true);

        $generatePlan = new ReflectionMethod($command, 'generatePlan');
        $plan = $generatePlan->invoke($command, $event['payload']['issue']);

        expect($plan)->toBe('## Plan\nImplement dark mode.');

        $github = app(GitHubService::class);
        $github->postComment('jordanpartridge/triage-agent', 42, "## 🤖 Triage Agent\n\n{$plan}");

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/issues/42/comments')
                && str_contains($request['body'], 'Triage Agent');
        });
    });

    it('skips non-issues event types', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeEvent(['eventType' => 'pull_request']), true);

        expect($method->invoke($command, $event))->toBeFalse();
    });

    it('skips non-opened actions', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeEvent(['payload' => ['action' => 'closed']]), true);

        expect($method->invoke($command, $event))->toBeFalse();
    });

    it('handles issues with empty body', function () {
        fakePrism('## Plan\nNeed more details.');

        $command = app(TriageListenCommand::class);
        $event = json_decode(makeEvent(['payload' => ['issue' => ['body' => '']]]), true);

        $generatePlan = new ReflectionMethod($command, 'generatePlan');
        $plan = $generatePlan->invoke($command, $event['payload']['issue']);

        expect($plan)->toBe('## Plan\nNeed more details.');
    });

    it('extracts repo and issue number from payload', function () {
        $event = json_decode(makeEvent([
            'payload' => [
                'repository' => ['full_name' => 'owner/repo'],
                'issue' => ['number' => 99],
            ],
        ]), true);

        expect($event['payload']['repository']['full_name'])->toBe('owner/repo')
            ->and($event['payload']['issue']['number'])->toBe(99);
    });
});
