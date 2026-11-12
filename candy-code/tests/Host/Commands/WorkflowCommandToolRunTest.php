<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * wf-impl C5: `/workflow` against Workflow-tool runs and the C4 features —
 * status of a tool run's record, the announcements block of a report, and
 * `/workflow lint` checking `preset:` against the session engine's roster.
 */
final class WorkflowCommandToolRunTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wf-cmd-toolrun-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/workflows', 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testStatusAnswersForAToolRunsRecord(): void
    {
        $engine = $this->engine();
        $plan = "stages:\n  - name: a\n    prompt: go\n";
        $runId = $engine->runPlan((new WorkflowRegistry())->fromYamlString($plan), $plan)->workflowId;

        $reply = WorkflowCommand::status($this->engine(), '/workflow status ' . $runId, $runId)->rows[1]->content;

        self::assertStringContainsString($runId, $reply);
        self::assertStringContainsString('completed', $reply);
    }

    public function testTheReportListsTheRunsAnnouncements(): void
    {
        $quiet = new WorkflowResult('w-1', WorkflowStatus::Completed);
        self::assertStringNotContainsString('Announcements', WorkflowCommand::describeWorkflowResult('w', $quiet));

        $loud = new WorkflowResult('w-1', WorkflowStatus::Completed, announcements: ['Found 3 leaks', "Stage 'v': from.max kept 2 of 5 items; 3 dropped"]);
        self::assertStringContainsString(
            "Announcements:\n- Found 3 leaks\n- Stage 'v': from.max kept 2 of 5 items; 3 dropped",
            WorkflowCommand::describeWorkflowResult('w', $loud),
        );
    }

    public function testLintChecksPresetsAgainstTheSessionEnginesRoster(): void
    {
        $engine = $this->engine();
        $engine->setAgentRosterSource(static fn (): array => [new Agent('reviewer', 'reviews', 'You review.', 'm', 'p', [], [], [], true)]);
        $file = $this->dir . '/plan.yml';
        file_put_contents($file, "name: p\nstages:\n  - name: a\n    prompt: go\n    preset: ghost\n");

        $result = (new WorkflowCommand())->run(
            CommandContext::new(workflowEngine: $engine, root: $this->dir),
            '/workflow lint ' . $file,
        );

        $reply = $result->rows[1]->content;
        self::assertTrue($result->rows[1]->error);
        self::assertStringContainsString('preset "ghost" is not an agent preset this session knows (known: reviewer)', $reply);
    }

    private function engine(): WorkflowEngine
    {
        $executor = new class () implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'ok', completedAt: new \DateTimeImmutable());
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };

        return new WorkflowEngine(new WorkflowRegistry($this->dir . '/workflows'), new AgentWorkerPool(executor: $executor), environmentRoot: $this->dir);
    }
}
