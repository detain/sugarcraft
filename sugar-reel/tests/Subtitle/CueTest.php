<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Subtitle;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Subtitle\Cue;

/**
 * Unit tests for Cue value object.
 *
 * @covers \SugarCraft\Reel\Subtitle\Cue
 */
final class CueTest extends TestCase
{
    /**
     * @testdox constructor stores start, end, and text as readonly properties
     */
    public function testConstructorStoresProperties(): void
    {
        $cue = new Cue(1.5, 3.5, 'Hello world');

        $this->assertSame(1.5, $cue->start);
        $this->assertSame(3.5, $cue->end);
        $this->assertSame('Hello world', $cue->text);
    }

    /**
     * @testdox contains() returns true when seconds equals start (inclusive)
     */
    public function testContainsIsInclusiveAtStart(): void
    {
        $cue = new Cue(5.0, 10.0, 'Test');

        $this->assertTrue($cue->contains(5.0));
    }

    /**
     * @testdox contains() returns true for any value strictly between start and end
     */
    public function testContainsInsideRange(): void
    {
        $cue = new Cue(5.0, 10.0, 'Test');

        $this->assertTrue($cue->contains(5.001));
        $this->assertTrue($cue->contains(7.5));
        $this->assertTrue($cue->contains(9.999));
    }

    /**
     * @testdox contains() returns false when seconds equals end (exclusive)
     */
    public function testContainsIsExclusiveAtEnd(): void
    {
        $cue = new Cue(5.0, 10.0, 'Test');

        $this->assertFalse($cue->contains(10.0));
    }

    /**
     * @testdox contains() returns false when seconds is before start
     */
    public function testContainsReturnsFalseBeforeStart(): void
    {
        $cue = new Cue(5.0, 10.0, 'Test');

        $this->assertFalse($cue->contains(4.999));
        $this->assertFalse($cue->contains(0.0));
        $this->assertFalse($cue->contains(-1.0));
    }

    /**
     * @testdox contains() returns false when seconds is after end
     */
    public function testContainsReturnsFalseAfterEnd(): void
    {
        $cue = new Cue(5.0, 10.0, 'Test');

        $this->assertFalse($cue->contains(10.001));
        $this->assertFalse($cue->contains(100.0));
    }

    /**
     * @testdox contains() works correctly with floating point boundaries
     */
    public function testContainsWithFloatingPoint(): void
    {
        $cue = new Cue(0.1, 0.2, 'Short');

        $this->assertFalse($cue->contains(0.099));
        $this->assertTrue($cue->contains(0.1));
        $this->assertTrue($cue->contains(0.15));
        $this->assertFalse($cue->contains(0.2));
    }

    /**
     * @testdox zero-duration cue (start equals end) is never "active" — contains is always false
     *
     * Since the interval is [start, end) and start == end, the half-open interval
     * is empty: no value satisfies start <= x < end when start == end.
     */
    public function testContainsZeroDurationCueIsAlwaysFalse(): void
    {
        $cue = new Cue(5.0, 5.0, 'Zero duration');

        $this->assertFalse($cue->contains(5.0));
        $this->assertFalse($cue->contains(5.001));
        $this->assertFalse($cue->contains(4.999));
    }

    /**
     * @testdox Cue is immutable — properties are readonly
     */
    public function testPropertiesAreReadonly(): void
    {
        $cue = new Cue(1.0, 2.0, 'Original');

        // Properties cannot be modified (would cause Error)
        $this->expectException(\Error::class);
        $cue->start = 99.0;
    }
}
