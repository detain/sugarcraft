<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C6 (wf-impl): the shipped fix-wave example (`from: {context: files}`) runs
 * from the run lines its header documents — `/workflow run … files:='[…]'`
 * and the headless `--param files:=…` — because `key:=<json>` carries the
 * list as a list. A plain `files=…` stays a string and the stage refuses it.
 */
final class FixWaveRunContextTest extends TestCase
{
    /** @var list<string> every wave agent's prompt */
    private array $wavePrompts = [];

    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    public function testTheTuiRunLineFansTheWaveOutOverTheList(): void
    {
        [$name, $context, $problems] = WorkflowCommand::parseRunArguments(
            "fix-wave files:='[\"src/A.php\",\"src/B.php\"]' spec=docs/brief.md",
        );
        self::assertSame([], $problems);

        $this->assertTheWaveRanOverBothFiles($this->runExample($name, $context));
    }

    public function testTheHeadlessParamLineFansTheWaveOutOverTheList(): void
    {
        // What ArgvParser hands the splitter for
        //   --param files:='["src/A.php","src/B.php"]' --param spec=docs/brief.md
        // once the shell has removed the single quotes: repeated flags joined by ','.
        [$context, $problems] = (new \ReflectionMethod(Subcommands::class, 'parseParamPairs'))
            ->invoke(null, 'files:=["src/A.php","src/B.php"],spec=docs/brief.md');
        self::assertSame([], $problems);

        $this->assertTheWaveRanOverBothFiles($this->runExample('fix-wave', $context));
    }

    public function testAPlainPairIsStillAStringAndTheWaveRefusesIt(): void
    {
        [, $context] = WorkflowCommand::parseRunArguments('fix-wave files=src/A.php,src/B.php spec=docs/brief.md');

        $result = $this->runExample('fix-wave', $context);

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString('which is a string, not an array', (string) $result->stageResults[0]->error);
        self::assertSame([], $this->wavePrompts);
    }

    /** @param array<string, mixed> $context */
    private function runExample(string $name, array $context): \SugarCraft\Code\Workflows\WorkflowResult
    {
        $this->wavePrompts = [];
        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('execute')->willReturnCallback(function (SubAgent $agent, CompleteRequest $request): AgentResult {
            if (str_contains($agent->task, 'apply its fix to exactly ONE file')) {
                $this->wavePrompts[] = $agent->task;
                preg_match('/file:\s+(\S+)\./', $agent->task, $m);
                $output = json_encode(['file' => $m[1] ?? '?', 'changed' => true, 'notes' => 'ok']);
            } else {
                $output = json_encode(['summary' => 's', 'totalChanged' => 2, 'failed' => [], 'risks' => []]);
            }

            return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: (string) $output, startedAt: new \DateTimeImmutable(), completedAt: new \DateTimeImmutable());
        });

        // A scratch copy: a run writes its record beside the file it loaded,
        // and the shipped examples directory must stay clean.
        $dir = sys_get_temp_dir() . '/wf-fix-wave-' . uniqid('', true);
        mkdir($dir);
        copy(dirname(__DIR__, 2) . '/examples/workflows/fix-wave.yaml', $dir . '/fix-wave.yaml');
        $this->scratch = $dir;

        return (new WorkflowEngine(new WorkflowRegistry($dir), new AgentWorkerPool(executor: $executor)))->run($name, $context);
    }

    private function assertTheWaveRanOverBothFiles(\SugarCraft\Code\Workflows\WorkflowResult $result): void
    {
        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertCount(2, $this->wavePrompts);
        self::assertStringContainsString('src/A.php', $this->wavePrompts[0] . $this->wavePrompts[1]);
        self::assertStringContainsString('src/B.php', $this->wavePrompts[0] . $this->wavePrompts[1]);
        self::assertStringContainsString('docs/brief.md', $this->wavePrompts[0]);
    }
}
