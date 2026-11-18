<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testScaleValueBelowThreshold(): void
    {
        $this->assertSame('512', Format::scaleValue(512));
        $this->assertSame('0', Format::scaleValue(0));
    }

    public function testScaleValueBinarySuffixes(): void
    {
        $this->assertSame('1K', Format::scaleValue(1024));
        $this->assertSame('1.5K', Format::scaleValue(1536));
        $this->assertSame('1M', Format::scaleValue(1024 * 1024));
        $this->assertSame('2G', Format::scaleValue(2 * 1024 ** 3));
    }

    public function testScaleValueNegative(): void
    {
        $this->assertSame('-1K', Format::scaleValue(-1024));
    }

    public function testScaleValueDecimals(): void
    {
        // 1792 / 1024 = 1.75 → rounds differently at 1 vs 2 decimals.
        $this->assertSame('1.8K', Format::scaleValue(1792, 1));
        $this->assertSame('1.75K', Format::scaleValue(1792, 2));
    }

    public function testSiBytes(): void
    {
        $this->assertSame('500B', Format::siBytes(500));
        $this->assertSame('1KB', Format::siBytes(1000));
        $this->assertSame('1.5MB', Format::siBytes(1_500_000));
        $this->assertSame('-1KB', Format::siBytes(-1000));
    }

    public function testPicoseconds(): void
    {
        $this->assertSame('500ps', Format::picoseconds(500));
        $this->assertSame('1us', Format::picoseconds(1000));
        $this->assertSame('1ms', Format::picoseconds(1_000_000));
        $this->assertSame('2s', Format::picoseconds(2_000_000_000));
    }

    public function testPicosecondsComposesMinutes(): void
    {
        // 90s → "1m 30s"
        $this->assertSame('1m 30s', Format::picoseconds(90 * 1_000_000_000));
    }

    public function testPicosecondsComposesHours(): void
    {
        // 3661s → "1h 01m 01s"
        $this->assertSame('1h 01m 01s', Format::picoseconds(3661 * 1_000_000_000));
    }

    public function testDurationSeconds(): void
    {
        $this->assertSame('42s', Format::duration(42));
        $this->assertSame('0.5s', Format::duration(0.5));
    }

    public function testDurationMinutes(): void
    {
        $this->assertSame('3m 5s', Format::duration(185));
        $this->assertSame('5m', Format::duration(300));
    }

    public function testDurationHours(): void
    {
        $this->assertSame('2h 07m', Format::duration(7620));
        $this->assertSame('5h', Format::duration(18000));
    }

    /** Mirrors the byte-size copies sugar-core's Format::iecBytes replaces: one binary unit set, one decimal. */
    public function testIecBytesBelowOneKibIsWholeBytes(): void
    {
        $this->assertSame('0 B', Format::iecBytes(0));
        $this->assertSame('512 B', Format::iecBytes(512));
        $this->assertSame('1023 B', Format::iecBytes(1023));
    }

    public function testIecBytesOneDecimalAndSpaceBeforeUnit(): void
    {
        $this->assertSame('1.0 KiB', Format::iecBytes(1024));
        $this->assertSame('4.0 KiB', Format::iecBytes(4096));
        $this->assertSame('1.5 KiB', Format::iecBytes(1536));
        $this->assertSame('1.0 MiB', Format::iecBytes(1024 * 1024));
        $this->assertSame('2.0 GiB', Format::iecBytes(2 * 1024 ** 3));
        $this->assertSame('3.0 TiB', Format::iecBytes(3 * 1024 ** 4));
        $this->assertSame('5.0 PiB', Format::iecBytes(5 * 1024 ** 5));
    }

    /** A value that rounds up to 1024 of one unit moves to the next, never "1024.0 KiB". */
    public function testIecBytesPromotesRoundingOverflowToNextUnit(): void
    {
        $this->assertSame('1.0 MiB', Format::iecBytes(1024 * 1024 - 1));
        $this->assertSame('1.0 GiB', Format::iecBytes(1024 ** 3 - 1));
    }

    public function testIecBytesPibIsTheLargestUnit(): void
    {
        $this->assertSame('1024.0 PiB', Format::iecBytes(1 << 60));
        $this->assertSame('8192.0 PiB', Format::iecBytes(PHP_INT_MAX));
    }

    public function testIecBytesNegativeKeepsSign(): void
    {
        $this->assertSame('-2.0 KiB', Format::iecBytes(-2048));
        $this->assertSame('-1 B', Format::iecBytes(-1));
    }

    /** -PHP_INT_MIN overflows an int; the float view must carry it instead of throwing. */
    public function testIecBytesHandlesIntMin(): void
    {
        $this->assertSame('-8192.0 PiB', Format::iecBytes(PHP_INT_MIN));
    }
}
