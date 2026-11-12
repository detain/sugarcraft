<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * D1 cross-track (wf-impl P0-4): the TUI's `/workflow run` lints the named
 * plan before the engine sees it, exactly as the host command does — the
 * Chat call site hands `WorkflowCommand::start()` its command context.
 */
final class WorkflowRunLintChatTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testALintErrorRefusesTheTuiRunWithNothingDispatched(): void
    {
        $name = $this->writePlan("stages:\n  - name: sole\n    agent: coder\n    prompt: reads {{ghost.results}}\n");
        $engine = self::recordingEngine();

        [$after, $cmd] = (new Chat(inputBuf: "/workflow run {$name}", workflowEngine: $engine, backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNull($cmd, 'no run fiber was scheduled');
        self::assertSame([], $engine->runs, 'nothing was dispatched');
        self::assertFalse($after->inFlight, 'the refused run does not hold the turn');
        $reply = $after->history[array_key_last($after->history)]->content;
        self::assertStringContainsString("workflow `{$name}` was not run: lint found 1 error(s), 0 warning(s) (nothing was dispatched).", $reply);
        self::assertStringContainsString('{{ghost.results}}', $reply);
    }

    public function testACleanPlanStillRunsThroughTheFiber(): void
    {
        $name = $this->writePlan("stages:\n  - name: sole\n    agent: coder\n    prompt: plain work\n");
        $engine = self::recordingEngine();

        [$after, $cmd] = (new Chat(inputBuf: "/workflow run {$name}", workflowEngine: $engine, backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));

        self::assertInstanceOf(\Closure::class, $cmd, 'the run is scheduled as before');
        self::assertTrue($after->inFlight);
    }

    private function writePlan(string $stagesYaml): string
    {
        $dir = Bootstrap::trustedConfigDirPath() . '/workflows';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $name = 'wf-b2-' . bin2hex(random_bytes(4));
        $path = "{$dir}/{$name}.yaml";
        file_put_contents($path, "name: {$name}\n" . $stagesYaml);
        $this->files[] = $path;

        return $name;
    }

    private static function recordingEngine(): WorkflowEngineInterface
    {
        return new class () implements WorkflowEngineInterface {
            /** @var list<string> */
            public array $runs = [];

            public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
            {
                $this->runs[] = $workflowPath;

                return new WorkflowResult('wf-b2', WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
            }

            public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult('wf-b2', WorkflowStatus::Completed);
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
