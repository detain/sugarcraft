<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TeammateStatus;

/**
 * Tests for TeammateStatus enum - operational states for teammate agents in a team.
 */
final class TeammateStatusTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('idle', TeammateStatus::Idle->value);
        $this->assertSame('active', TeammateStatus::Active->value);
        $this->assertSame('waiting', TeammateStatus::Waiting->value);
        $this->assertSame('completed', TeammateStatus::Completed->value);
        $this->assertSame('failed', TeammateStatus::Failed->value);
        $this->assertSame('interrupted', TeammateStatus::Interrupted->value);
    }

    public function testCaseCount(): void
    {
        $cases = TeammateStatus::cases();
        $this->assertCount(6, $cases);
    }

    // -------------------------------------------------------------------------
    // TeammateStatus::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromIdle(): void
    {
        $this->assertSame(TeammateStatus::Idle, TeammateStatus::from('idle'));
    }

    public function testFromActive(): void
    {
        $this->assertSame(TeammateStatus::Active, TeammateStatus::from('active'));
    }

    public function testFromWaiting(): void
    {
        $this->assertSame(TeammateStatus::Waiting, TeammateStatus::from('waiting'));
    }

    public function testFromCompleted(): void
    {
        $this->assertSame(TeammateStatus::Completed, TeammateStatus::from('completed'));
    }

    public function testFromFailed(): void
    {
        $this->assertSame(TeammateStatus::Failed, TeammateStatus::from('failed'));
    }

    public function testFromInterrupted(): void
    {
        $this->assertSame(TeammateStatus::Interrupted, TeammateStatus::from('interrupted'));
    }

    // -------------------------------------------------------------------------
    // TeammateStatus::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        TeammateStatus::from('invalid');
    }

    // -------------------------------------------------------------------------
    // TeammateStatus::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromIdle(): void
    {
        $this->assertSame(TeammateStatus::Idle, TeammateStatus::tryFrom('idle'));
    }

    public function testTryFromActive(): void
    {
        $this->assertSame(TeammateStatus::Active, TeammateStatus::tryFrom('active'));
    }

    public function testTryFromWaiting(): void
    {
        $this->assertSame(TeammateStatus::Waiting, TeammateStatus::tryFrom('waiting'));
    }

    public function testTryFromCompleted(): void
    {
        $this->assertSame(TeammateStatus::Completed, TeammateStatus::tryFrom('completed'));
    }

    public function testTryFromFailed(): void
    {
        $this->assertSame(TeammateStatus::Failed, TeammateStatus::tryFrom('failed'));
    }

    public function testTryFromInterrupted(): void
    {
        $this->assertSame(TeammateStatus::Interrupted, TeammateStatus::tryFrom('interrupted'));
    }

    // -------------------------------------------------------------------------
    // TeammateStatus::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(TeammateStatus::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(TeammateStatus::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(TeammateStatus::tryFrom('IDLE'));
        $this->assertNull(TeammateStatus::tryFrom('Active'));
    }
}
