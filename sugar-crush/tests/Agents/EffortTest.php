<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Effort;

/**
 * Tests for Effort enum - computational effort tiers for agent operations.
 */
final class EffortTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('low', Effort::Low->value);
        $this->assertSame('medium', Effort::Medium->value);
        $this->assertSame('high', Effort::High->value);
        $this->assertSame('xhigh', Effort::XHigh->value);
        $this->assertSame('max', Effort::Max->value);
    }

    public function testCaseCount(): void
    {
        $cases = Effort::cases();
        $this->assertCount(5, $cases);
    }

    // -------------------------------------------------------------------------
    // Effort::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromLow(): void
    {
        $this->assertSame(Effort::Low, Effort::from('low'));
    }

    public function testFromMedium(): void
    {
        $this->assertSame(Effort::Medium, Effort::from('medium'));
    }

    public function testFromHigh(): void
    {
        $this->assertSame(Effort::High, Effort::from('high'));
    }

    public function testFromXHigh(): void
    {
        $this->assertSame(Effort::XHigh, Effort::from('xhigh'));
    }

    public function testFromMax(): void
    {
        $this->assertSame(Effort::Max, Effort::from('max'));
    }

    // -------------------------------------------------------------------------
    // Effort::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        Effort::from('invalid');
    }

    // -------------------------------------------------------------------------
    // Effort::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromLow(): void
    {
        $this->assertSame(Effort::Low, Effort::tryFrom('low'));
    }

    public function testTryFromMedium(): void
    {
        $this->assertSame(Effort::Medium, Effort::tryFrom('medium'));
    }

    public function testTryFromHigh(): void
    {
        $this->assertSame(Effort::High, Effort::tryFrom('high'));
    }

    public function testTryFromXHigh(): void
    {
        $this->assertSame(Effort::XHigh, Effort::tryFrom('xhigh'));
    }

    public function testTryFromMax(): void
    {
        $this->assertSame(Effort::Max, Effort::tryFrom('max'));
    }

    // -------------------------------------------------------------------------
    // Effort::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(Effort::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(Effort::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(Effort::tryFrom('LOW'));
        $this->assertNull(Effort::tryFrom('Medium'));
    }
}
