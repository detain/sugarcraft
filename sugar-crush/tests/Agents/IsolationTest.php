<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Isolation;

/**
 * Tests for Isolation enum - isolation level for agent workspace operations.
 */
final class IsolationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('none', Isolation::None->value);
        $this->assertSame('worktree', Isolation::Worktree->value);
    }

    public function testCaseCount(): void
    {
        $cases = Isolation::cases();
        $this->assertCount(2, $cases);
    }

    // -------------------------------------------------------------------------
    // Isolation::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromNone(): void
    {
        $this->assertSame(Isolation::None, Isolation::from('none'));
    }

    public function testFromWorktree(): void
    {
        $this->assertSame(Isolation::Worktree, Isolation::from('worktree'));
    }

    // -------------------------------------------------------------------------
    // Isolation::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        Isolation::from('invalid');
    }

    // -------------------------------------------------------------------------
    // Isolation::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromNone(): void
    {
        $this->assertSame(Isolation::None, Isolation::tryFrom('none'));
    }

    public function testTryFromWorktree(): void
    {
        $this->assertSame(Isolation::Worktree, Isolation::tryFrom('worktree'));
    }

    // -------------------------------------------------------------------------
    // Isolation::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(Isolation::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(Isolation::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(Isolation::tryFrom('NONE'));
        $this->assertNull(Isolation::tryFrom('Worktree'));
    }
}
