<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

final class WorkflowResultTest extends TestCase
{
    public function testIsSuccessReturnsTrueWhenStatusIsCompleted(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-001',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:00Z'),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }

    public function testIsFailureReturnsTrueWhenStatusIsFailed(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-002',
            status: WorkflowStatus::Failed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:00Z'),
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueWhenStatusIsCancelled(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-003',
            status: WorkflowStatus::Cancelled,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:00Z'),
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
    }

    public function testIsSuccessAndIsFailureReturnFalseWhenNotTerminal(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-004',
            status: WorkflowStatus::Running,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }

    public function testDurationMsReturnsZeroWhenCompletedAtIsNull(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-005',
            status: WorkflowStatus::Running,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: null,
        );

        $this->assertSame(0, $result->durationMs());
    }

    public function testDurationMsCalculatesCorrectInterval(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-006',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:30.500Z'),
        );

        // 90.5 seconds = 90500ms
        $this->assertSame(90500, $result->durationMs());
    }

    public function testTotalDurationMsReturnsZeroWhenNoStages(): void
    {
        $result = new WorkflowResult(
            workflowId: 'wf-007',
            status: WorkflowStatus::Completed,
            stageResults: [],
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:02:00Z'),
        );

        $this->assertSame(0, $result->totalDurationMs());
    }

    public function testTotalDurationMsSumsAllStageDurations(): void
    {
        $stage1 = new StageResult(
            stageName: 'stage-1',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:00:30.000Z'),
        );

        $stage2 = new StageResult(
            stageName: 'stage-2',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:30.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:00.000Z'),
        );

        $stage3 = new StageResult(
            stageName: 'stage-3',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:01:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:15.000Z'),
        );

        $result = new WorkflowResult(
            workflowId: 'wf-008',
            status: WorkflowStatus::Completed,
            stageResults: [$stage1, $stage2, $stage3],
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:15Z'),
        );

        // stage1: 30000ms, stage2: 30000ms, stage3: 15000ms = 75000ms total
        $this->assertSame(75000, $result->totalDurationMs());
    }

    public function testTotalDurationMsIgnoresRunningStages(): void
    {
        $completedStage = new StageResult(
            stageName: 'completed-stage',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:00:30.000Z'),
        );

        $runningStage = new StageResult(
            stageName: 'running-stage',
            status: WorkflowStatus::Running,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:30.000Z'),
            completedAt: null,
        );

        $result = new WorkflowResult(
            workflowId: 'wf-009',
            status: WorkflowStatus::Running,
            stageResults: [$completedStage, $runningStage],
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: null,
        );

        // Only the completed stage contributes: 30000ms
        $this->assertSame(30000, $result->totalDurationMs());
    }

    public function testWorkflowResultWithAllProperties(): void
    {
        $stage = new StageResult(
            stageName: 'build',
            status: WorkflowStatus::Completed,
            output: 'Build successful',
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:00:10.000Z'),
        );

        $result = new WorkflowResult(
            workflowId: 'wf-010',
            status: WorkflowStatus::Completed,
            stageResults: [$stage],
            context: ['artifact' => 'bin/app', 'warnings' => 0],
            totalTokens: 15000,
            totalCost: 0.25,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:00:10.000Z'),
        );

        $this->assertSame('wf-010', $result->workflowId);
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame(['artifact' => 'bin/app', 'warnings' => 0], $result->context);
        $this->assertSame(15000, $result->totalTokens);
        $this->assertSame(0.25, $result->totalCost);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(10000, $result->durationMs());
        $this->assertSame(10000, $result->totalDurationMs());
    }
}
