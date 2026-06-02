<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Format;

/**
 * Tests for Format helper.
 */
final class FormatTest extends TestCase
{
    public function testScaleValueSmallNumbers(): void
    {
        $this->assertSame('0', Format::scaleValue(0));
        $this->assertSame('100', Format::scaleValue(100));
        $this->assertSame('1023', Format::scaleValue(1023));
    }

    public function testScaleValueKilo(): void
    {
        $this->assertSame('1K', Format::scaleValue(1024));
        $this->assertSame('1.5K', Format::scaleValue(1536));
        $this->assertSame('2K', Format::scaleValue(2048));
        $this->assertSame('1M', Format::scaleValue(1024 * 1024));
    }

    public function testScaleValueMega(): void
    {
        $this->assertSame('1M', Format::scaleValue(1024 * 1024));
        $this->assertSame('1.5M', Format::scaleValue(1536 * 1024));
    }

    public function testScaleValueGiga(): void
    {
        $this->assertSame('1G', Format::scaleValue(1024 * 1024 * 1024));
    }

    public function testScaleValueTera(): void
    {
        $this->assertSame('1T', Format::scaleValue(1024 * 1024 * 1024 * 1024));
    }

    public function testScaleValueNegative(): void
    {
        $this->assertSame('-1K', Format::scaleValue(-1024));
        $this->assertSame('-1.5M', Format::scaleValue(-1536 * 1024));
    }

    public function testScaleValueDecimals(): void
    {
        $this->assertSame('1.5K', Format::scaleValue(1536, 2));
        $this->assertSame('1K', Format::scaleValue(1024, 2));
    }

    public function testSiBytesSmall(): void
    {
        $this->assertSame('500B', Format::siBytes(500));
        $this->assertSame('999B', Format::siBytes(999));
    }

    public function testSiBytesKilo(): void
    {
        $this->assertSame('1KB', Format::siBytes(1000));
        $this->assertSame('1.5KB', Format::siBytes(1500));
    }

    public function testSiBytesMega(): void
    {
        $this->assertSame('1MB', Format::siBytes(1000 * 1000));
        $this->assertSame('2.5MB', Format::siBytes(2500 * 1000));
    }

    public function testSiBytesGiga(): void
    {
        $this->assertSame('1GB', Format::siBytes(1000 * 1000 * 1000));
    }

    public function testPicosecondsSmall(): void
    {
        $this->assertSame('500ps', Format::picoseconds(500));
        $this->assertSame('999ps', Format::picoseconds(999));
    }

    public function testPicosecondsMicroseconds(): void
    {
        $this->assertSame('1us', Format::picoseconds(1000));
        $this->assertSame('1.5us', Format::picoseconds(1500));
        $this->assertSame('999us', Format::picoseconds(999000));
    }

    public function testPicosecondsMilliseconds(): void
    {
        $this->assertSame('1ms', Format::picoseconds(1_000_000));
        $this->assertSame('1.5ms', Format::picoseconds(1_500_000));
    }

    public function testPicosecondsSeconds(): void
    {
        $this->assertSame('1s', Format::picoseconds(1_000_000_000));
        $this->assertSame('5s', Format::picoseconds(5_000_000_000));
    }

    public function testPicosecondsMinutes(): void
    {
        $this->assertSame('1m 00s', Format::picoseconds(60 * 1_000_000_000));
        $this->assertSame('5m 30s', Format::picoseconds(330 * 1_000_000_000));
    }

    public function testPicosecondsHours(): void
    {
        $this->assertSame('1h 00m 00s', Format::picoseconds(3600 * 1_000_000_000));
        $this->assertSame('1h 30m 00s', Format::picoseconds(5400 * 1_000_000_000));
    }

    public function testDurationSeconds(): void
    {
        $this->assertSame('5s', Format::duration(5));
        $this->assertSame('30.5s', Format::duration(30.5));
    }

    public function testDurationMinutes(): void
    {
        // Exact-minute spans omit the seconds component ("1m", not "1m 0s").
        $this->assertSame('1m', Format::duration(60));
        $this->assertSame('5m', Format::duration(300));
        $this->assertSame('5m 30s', Format::duration(330));
    }

    public function testDurationHours(): void
    {
        $this->assertSame('1h', Format::duration(3600));
        $this->assertSame('2h', Format::duration(7200));
        $this->assertSame('1h 30m', Format::duration(5400));
    }

    public function testDurationZero(): void
    {
        $this->assertSame('0s', Format::duration(0));
    }
}
