<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Tests for StageResult — result of a single workflow stage.
 */
final class StageResultTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $startedAt = new \DateTimeImmutable('2026-02-01T08:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-02-01T08:02:15Z');
        $agentA = new AgentResult(agentId: 'agent-a', status: AgentStatus::Completed, output: 'done');
        $agentB = new AgentResult(agentId: 'agent-b', status: AgentStatus::Completed, output: 'done');

        $result = new StageResult(
            stageName: 'fetch-data',
            status: WorkflowStatus::Completed,
            output: 'All data fetched',
            error: null,
            agents: [$agentA, $agentB],
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        $this->assertSame('fetch-data', $result->stageName);
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertSame('All data fetched', $result->output);
        $this->assertNull($result->error);
        $this->assertCount(2, $result->agents);
        $this->assertSame($startedAt, $result->startedAt);
        $this->assertSame($completedAt, $result->completedAt);
    }

    public function testConstructionWithDefaults(): void
    {
        $result = new StageResult(
            stageName: 'empty-stage',
            status: WorkflowStatus::Pending,
        );

        $this->assertSame('empty-stage', $result->stageName);
        $this->assertSame(WorkflowStatus::Pending, $result->status);
        $this->assertNull($result->output);
        $this->assertNull($result->error);
        $this->assertSame([], $result->agents);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->startedAt);
        $this->assertNull($result->completedAt);
    }

    public function testConstructionWithError(): void
    {
        $result = new StageResult(
            stageName: 'fail-stage',
            status: WorkflowStatus::Failed,
            error: 'Step timed out',
        );

        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertSame('Step timed out', $result->error);
    }

    // -------------------------------------------------------------------------
    // isSuccess()
    // -------------------------------------------------------------------------

    public function testIsSuccessReturnsTrueWhenCompletedWithNoError(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            output: 'Done',
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseWhenStatusNotCompleted(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Failed,
        );

        $this->assertFalse($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseWhenErrorPresent(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            error: 'Something went wrong',
        );

        $this->assertFalse($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseForNonSuccessStatuses(): void
    {
        $nonSuccessStatuses = [
            WorkflowStatus::Draft,
            WorkflowStatus::Pending,
            WorkflowStatus::Running,
            WorkflowStatus::Paused,
            WorkflowStatus::Resuming,
            WorkflowStatus::Failed,
            WorkflowStatus::Cancelled,
        ];

        foreach ($nonSuccessStatuses as $status) {
            $r = new StageResult(stageName: 's', status: $status);
            $this->assertFalse($r->isSuccess(), "isSuccess() should be false for status {$status->value}");
        }
    }

    // -------------------------------------------------------------------------
    // isFailure()
    // -------------------------------------------------------------------------

    public function testIsFailureReturnsTrueWhenErrorPresent(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            error: 'Oops',
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueForFailedStatus(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Failed,
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueForCancelledStatus(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Cancelled,
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsFalseWhenNoErrorAndNotFailed(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            output: 'All good',
        );

        $this->assertFalse($result->isFailure());
    }

    public function testIsFailureReturnsFalseForActiveStatuses(): void
    {
        $activeStatuses = [
            WorkflowStatus::Draft,
            WorkflowStatus::Pending,
            WorkflowStatus::Running,
            WorkflowStatus::Paused,
            WorkflowStatus::Resuming,
        ];

        foreach ($activeStatuses as $status) {
            $r = new StageResult(stageName: 's', status: $status);
            $this->assertFalse($r->isFailure(), "isFailure() should be false for status {$status->value}");
        }
    }

    // -------------------------------------------------------------------------
    // durationMs()
    // -------------------------------------------------------------------------

    public function testDurationMsReturnsCorrectInterval(): void
    {
        // 135 seconds = 135000 ms
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-02-01T08:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-02-01T08:02:15Z'),
        );

        $this->assertSame(135000, $result->durationMs());
    }

    public function testDurationMsReturnsZeroWhenCompletedAtIsNull(): void
    {
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Running,
            startedAt: new \DateTimeImmutable('2026-02-01T08:00:00Z'),
            completedAt: null,
        );

        $this->assertSame(0, $result->durationMs());
    }

    public function testDurationMsHandlesSubSecondPrecision(): void
    {
        // 2500ms = 2.5 seconds
        $result = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-02-01T08:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-02-01T08:00:02.500Z'),
        );

        $this->assertSame(2500, $result->durationMs());
    }
}
