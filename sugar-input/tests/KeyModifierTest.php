<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\KeyModifier;

/**
 * Tests for KeyModifier bitmask methods and factory singletons.
 */
final class KeyModifierTest extends TestCase
{
    // --- includes() tests ---

    public function testIncludesReturnsTrueWhenFlagSet(): void
    {
        $modifier = KeyModifier::alt(); // ALT = 2

        $this->assertTrue($modifier->includes(KeyModifier::ALT));
    }

    public function testIncludesReturnsFalseWhenFlagNotSet(): void
    {
        $modifier = KeyModifier::alt(); // ALT = 2

        $this->assertFalse($modifier->includes(KeyModifier::SHIFT));
    }

    public function testIncludesWithCombinedModifiers(): void
    {
        $modifier = KeyModifier::altShift(); // ALT | SHIFT = 2 | 1 = 3

        $this->assertTrue($modifier->includes(KeyModifier::ALT));
        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
        $this->assertFalse($modifier->includes(KeyModifier::CTRL));
    }

    // --- equals() tests ---

    public function testEqualsReturnsTrueForSameMask(): void
    {
        $a = KeyModifier::alt();
        $b = KeyModifier::alt();

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentMask(): void
    {
        $a = KeyModifier::alt();
        $b = KeyModifier::ctrl();

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsTrueForCombinedModifiers(): void
    {
        $a = KeyModifier::altShift();
        $b = KeyModifier::altShift();

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentCombinations(): void
    {
        $a = KeyModifier::altShift();
        $b = KeyModifier::ctrlShift();

        $this->assertFalse($a->equals($b));
    }

    // --- value() tests ---

    public function testValueReturnsRawMaskForNone(): void
    {
        $modifier = KeyModifier::none();

        $this->assertSame(0, $modifier->value());
    }

    public function testValueReturnsCorrectMaskForSingleModifier(): void
    {
        $modifier = KeyModifier::alt();

        $this->assertSame(KeyModifier::ALT, $modifier->value());
    }

    public function testValueReturnsCorrectMaskForCombinedModifiers(): void
    {
        $modifier = KeyModifier::altShift();

        $this->assertSame(KeyModifier::ALT | KeyModifier::SHIFT, $modifier->value());
    }

    // --- Singleton factory tests ---

    public function testAltShiftFactoryReturnsCorrectMask(): void
    {
        $modifier = KeyModifier::altShift();

        $this->assertSame(KeyModifier::ALT | KeyModifier::SHIFT, $modifier->value());
        $this->assertTrue($modifier->includes(KeyModifier::ALT));
        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
        $this->assertFalse($modifier->includes(KeyModifier::CTRL));
    }

    public function testCtrlShiftFactoryReturnsCorrectMask(): void
    {
        $modifier = KeyModifier::ctrlShift();

        $this->assertSame(KeyModifier::CTRL | KeyModifier::SHIFT, $modifier->value());
        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
        $this->assertFalse($modifier->includes(KeyModifier::ALT));
    }

    public function testAltCtrlFactoryReturnsCorrectMask(): void
    {
        $modifier = KeyModifier::altCtrl();

        $this->assertSame(KeyModifier::ALT | KeyModifier::CTRL, $modifier->value());
        $this->assertTrue($modifier->includes(KeyModifier::ALT));
        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
        $this->assertFalse($modifier->includes(KeyModifier::SHIFT));
    }

    public function testAllFactoryReturnsShiftAltCtrl(): void
    {
        $modifier = KeyModifier::all();

        $expected = KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::CTRL;
        $this->assertSame($expected, $modifier->value());
        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
        $this->assertTrue($modifier->includes(KeyModifier::ALT));
        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
        $this->assertFalse($modifier->includes(KeyModifier::META));
    }

    // --- Singleton caching tests ---

    public function testSingletonsReturnSameInstance(): void
    {
        $first = KeyModifier::altShift();
        $second = KeyModifier::altShift();

        $this->assertSame($first, $second);
    }

    public function testDifferentSingletonsAreDifferentInstances(): void
    {
        $altShift = KeyModifier::altShift();
        $ctrlShift = KeyModifier::ctrlShift();

        $this->assertNotSame($altShift, $ctrlShift);
    }

    // --- fromKittyInt() tests ---

    public function testFromKittyIntParsesShiftBit(): void
    {
        $modifier = KeyModifier::fromKittyInt(1);

        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
    }

    public function testFromKittyIntParsesAllBits(): void
    {
        $modifier = KeyModifier::fromKittyInt(1 | 2 | 4 | 8 | 16 | 32);

        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
        $this->assertTrue($modifier->includes(KeyModifier::ALT));
        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
        $this->assertTrue($modifier->includes(KeyModifier::META));
        $this->assertTrue($modifier->includes(KeyModifier::SUPER));
        $this->assertTrue($modifier->includes(KeyModifier::HYPER));
    }

    // --- fromSgrMouse() tests ---

    public function testFromSgrMouseParsesShiftBit(): void
    {
        $modifier = KeyModifier::fromSgrMouse(1);

        $this->assertTrue($modifier->includes(KeyModifier::SHIFT));
    }

    public function testFromSgrMouseParsesAltBit(): void
    {
        $modifier = KeyModifier::fromSgrMouse(2);

        $this->assertTrue($modifier->includes(KeyModifier::ALT));
    }

    public function testFromSgrMouseParsesCtrlBit(): void
    {
        $modifier = KeyModifier::fromSgrMouse(4);

        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
    }

    public function testFromSgrMouseIgnoresHigherBits(): void
    {
        $modifier = KeyModifier::fromSgrMouse(4 | 8 | 16); // Ctrl bit plus higher bits

        $this->assertTrue($modifier->includes(KeyModifier::CTRL));
        $this->assertFalse($modifier->includes(KeyModifier::META));
        $this->assertFalse($modifier->includes(KeyModifier::SUPER));
    }
}
