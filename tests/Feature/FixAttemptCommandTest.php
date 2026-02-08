<?php

use App\Commands\FixAttemptCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

describe('FixAttemptCommand', function () {
    it('selects relevant files from repo tree', function () {
        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: '["app/Models/User.php", "app/Services/AuthService.php"]',
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(10, 20),
                meta: new Meta('openrouter', 'test-model'),
                messages: collect([]),
                additionalContent: [],
            ),
        ]);

        $command = app(FixAttemptCommand::class);

        $method = new ReflectionMethod($command, 'selectFiles');
        $result = $method->invoke($command, [
            'title' => 'Auth bug',
            'body' => 'Login fails for new users',
        ], [
            'app/Models/User.php',
            'app/Services/AuthService.php',
            'composer.json',
        ]);

        expect($result)->toBe(['app/Models/User.php', 'app/Services/AuthService.php']);
    });

    it('generates a structured fix', function () {
        $fixData = [
            'summary' => 'Add null check to prevent crash',
            'branch_name' => 'fix/issue-42-null-check',
            'changes' => [
                [
                    'path' => 'app/Services/AuthService.php',
                    'content' => '<?php return "fixed";',
                    'commit_message' => 'Fix null pointer in AuthService',
                ],
            ],
        ];

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($fixData),
        ]);

        $command = app(FixAttemptCommand::class);

        $method = new ReflectionMethod($command, 'generateFix');
        $result = $method->invoke($command, [
            'title' => 'Null pointer crash',
            'body' => 'Crashes when user is null',
        ], ['app/Services/AuthService.php'], [
            'app/Services/AuthService.php' => [
                'content' => '<?php return null;',
                'sha' => 'abc123',
            ],
        ], 'owner/repo');

        expect($result)
            ->toHaveKey('summary', 'Add null check to prevent crash')
            ->toHaveKey('branch_name', 'fix/issue-42-null-check')
            ->and($result['changes'])->toHaveCount(1);
    });

    it('validates fix output', function () {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'summary' => 'Empty fix',
                'branch_name' => 'fix/empty',
                'changes' => [],
            ]),
            StructuredResponseFake::make()->withStructured([
                'summary' => 'Empty fix',
                'branch_name' => 'fix/empty',
                'changes' => [],
            ]),
            StructuredResponseFake::make()->withStructured([
                'summary' => 'Empty fix',
                'branch_name' => 'fix/empty',
                'changes' => [],
            ]),
        ]);

        $command = app(FixAttemptCommand::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $method = new ReflectionMethod($command, 'generateFix');
        $method->invoke($command, [
            'title' => 'Test',
            'body' => 'Test',
        ], [], [], 'owner/repo');
    })->throws(\RuntimeException::class, 'Generated fix contains no changes');

    it('posts failure comment when fix fails', function () {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/42' => Http::response([], 500),
            'api.github.com/repos/owner/repo/issues/42/comments' => Http::response([], 201),
        ]);

        config()->set('services.github.token', 'test-token');

        $this->artisan('fix:attempt', ['repo' => 'owner/repo', 'issue' => '42'])
            ->assertExitCode(1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/issues/42/comments')
                && str_contains($request['body'], 'Fix attempt failed');
        });
    });
});
