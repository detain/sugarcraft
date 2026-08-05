<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentStatus;

/**
 * Tests for AgentStatus enum - lifecycle states for agent worker pool execution.
 */
final class AgentStatusTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('pending', AgentStatus::Pending->value);
        $this->assertSame('queued', AgentStatus::Queued->value);
        $this->assertSame('running', AgentStatus::Running->value);
        $this->assertSame('streaming', AgentStatus::Streaming->value);
        $this->assertSame('completed', AgentStatus::Completed->value);
        $this->assertSame('failed', AgentStatus::Failed->value);
        $this->assertSame('stopped', AgentStatus::Stopped->value);
        $this->assertSame('timed_out', AgentStatus::TimedOut->value);
    }

    public function testCaseCount(): void
    {
        $cases = AgentStatus::cases();
        $this->assertCount(8, $cases);
    }

    // -------------------------------------------------------------------------
    // AgentStatus::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromPending(): void
    {
        $this->assertSame(AgentStatus::Pending, AgentStatus::from('pending'));
    }

    public function testFromQueued(): void
    {
        $this->assertSame(AgentStatus::Queued, AgentStatus::from('queued'));
    }

    public function testFromRunning(): void
    {
        $this->assertSame(AgentStatus::Running, AgentStatus::from('running'));
    }

    public function testFromStreaming(): void
    {
        $this->assertSame(AgentStatus::Streaming, AgentStatus::from('streaming'));
    }

    public function testFromCompleted(): void
    {
        $this->assertSame(AgentStatus::Completed, AgentStatus::from('completed'));
    }

    public function testFromFailed(): void
    {
        $this->assertSame(AgentStatus::Failed, AgentStatus::from('failed'));
    }

    public function testFromStopped(): void
    {
        $this->assertSame(AgentStatus::Stopped, AgentStatus::from('stopped'));
    }

    public function testFromTimedOut(): void
    {
        $this->assertSame(AgentStatus::TimedOut, AgentStatus::from('timed_out'));
    }

    // -------------------------------------------------------------------------
    // AgentStatus::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        AgentStatus::from('invalid');
    }

    // -------------------------------------------------------------------------
    // AgentStatus::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromPending(): void
    {
        $this->assertSame(AgentStatus::Pending, AgentStatus::tryFrom('pending'));
    }

    public function testTryFromQueued(): void
    {
        $this->assertSame(AgentStatus::Queued, AgentStatus::tryFrom('queued'));
    }

    public function testTryFromRunning(): void
    {
        $this->assertSame(AgentStatus::Running, AgentStatus::tryFrom('running'));
    }

    public function testTryFromStreaming(): void
    {
        $this->assertSame(AgentStatus::Streaming, AgentStatus::tryFrom('streaming'));
    }

    public function testTryFromCompleted(): void
    {
        $this->assertSame(AgentStatus::Completed, AgentStatus::tryFrom('completed'));
    }

    public function testTryFromFailed(): void
    {
        $this->assertSame(AgentStatus::Failed, AgentStatus::tryFrom('failed'));
    }

    public function testTryFromStopped(): void
    {
        $this->assertSame(AgentStatus::Stopped, AgentStatus::tryFrom('stopped'));
    }

    public function testTryFromTimedOut(): void
    {
        $this->assertSame(AgentStatus::TimedOut, AgentStatus::tryFrom('timed_out'));
    }

    // -------------------------------------------------------------------------
    // AgentStatus::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(AgentStatus::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(AgentStatus::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(AgentStatus::tryFrom('PENDING'));
        $this->assertNull(AgentStatus::tryFrom('Completed'));
    }
}
