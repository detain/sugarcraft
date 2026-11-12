<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * wf-impl P0-4: `/workflow run` lints the named plan before the engine sees
 * it. Errors refuse the run (the engine's run() is never called); warnings
 * ride the report under its heading. The plans live in the suite's sandboxed
 * HOME user tier — the tier `/workflow lint` by name also reads.
 */
final class WorkflowRunLintTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    public function testALintErrorRefusesTheRunBeforeTheEngineIsCalled(): void
    {
        $name = $this->writePlan(<<<'YAML'
            stages:
              - name: sole
                agent: coder
                prompt: reads {{ghost.results}}
            YAML);
        $engine = self::recordingEngine();

        $result = (new WorkflowCommand())->run(CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()), "/workflow run {$name}");

        self::assertSame([], $engine->runs, 'nothing was dispatched');
        self::assertSame([], $result->effects, 'no run fiber was built');
        $reply = $result->rows[1]->content;
        self::assertTrue($result->rows[1]->error);
        self::assertStringContainsString("workflow `{$name}` was not run: lint found 1 error(s), 0 warning(s) (nothing was dispatched).", $reply);
        self::assertStringContainsString('- error [sole] {{ghost.results}} names no producer', $reply);
    }

    public function testLintWarningsRideTheReportUnderItsHeading(): void
    {
        $name = $this->writePlan(<<<'YAML'
            stages:
              - name: sole
                agent: coder
                prompt: topic {{subject}}
            YAML);
        $engine = self::recordingEngine();

        $report = self::settle((new WorkflowCommand())->run(CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()), "/workflow run {$name}"));

        self::assertCount(1, $engine->runs, 'a warning never blocks the run');
        [$heading, $rest] = explode("\n\n", $report, 2);
        self::assertStringContainsString($name, $heading);
        self::assertStringStartsWith("1 lint warning:\n- warning [sole] {{subject}} matches no run-context seed", $rest);
    }

    public function testTheRunsOwnArgumentsSeedTheLint(): void
    {
        $name = $this->writePlan(<<<'YAML'
            stages:
              - name: sole
                agent: coder
                prompt: topic {{subject}}
            YAML);
        $engine = self::recordingEngine();

        $report = self::settle((new WorkflowCommand())->run(CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()), "/workflow run {$name} subject=php"));

        self::assertSame([[$name, ['subject' => 'php']]], $engine->runs);
        self::assertStringNotContainsString('lint warning', $report);
    }

    public function testANameNoTierLoadsIsLeftToTheEnginesOwnRefusal(): void
    {
        $engine = self::recordingEngine();

        self::settle((new WorkflowCommand())->run(CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()), '/workflow run wf-d1-no-such-plan-anywhere'));

        self::assertSame('wf-d1-no-such-plan-anywhere', $engine->runs[0][0] ?? null, 'the lint does not invent a cannot-load verdict');
    }

    public function testStartWithoutACommandContextKeepsThePreLintBehaviour(): void
    {
        $name = $this->writePlan(<<<'YAML'
            stages:
              - name: sole
                agent: coder
                prompt: reads {{ghost.results}}
            YAML);
        $engine = self::recordingEngine();

        self::settle(WorkflowCommand::start($engine, "/workflow run {$name}", $name));

        self::assertCount(1, $engine->runs, 'no context, no registry view: the run proceeds as before');
    }

    private function writePlan(string $stagesYaml): string
    {
        $dir = Bootstrap::trustedConfigDirPath() . '/workflows';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $name = 'wf-d1-' . bin2hex(random_bytes(4));
        $path = "{$dir}/{$name}.yaml";
        file_put_contents($path, "name: {$name}\n" . $stagesYaml . "\n");
        $this->files[] = $path;

        return $name;
    }

    private static function settle(CommandResult $result): string
    {
        self::assertCount(1, $result->effects, 'the run occupies the turn');
        $out = null;
        $guard = Loop::addTimer(10.0, static fn () => Loop::stop());
        ($result->effects[0]->run())()->then(static function (mixed $value) use (&$out, $guard): void {
            $out = $value;
            Loop::cancelTimer($guard);
            Loop::stop();
        });
        Loop::run();

        if ($out instanceof Message) {
            $out = $out->content;
        }
        self::assertIsString($out);

        return $out;
    }

    private static function recordingEngine(): WorkflowEngineInterface
    {
        return new class () implements WorkflowEngineInterface {
            /** @var list<array{0: string, 1: array<string, string>}> */
            public array $runs = [];

            public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
            {
                $this->runs[] = [$workflowPath, $context];

                return new WorkflowResult('wf-d1', WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
            }

            public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult('wf-d1', WorkflowStatus::Completed);
            }

            public function status(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Completed;
            }

            public function listWorkflows(): array
            {
                return [];
            }
        };
    }
}
