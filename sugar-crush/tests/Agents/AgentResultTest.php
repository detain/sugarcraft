<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;

/**
 * Tests for AgentResult - result of a parallel agent execution.
 */
final class AgentResultTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T10:01:30Z');

        $result = new AgentResult(
            agentId: 'agent-42',
            status: AgentStatus::Completed,
            output: 'Task completed successfully',
            error: null,
            tokensUsed: 1500,
            costUsd: 0.023,
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        $this->assertSame('agent-42', $result->agentId);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame('Task completed successfully', $result->output);
        $this->assertNull($result->error);
        $this->assertSame(1500, $result->tokensUsed);
        $this->assertSame(0.023, $result->costUsd);
        $this->assertSame($startedAt, $result->startedAt);
        $this->assertSame($completedAt, $result->completedAt);
    }

    public function testConstructionWithDefaults(): void
    {
        $result = new AgentResult(
            agentId: 'agent-99',
            status: AgentStatus::Pending,
        );

        $this->assertSame('agent-99', $result->agentId);
        $this->assertSame(AgentStatus::Pending, $result->status);
        $this->assertNull($result->output);
        $this->assertNull($result->error);
        $this->assertSame(0, $result->tokensUsed);
        $this->assertSame(0.0, $result->costUsd);
        $this->assertNull($result->startedAt);
        $this->assertNull($result->completedAt);
    }

    public function testConstructionWithError(): void
    {
        $error = new \RuntimeException('Process terminated');

        $result = new AgentResult(
            agentId: 'agent-7',
            status: AgentStatus::Failed,
            error: $error,
        );

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertSame($error, $result->error);
    }

    // -------------------------------------------------------------------------
    // isSuccess()
    // -------------------------------------------------------------------------

    public function testIsSuccessReturnsTrueWhenCompletedWithNoError(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            output: 'Done',
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseWhenStatusNotCompleted(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Failed,
        );

        $this->assertFalse($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseWhenErrorPresent(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            error: new \RuntimeException('Oops'),
        );

        $this->assertFalse($result->isSuccess());
    }

    public function testIsSuccessReturnsFalseForAllNonSuccessStatuses(): void
    {
        $failureStatuses = [
            AgentStatus::Pending,
            AgentStatus::Queued,
            AgentStatus::Running,
            AgentStatus::Streaming,
            AgentStatus::Failed,
            AgentStatus::Stopped,
            AgentStatus::TimedOut,
        ];

        foreach ($failureStatuses as $status) {
            $result = new AgentResult(agentId: 'a', status: $status);
            $this->assertFalse($result->isSuccess(), "isSuccess() should be false for status {$status->value}");
        }
    }

    // -------------------------------------------------------------------------
    // isFailure()
    // -------------------------------------------------------------------------

    public function testIsFailureReturnsTrueWhenErrorPresent(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            error: new \RuntimeException('Oops'),
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueForFailedStatus(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Failed,
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueForStoppedStatus(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Stopped,
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsTrueForTimedOutStatus(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::TimedOut,
        );

        $this->assertTrue($result->isFailure());
    }

    public function testIsFailureReturnsFalseWhenNoErrorAndSuccessStatus(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            output: 'All good',
        );

        $this->assertFalse($result->isFailure());
    }

    public function testIsFailureReturnsFalseForPendingQueuedRunningStreaming(): void
    {
        $nonFailureStatuses = [
            AgentStatus::Pending,
            AgentStatus::Queued,
            AgentStatus::Running,
            AgentStatus::Streaming,
        ];

        foreach ($nonFailureStatuses as $status) {
            $result = new AgentResult(agentId: 'a', status: $status);
            $this->assertFalse($result->isFailure(), "isFailure() should be false for status {$status->value}");
        }
    }

    // -------------------------------------------------------------------------
    // durationMs()
    // -------------------------------------------------------------------------

    public function testDurationMsReturnsCorrectInterval(): void
    {
        // 90 seconds = 90000 ms
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:30Z'),
        );

        $this->assertSame(90000, $result->durationMs());
    }

    public function testDurationMsReturnsZeroWhenStartedAtIsNull(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            startedAt: null,
            completedAt: new \DateTimeImmutable('2026-01-01T10:01:30Z'),
        );

        $this->assertSame(0, $result->durationMs());
    }

    public function testDurationMsReturnsZeroWhenCompletedAtIsNull(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Running,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
            completedAt: null,
        );

        $this->assertSame(0, $result->durationMs());
    }

    public function testDurationMsReturnsZeroWhenBothTimestampsAreNull(): void
    {
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Pending,
            startedAt: null,
            completedAt: null,
        );

        $this->assertSame(0, $result->durationMs());
    }

    public function testDurationMsHandlesSubSecondPrecision(): void
    {
        // 1500ms = 1.5 seconds
        $result = new AgentResult(
            agentId: 'a',
            status: AgentStatus::Completed,
            startedAt: new \DateTimeImmutable('2026-01-01T10:00:00.000Z'),
            completedAt: new \DateTimeImmutable('2026-01-01T10:00:01.500Z'),
        );

        $this->assertSame(1500, $result->durationMs());
    }
}
