<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Modifiers;
use PHPUnit\Framework\TestCase;

final class ModifiersTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        $m = Modifiers::none();
        $this->assertTrue($m->isEmpty());
        $this->assertSame(0, $m->toBitfield());
    }

    public function testOfBuildsFromBooleans(): void
    {
        $m = Modifiers::of(shift: true, alt: false, ctrl: true);
        $this->assertTrue($m->shift);
        $this->assertFalse($m->alt);
        $this->assertTrue($m->ctrl);
        $this->assertFalse($m->isEmpty());
    }

    public function testToBitfieldEncodesAllFlags(): void
    {
        $m = new Modifiers(shift: true, alt: true, ctrl: true);
        $this->assertSame(
            Modifiers::SHIFT | Modifiers::ALT | Modifiers::CTRL,
            $m->toBitfield(),
        );
    }

    public function testToBitfieldShiftOnly(): void
    {
        $m = new Modifiers(shift: true);
        $this->assertSame(Modifiers::SHIFT, $m->toBitfield());
    }

    public function testToBitfieldAltOnly(): void
    {
        $m = new Modifiers(alt: true);
        $this->assertSame(Modifiers::ALT, $m->toBitfield());
    }

    public function testToBitfieldCtrlOnly(): void
    {
        $m = new Modifiers(ctrl: true);
        $this->assertSame(Modifiers::CTRL, $m->toBitfield());
    }

    public function testFromXtermModNoModifiers(): void
    {
        // mod = 1 means no modifiers in xterm convention.
        $m = Modifiers::fromXtermMod(1);
        $this->assertTrue($m->isEmpty());
    }

    public function testFromXtermModShift(): void
    {
        // mod = 2 → bits = 1 → shift
        $m = Modifiers::fromXtermMod(2);
        $this->assertTrue($m->shift);
        $this->assertFalse($m->alt);
        $this->assertFalse($m->ctrl);
    }

    public function testFromXtermModAlt(): void
    {
        // mod = 3 → bits = 2 → alt
        $m = Modifiers::fromXtermMod(3);
        $this->assertFalse($m->shift);
        $this->assertTrue($m->alt);
        $this->assertFalse($m->ctrl);
    }

    public function testFromXtermModCtrl(): void
    {
        // mod = 5 → bits = 4 → ctrl
        $m = Modifiers::fromXtermMod(5);
        $this->assertFalse($m->shift);
        $this->assertFalse($m->alt);
        $this->assertTrue($m->ctrl);
    }

    public function testFromXtermModAllSet(): void
    {
        // mod = 8 → bits = 7 → shift+alt+ctrl
        $m = Modifiers::fromXtermMod(8);
        $this->assertTrue($m->shift);
        $this->assertTrue($m->alt);
        $this->assertTrue($m->ctrl);
    }

    public function testFromXtermModBelowOneClampsToZero(): void
    {
        $m = Modifiers::fromXtermMod(0);
        $this->assertTrue($m->isEmpty());

        $m2 = Modifiers::fromXtermMod(-3);
        $this->assertTrue($m2->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenAnySet(): void
    {
        $this->assertFalse((new Modifiers(shift: true))->isEmpty());
        $this->assertFalse((new Modifiers(alt: true))->isEmpty());
        $this->assertFalse((new Modifiers(ctrl: true))->isEmpty());
    }
}
