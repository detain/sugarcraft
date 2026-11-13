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

    public function testFromKittyIntParsesLockStateBits(): void
    {
        // m4: spec bits 6 (CapsLock) and 7 (NumLock) reach the constants.
        $caps = KeyModifier::fromKittyInt(64);
        $this->assertTrue($caps->includes(KeyModifier::CAPS_LOCK));
        $this->assertFalse($caps->includes(KeyModifier::NUM_LOCK));

        $num = KeyModifier::fromKittyInt(128);
        $this->assertTrue($num->includes(KeyModifier::NUM_LOCK));
        $this->assertFalse($num->includes(KeyModifier::CAPS_LOCK));
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

    // --- memoization identity law (SI-18) ---

    public function testAllFactoriesAgreeOnIdentityForModifierNone(): void
    {
        // Every route to mask 0 yields the ONE none() instance: the cache,
        // not per-property singletons, is what makes this hold.
        $this->assertSame(KeyModifier::none(), KeyModifier::fromKittyInt(0));
        $this->assertSame(KeyModifier::none(), KeyModifier::fromSgrMouse(0));
        $this->assertSame(KeyModifier::none(), KeyModifier::fromXtermParam(1));
        $this->assertSame(KeyModifier::none(), KeyModifier::fromMask(0));
    }

    public function testFactoriesMemoizeByFinalMask(): void
    {
        // Different wires, same storage mask -> identical instance.
        $this->assertSame(KeyModifier::fromKittyInt(1), KeyModifier::shift());
        $this->assertSame(KeyModifier::fromSgrMouse(3), KeyModifier::fromKittyInt(3));
        // Repeat calls hand back the same object, never a copy.
        $this->assertSame(KeyModifier::fromXtermParam(3), KeyModifier::fromXtermParam(3));
    }

    // --- fromXtermParam() zero/negative guard (SI-18) ---

    public function testFromXtermParamRefusesToFabricateBitsFromNonPositiveField(): void
    {
        // Without the guard, (0 - 1) = -1 passes every bit test and the
        // factory hands back SHIFT|ALT|CTRL|META from a "no modifiers" wire
        // field. The guard answers none() instead.
        $this->assertSame(KeyModifier::none(), KeyModifier::fromXtermParam(0));
        $this->assertTrue(KeyModifier::fromXtermParam(-1)->equals(KeyModifier::none()));
        $this->assertSame(0, KeyModifier::fromXtermParam(0)->value());
    }

    // --- shiftAltCtrl() rename + deprecated alias (SI-18) ---

    public function testShiftAltCtrlCarriesExactlyTheLegacyTrio(): void
    {
        $modifier = KeyModifier::shiftAltCtrl();

        $this->assertSame(
            KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::CTRL,
            $modifier->value()
        );
        // The extended bits can never arrive here: this is the legacy 0x1f
        // wire set, not "all modifiers".
        $this->assertFalse($modifier->includes(KeyModifier::META));
        $this->assertFalse($modifier->includes(KeyModifier::SUPER));
        $this->assertFalse($modifier->includes(KeyModifier::HYPER));
    }

    public function testAllAliasResolvesToTheSameInstanceAsShiftAltCtrl(): void
    {
        $this->assertSame(KeyModifier::shiftAltCtrl(), KeyModifier::all());
    }

    // --- fromMask() public IPC/bridge seam (SI-18) ---

    public function testFromMaskRoundTripsAStoredValue(): void
    {
        $stored = KeyModifier::altCtrl()->value();

        $this->assertSame(KeyModifier::altCtrl(), KeyModifier::fromMask($stored));
    }

    public function testFromMaskRejectsUndefinedBitsAndNegatives(): void
    {
        // 1 << 8 is outside the defined union; fail fast at the boundary.
        $this->expectException(\InvalidArgumentException::class);
        KeyModifier::fromMask(1 << 8);
    }

    public function testFromMaskRejectsNegativeMask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyModifier::fromMask(-1);
    }

    // --- equals() is the comparison API (doc test) ---

    public function testEqualsComparesAcrossFactories(): void
    {
        // Hosts compare with equals(): any two instances of the same mask are
        // equal whether the cache handed back one object or a future shape
        // handed back two.
        $this->assertTrue(KeyModifier::fromKittyInt(1)->equals(KeyModifier::shift()));
        $this->assertFalse(KeyModifier::shift()->equals(KeyModifier::alt()));
    }
}
