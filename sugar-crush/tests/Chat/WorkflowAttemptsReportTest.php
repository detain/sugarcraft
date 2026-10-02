<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Since WF-1(b) the pool re-runs a failed or timed-out agent up to its
 * `retries`, folding every attempt into ONE {@see AgentResult} whose
 * `$attempts` says how many runs it took (and whose spend covers them all).
 * Chat's workflow report now names the stages that needed more than one, so
 * a stage that passed on its third try no longer reads like a first-time pass
 * (integ-w8b follow-up for w9-chat-cmds).
 */
final class WorkflowAttemptsReportTest extends TestCase
{
    private static function describe(WorkflowResult $result): string
    {
        return (new \ReflectionMethod(Chat::class, 'describeWorkflowResult'))->invoke(null, 'deploy', $result);
    }

    private static function stage(string $name, int ...$attempts): StageResult
    {
        return new StageResult(
            stageName: $name,
            status: WorkflowStatus::Completed,
            agents: array_map(
                static fn(int $n): AgentResult => new AgentResult(agentId: $name . '-' . $n, status: AgentStatus::Completed, output: 'ok', attempts: $n),
                $attempts,
            ),
        );
    }

    public function testAStageThatNeededRetriesIsNamedWithItsAttemptCount(): void
    {
        $report = self::describe(new WorkflowResult('deploy-1', WorkflowStatus::Completed, [
            self::stage('build', 1),
            self::stage('test', 1, 3, 2),
        ]));

        $this->assertStringContainsString("Stage 'test': 3 attempts", $report, 'the most attempts any of its agents took');
        $this->assertStringNotContainsString("Stage 'build'", $report, 'a first-time pass adds no line');
    }

    public function testARunWithNoRetriesReportsExactlyAsBefore(): void
    {
        $report = self::describe(new WorkflowResult('deploy-1', WorkflowStatus::Completed, [self::stage('build', 1), self::stage('empty')]));

        $this->assertStringNotContainsString('attempts', $report);
    }
}
