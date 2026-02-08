<?php

namespace App\Commands;

use App\Services\GitHubService;
use LaravelZero\Framework\Commands\Command;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class FixAttemptCommand extends Command
{
    protected $signature = 'fix:attempt {repo} {issue}';

    protected $description = 'Attempt to fix a GitHub issue by generating a code change and opening a PR';

    public function handle(GitHubService $github): int
    {
        $repo = $this->argument('repo');
        $issueNumber = (int) $this->argument('issue');

        try {
            $this->info("Fetching issue #{$issueNumber} from {$repo}...");

            $issue = $github->getIssue($repo, $issueNumber);
            $tree = $github->getRepoTree($repo);

            $this->info('Selecting relevant files...');

            $selectedPaths = $this->selectFiles($issue, $tree);
            $this->info('Selected files: '.implode(', ', $selectedPaths));

            $fileContents = [];
            foreach ($selectedPaths as $path) {
                $file = $github->getFileContent($repo, $path);
                $fileContents[$path] = $file;
            }

            $this->info('Generating fix...');

            $fix = $this->generateFix($issue, $tree, $fileContents, $repo);

            $this->info("Creating branch {$fix['branch_name']}...");

            $github->createBranch($repo, $fix['branch_name'], 'main');

            foreach ($fix['changes'] as $change) {
                $sha = $fileContents[$change['path']]['sha'] ?? null;
                $github->commitFile(
                    $repo,
                    $change['path'],
                    $change['content'],
                    $change['commit_message'],
                    $fix['branch_name'],
                    $sha,
                );
            }

            $pr = $github->createPullRequest(
                $repo,
                $fix['branch_name'],
                'main',
                "Fix #{$issueNumber}: {$issue['title']}",
                "## Summary\n\n{$fix['summary']}\n\nCloses #{$issueNumber}",
            );

            $github->postComment(
                $repo,
                $issueNumber,
                "I've opened a PR with a proposed fix: {$pr['html_url']}",
            );

            $this->info("PR created: {$pr['html_url']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Fix attempt failed: {$e->getMessage()}");

            try {
                $github->postComment(
                    $repo,
                    $issueNumber,
                    "Fix attempt failed: {$e->getMessage()}",
                );
            } catch (\Throwable) {
                // Silence comment failure
            }

            return self::FAILURE;
        }
    }

    protected function selectFiles(array $issue, array $tree): array
    {
        $fileList = implode("\n", $tree);

        $response = Prism::text()
            ->using(Provider::OpenRouter, config('services.fix.model'))
            ->withSystemPrompt(<<<'PROMPT'
                You are a senior software engineer. Given a GitHub issue and a repository file tree,
                select the files most likely relevant to fixing the issue. Return ONLY a JSON array of
                file paths (max 5). No explanation, no markdown, just the JSON array.
                PROMPT)
            ->withPrompt("Issue: {$issue['title']}\n\n{$issue['body']}\n\nFiles:\n{$fileList}")
            ->withClientOptions(['timeout' => 120])
            ->asText();

        return json_decode($response->text, true);
    }

    protected function generateFix(array $issue, array $tree, array $fileContents, string $repo): array
    {
        $systemPrompt = $this->buildSystemPrompt($tree, $fileContents, $repo);

        $schema = new ObjectSchema(
            name: 'fix',
            description: 'A code fix for the GitHub issue',
            properties: [
                new StringSchema('summary', 'What the fix does'),
                new StringSchema('branch_name', 'Branch name, e.g. fix/issue-42-null-check'),
                new ArraySchema(
                    name: 'changes',
                    description: 'File changes to apply',
                    items: new ObjectSchema(
                        name: 'change',
                        description: 'A single file change',
                        properties: [
                            new StringSchema('path', 'File path'),
                            new StringSchema('content', 'Complete updated file content'),
                            new StringSchema('commit_message', 'Commit message for this change'),
                        ],
                        requiredFields: ['path', 'content', 'commit_message'],
                    ),
                ),
            ],
            requiredFields: ['summary', 'branch_name', 'changes'],
        );

        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Prism::structured()
                    ->using(Provider::OpenRouter, config('services.fix.model'))
                    ->withSystemPrompt($systemPrompt)
                    ->withPrompt("Fix this issue:\n\nTitle: {$issue['title']}\n\n{$issue['body']}")
                    ->withSchema($schema)
                    ->withClientOptions(['timeout' => 120])
                    ->asStructured();

                $fix = $response->structured;

                if (empty($fix['changes'])) {
                    throw new \RuntimeException('Generated fix contains no changes');
                }

                return $fix;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->warn("Attempt {$attempt} failed: {$e->getMessage()}");
            }
        }

        throw $lastException;
    }

    protected function buildSystemPrompt(array $tree, array $fileContents, string $repo): string
    {
        $fileList = implode("\n", array_map(fn (string $path) => "- {$path}", $tree));

        $contents = '';
        foreach ($fileContents as $path => $file) {
            $contents .= "\n--- {$path} ---\n{$file['content']}\n";
        }

        return <<<PROMPT
            You are a senior software engineer generating a code fix for a GitHub issue.
            Repository: {$repo}

            Project structure:
            {$fileList}

            File contents:
            {$contents}

            Generate a fix that:
            - Modifies only the files necessary
            - Returns complete file contents (not diffs)
            - Uses a descriptive branch name like fix/issue-42-short-description
            - Includes clear commit messages
            PROMPT;
    }
}
