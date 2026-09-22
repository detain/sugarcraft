<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests\Dock;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Dock\DockSlot;

/**
 * DockSlot — the per-pane stack entry VO with its rational height weight.
 */
final class DockSlotTest extends TestCase
{
    public function testNewDefaultsToUnitWeight(): void
    {
        $slot = DockSlot::new('files');
        $this->assertSame('files', $slot->paneId);
        $this->assertSame(1, $slot->weightNum);
        $this->assertSame(1, $slot->weightDenom);
    }

    public function testNewCarriesExplicitRationalWeight(): void
    {
        $slot = DockSlot::new('term', 2, 3);
        $this->assertSame(2, $slot->weightNum);
        $this->assertSame(3, $slot->weightDenom);
    }

    public function testEmptyPaneIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paneId must be a non-empty string');
        DockSlot::new('');
    }

    public function testZeroWeightNumeratorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numerator must be >= 1; got 0');
        DockSlot::new('files', 0, 1);
    }

    public function testNegativeWeightNumeratorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numerator must be >= 1; got -3');
        DockSlot::new('files', -3, 1);
    }

    public function testZeroWeightDenominatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('denominator must be >= 1; got 0');
        DockSlot::new('files', 1, 0);
    }

    public function testNegativeWeightDenominatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('denominator must be >= 1; got -2');
        DockSlot::new('files', 5, -2);
    }

    public function testWithWeightReturnsNewInstanceCarryingReplacement(): void
    {
        $slot = DockSlot::new('files', 1, 1);
        $heavier = $slot->withWeight(3, 2);

        $this->assertNotSame($slot, $heavier);
        $this->assertSame('files', $heavier->paneId);
        $this->assertSame(3, $heavier->weightNum);
        $this->assertSame(2, $heavier->weightDenom);
    }

    public function testWithWeightLeavesOriginalUntouched(): void
    {
        $slot = DockSlot::new('files', 1, 1);
        $slot->withWeight(9, 4);

        $this->assertSame(1, $slot->weightNum);
        $this->assertSame(1, $slot->weightDenom);
    }

    public function testWithWeightValidatesItsInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numerator must be >= 1; got 0');
        DockSlot::new('files')->withWeight(0, 1);
    }

    public function testPublicConstructorEnforcesTheSameGuardsAsNew(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DockSlot('', 1, 1);
    }
}
