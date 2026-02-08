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

function makeLabeledEvent(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'eventType' => 'issues',
        'payload' => [
            'action' => 'labeled',
            'repository' => ['full_name' => 'jordanpartridge/triage-agent'],
            'issue' => [
                'number' => 42,
                'title' => 'Add dark mode',
                'body' => 'We need a dark mode toggle in the settings page.',
            ],
            'label' => [
                'name' => 'enhancement',
            ],
        ],
    ], $overrides));
}

function makePrEvent(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'eventType' => 'pull_request',
        'payload' => [
            'action' => 'opened',
            'repository' => ['full_name' => 'jordanpartridge/triage-agent'],
            'pull_request' => [
                'number' => 10,
                'title' => 'Add dark mode feature',
                'body' => 'Implements dark mode toggle.',
                'changed_files' => 3,
            ],
        ],
    ], $overrides));
}

function fakePrism(string $text = "## Plan\nImplement dark mode."): void
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
        fakePrism("## Plan\nImplement dark mode.");

        Http::fake([
            'api.github.com/*' => Http::response([], 201),
        ]);

        $command = app(TriageListenCommand::class);
        $event = json_decode(makeEvent(), true);

        $generatePlan = new ReflectionMethod($command, 'generatePlan');
        $plan = $generatePlan->invoke($command, $event['payload']['issue'], [], 'jordanpartridge/triage-agent');

        expect($plan)->toBe("## Plan\nImplement dark mode.");

        $github = app(GitHubService::class);
        $github->postComment('jordanpartridge/triage-agent', 42, "## Triage Agent\n\n{$plan}");

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/issues/42/comments')
                && str_contains($request['body'], 'Triage Agent');
        });
    });

    it('skips non-issues event types', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeEvent(['eventType' => 'push']), true);

        expect($method->invoke($command, $event))->toBeFalse();
    });

    it('skips non-opened actions for unknown event combos', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeEvent(['payload' => ['action' => 'closed']]), true);

        expect($method->invoke($command, $event))->toBeFalse();
    });

    it('handles issues with empty body', function () {
        fakePrism("## Plan\nNeed more details.");

        $command = app(TriageListenCommand::class);
        $event = json_decode(makeEvent(['payload' => ['issue' => ['body' => '']]]), true);

        $generatePlan = new ReflectionMethod($command, 'generatePlan');
        $plan = $generatePlan->invoke($command, $event['payload']['issue'], [], '');

        expect($plan)->toBe("## Plan\nNeed more details.");
    });

    it('includes repo context in the system prompt', function () {
        $command = app(TriageListenCommand::class);

        $tree = ['app/Models/User.php', 'app/Services/PaymentService.php', 'composer.json'];

        $buildRepoContext = new ReflectionMethod($command, 'buildRepoContext');
        $context = $buildRepoContext->invoke($command, $tree, 'jordanpartridge/triage-agent');

        expect($context)
            ->toContain('app/Models/User.php')
            ->toContain('app/Services/PaymentService.php')
            ->toContain('composer.json')
            ->toContain('jordanpartridge/triage-agent');
    });

    it('returns empty string for empty tree', function () {
        $command = app(TriageListenCommand::class);

        $buildRepoContext = new ReflectionMethod($command, 'buildRepoContext');
        $result = $buildRepoContext->invoke($command, [], '');

        expect($result)->toBe('');
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

    it('returns null for shouldProcess with null event', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        expect($method->invoke($command, null))->toBeFalse();
    });
});

describe('TriageListenCommand event routing', function () {
    it('resolves issues.opened handler', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeEvent(), true);

        expect($method->invoke($command, $event))->toBeInstanceOf(\Closure::class);
    });

    it('resolves issues.labeled handler', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeLabeledEvent(), true);

        expect($method->invoke($command, $event))->toBeInstanceOf(\Closure::class);
    });

    it('resolves pull_request.opened handler', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makePrEvent(), true);

        expect($method->invoke($command, $event))->toBeInstanceOf(\Closure::class);
    });

    it('returns null for unhandled event types', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeEvent(['eventType' => 'deployment']), true);

        expect($method->invoke($command, $event))->toBeNull();
    });

    it('returns null for unhandled actions', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeEvent(['payload' => ['action' => 'deleted']]), true);

        expect($method->invoke($command, $event))->toBeNull();
    });

    it('accepts issues.labeled as a valid event', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeLabeledEvent(), true);

        expect($method->invoke($command, $event))->toBeTrue();
    });

    it('accepts pull_request.opened as a valid event', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makePrEvent(), true);

        expect($method->invoke($command, $event))->toBeTrue();
    });
});

describe('TriageListenCommand issue labeled handler', function () {
    it('generates a re-triage plan with label context', function () {
        fakePrism("## Updated Plan\nPrioritize as enhancement.");

        $command = app(TriageListenCommand::class);

        $issue = [
            'number' => 42,
            'title' => 'Add dark mode',
            'body' => 'We need dark mode.',
        ];

        $generatePlan = new ReflectionMethod($command, 'generatePlan');
        $plan = $generatePlan->invoke($command, $issue, [], 'owner/repo', 'enhancement');

        expect($plan)->toBe("## Updated Plan\nPrioritize as enhancement.");
    });
});

describe('TriageListenCommand PR handler', function () {
    it('generates a PR summary', function () {
        fakePrism("## Summary\nThis PR adds dark mode.");

        $command = app(TriageListenCommand::class);

        $pr = [
            'number' => 10,
            'title' => 'Add dark mode feature',
            'body' => 'Implements dark mode toggle.',
            'changed_files' => 3,
        ];

        $generatePrSummary = new ReflectionMethod($command, 'generatePrSummary');
        $summary = $generatePrSummary->invoke($command, $pr, 'owner/repo');

        expect($summary)->toBe("## Summary\nThis PR adds dark mode.");
    });

    it('handles PR with no body', function () {
        fakePrism("## Summary\nNo description provided.");

        $command = app(TriageListenCommand::class);

        $pr = [
            'number' => 10,
            'title' => 'Quick fix',
            'changed_files' => 1,
        ];

        $generatePrSummary = new ReflectionMethod($command, 'generatePrSummary');
        $summary = $generatePrSummary->invoke($command, $pr, 'owner/repo');

        expect($summary)->toBe("## Summary\nNo description provided.");
    });
});

describe('TriageListenCommand processMessage', function () {
    it('handles invalid json gracefully', function () {
        $command = app(TriageListenCommand::class);
        $github = app(GitHubService::class);

        $processMessage = new ReflectionMethod($command, 'processMessage');
        $processMessage->invoke($command, 'not-valid-json', $github);

        // Should not throw, should silently skip
        expect(true)->toBeTrue();
    });

    it('skips events with no matching handler', function () {
        $command = app(TriageListenCommand::class);
        $github = app(GitHubService::class);

        Http::fake();

        $processMessage = new ReflectionMethod($command, 'processMessage');
        $processMessage->invoke($command, json_encode(['eventType' => 'deployment', 'payload' => ['action' => 'created']]), $github);

        Http::assertNothingSent();
    });

    it('catches and logs errors during event processing', function () {
        fakePrism("## Plan\nDo something.");

        Http::fake([
            'api.github.com/repos/*/git/trees/*' => Http::response(['tree' => []], 200),
            'api.github.com/repos/*/issues/*/comments' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        config()->set('services.github.token', 'test-token');

        $command = app(TriageListenCommand::class);
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput;
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $outputStyle = new \Illuminate\Console\OutputStyle($input, $bufferedOutput);
        $command->setOutput($outputStyle);

        $github = new GitHubService;
        $github->setSleeper(fn (int $s) => null);

        $processMessage = new ReflectionMethod($command, 'processMessage');
        $processMessage->invoke($command, makeEvent(), $github);

        // Should not throw - error is caught internally
        $outputText = $bufferedOutput->fetch();
        expect($outputText)->toContain('Error processing event');
    });
});

describe('TriageListenCommand reconnection', function () {
    it('has sleep method for reconnection delays', function () {
        $sleepCalls = [];

        $command = app(TriageListenCommand::class);
        $command->setSleeper(function (int $seconds) use (&$sleepCalls) {
            $sleepCalls[] = $seconds;
        });

        $sleep = new ReflectionMethod($command, 'sleep');
        $sleep->invoke($command, 5);

        expect($sleepCalls)->toBe([5]);
    });

    it('supports max messages for testing', function () {
        $command = app(TriageListenCommand::class);
        $command->setMaxMessages(1);

        $maxMessages = new ReflectionProperty($command, 'maxMessages');

        expect($maxMessages->getValue($command))->toBe(1);
    });
});
