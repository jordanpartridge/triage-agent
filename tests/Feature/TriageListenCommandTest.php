<?php

use App\Commands\TriageListenCommand;
use App\Services\GitHubService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

function makePrMergedEvent(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'eventType' => 'pull_request',
        'payload' => [
            'action' => 'closed',
            'repository' => ['full_name' => 'jordanpartridge/triage-agent'],
            'pull_request' => [
                'number' => 15,
                'title' => 'Add authentication feature',
                'body' => 'Implements OAuth2 login flow.',
                'merged' => true,
                'merged_at' => '2025-01-15T10:30:00Z',
                'html_url' => 'https://github.com/jordanpartridge/triage-agent/pull/15',
                'head' => ['ref' => 'feat/auth'],
                'user' => ['login' => 'jordanpartridge'],
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

    it('resolves issues.opened to a handler', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeEvent(), true);

        expect($method->invoke($command, $event))->toBeInstanceOf(Closure::class);
    });

    it('resolves pull_request.closed to a handler', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makePrMergedEvent(), true);

        expect($method->invoke($command, $event))->toBeInstanceOf(Closure::class);
    });

    it('returns null for unrecognized event types', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveHandler');

        $event = json_decode(makeEvent(['eventType' => 'deployment']), true);

        expect($method->invoke($command, $event))->toBeNull();
    });

    it('skips non-issues event types via shouldProcess', function () {
        $command = app(TriageListenCommand::class);
        $method = new ReflectionMethod($command, 'shouldProcess');

        $event = json_decode(makeEvent(['eventType' => 'deployment']), true);

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

describe('handlePullRequestClosed', function () {
    beforeEach(function () {
        $this->cmd = app(TriageListenCommand::class);
        $this->cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $this->handler = new ReflectionMethod($this->cmd, 'handlePullRequestClosed');
    });

    it('calls know CLI when a PR is merged', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent(), true);
        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return $cmd[0] === 'know'
                && $cmd[1] === 'add'
                && str_contains($cmd[2], 'PR #15')
                && str_contains($cmd[2], 'Add authentication feature')
                && in_array('--category', $cmd)
                && $cmd[array_search('--category', $cmd) + 1] === 'architecture'
                && in_array('--author', $cmd)
                && $cmd[array_search('--author', $cmd) + 1] === 'jordanpartridge'
                && in_array('--branch', $cmd)
                && $cmd[array_search('--branch', $cmd) + 1] === 'feat/auth'
                && in_array('--no-git', $cmd);
        });
    });

    it('includes PR body in knowledge content', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent(), true);
        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            $cmd = $process->command;
            $contentIndex = array_search('--content', $cmd);

            return $contentIndex !== false
                && str_contains($cmd[$contentIndex + 1], 'OAuth2 login flow');
        });
    });

    it('skips PRs that are closed without merging', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent([
            'payload' => ['pull_request' => ['merged' => false]],
        ]), true);

        $this->handler->invoke($this->cmd, $event);

        Process::assertDidntRun('know *');
    });

    it('skips events with missing pull_request payload', function () {
        Process::fake();

        $event = [
            'eventType' => 'pull_request',
            'payload' => [
                'action' => 'closed',
                'repository' => ['full_name' => 'owner/repo'],
            ],
        ];

        $this->handler->invoke($this->cmd, $event);

        Process::assertDidntRun('know *');
    });

    it('handles missing optional fields gracefully', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent(), true);
        $event['payload']['pull_request']['body'] = null;
        $event['payload']['pull_request']['html_url'] = null;
        $event['payload']['pull_request']['merged_at'] = null;
        unset($event['payload']['pull_request']['head']['ref']);
        unset($event['payload']['pull_request']['user']['login']);

        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return $cmd[0] === 'know'
                && $cmd[1] === 'add'
                && $cmd[array_search('--author', $cmd) + 1] === 'unknown'
                && $cmd[array_search('--branch', $cmd) + 1] === 'unknown';
        });
    });

    it('passes source URL from PR html_url', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent(), true);
        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            $cmd = $process->command;
            $sourceIndex = array_search('--source', $cmd);

            return $sourceIndex !== false
                && $cmd[$sourceIndex + 1] === 'https://github.com/jordanpartridge/triage-agent/pull/15';
        });
    });

    it('passes repo and tags to know CLI', function () {
        Process::fake();

        $event = json_decode(makePrMergedEvent(), true);
        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            $cmd = $process->command;
            $repoIndex = array_search('--repo', $cmd);
            $tagsIndex = array_search('--tags', $cmd);

            return $cmd[$repoIndex + 1] === 'jordanpartridge/triage-agent'
                && $cmd[$tagsIndex + 1] === 'pr-merge,jordanpartridge/triage-agent';
        });
    });

    it('logs error when know CLI fails', function () {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'Database connection failed',
                exitCode: 1,
            ),
        ]);

        $event = json_decode(makePrMergedEvent(), true);
        $this->handler->invoke($this->cmd, $event);

        Process::assertRan(function ($process) {
            return $process->command[0] === 'know';
        });
    });
});
