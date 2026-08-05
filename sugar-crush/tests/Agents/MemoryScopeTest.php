<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;

/**
 * Tests for MemoryScope enum - memory scope for agent context persistence.
 */
final class MemoryScopeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('user', MemoryScope::User->value);
        $this->assertSame('project', MemoryScope::Project->value);
        $this->assertSame('local', MemoryScope::Local->value);
    }

    public function testCaseCount(): void
    {
        $cases = MemoryScope::cases();
        $this->assertCount(3, $cases);
    }

    // -------------------------------------------------------------------------
    // MemoryScope::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromUser(): void
    {
        $this->assertSame(MemoryScope::User, MemoryScope::from('user'));
    }

    public function testFromProject(): void
    {
        $this->assertSame(MemoryScope::Project, MemoryScope::from('project'));
    }

    public function testFromLocal(): void
    {
        $this->assertSame(MemoryScope::Local, MemoryScope::from('local'));
    }

    // -------------------------------------------------------------------------
    // MemoryScope::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        MemoryScope::from('invalid');
    }

    // -------------------------------------------------------------------------
    // MemoryScope::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromUser(): void
    {
        $this->assertSame(MemoryScope::User, MemoryScope::tryFrom('user'));
    }

    public function testTryFromProject(): void
    {
        $this->assertSame(MemoryScope::Project, MemoryScope::tryFrom('project'));
    }

    public function testTryFromLocal(): void
    {
        $this->assertSame(MemoryScope::Local, MemoryScope::tryFrom('local'));
    }

    // -------------------------------------------------------------------------
    // MemoryScope::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(MemoryScope::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(MemoryScope::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(MemoryScope::tryFrom('USER'));
        $this->assertNull(MemoryScope::tryFrom('Project'));
    }
}
