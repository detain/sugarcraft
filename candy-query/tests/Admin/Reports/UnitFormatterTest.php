<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\UnitFormatter;

/**
 * Tests for UnitFormatter time/byte formatting.
 */
final class UnitFormatterTest extends TestCase
{
    public function testFormatTimePicoSeconds(): void
    {
        $result = UnitFormatter::formatTime(500);

        $this->assertSame('500ps', $result);
    }

    public function testFormatTimeMicroSeconds(): void
    {
        $result = UnitFormatter::formatTime(1500);

        $this->assertSame('1.5us', $result);
    }

    public function testFormatTimeMilliSeconds(): void
    {
        $result = UnitFormatter::formatTime(1500000);

        $this->assertSame('1.5ms', $result);
    }

    public function testFormatTimeSeconds(): void
    {
        $result = UnitFormatter::formatTime(1500000000);

        $this->assertSame('1.5s', $result);
    }

    public function testFormatTimeMinutes(): void
    {
        $result = UnitFormatter::formatTime(130000000000);

        $this->assertStringContainsString('2m', $result);
    }

    public function testFormatTimeHours(): void
    {
        $result = UnitFormatter::formatTime(7200000000000);

        $this->assertStringContainsString('h', $result);
    }

    public function testFormatTimeNull(): void
    {
        $result = UnitFormatter::formatTime(null);

        $this->assertSame('', $result);
    }

    public function testFormatTimeNonNumeric(): void
    {
        $result = UnitFormatter::formatTime('not_a_number');

        $this->assertSame('not_a_number', $result);
    }

    public function testFormatBytesBytes(): void
    {
        $result = UnitFormatter::formatBytes(500);

        $this->assertSame('500', $result);
    }

    public function testFormatBytesKilobytes(): void
    {
        $result = UnitFormatter::formatBytes(2048);

        $this->assertSame('2K', $result);
    }

    public function testFormatBytesMegabytes(): void
    {
        $result = UnitFormatter::formatBytes(5242880);

        $this->assertSame('5M', $result);
    }

    public function testFormatBytesGigabytes(): void
    {
        $result = UnitFormatter::formatBytes(2147483648);

        $this->assertSame('2G', $result);
    }

    public function testFormatBytesNull(): void
    {
        $result = UnitFormatter::formatBytes(null);

        $this->assertSame('', $result);
    }

    public function testFormatBytesNonNumeric(): void
    {
        $result = UnitFormatter::formatBytes('not_a_number');

        $this->assertSame('not_a_number', $result);
    }

    public function testFormatInteger(): void
    {
        $result = UnitFormatter::formatInteger(1234567);

        $this->assertSame('1,234,567', $result);
    }

    public function testFormatIntegerNegative(): void
    {
        $result = UnitFormatter::formatInteger(-1234567);

        $this->assertSame('-1,234,567', $result);
    }

    public function testFormatIntegerZero(): void
    {
        $result = UnitFormatter::formatInteger(0);

        $this->assertSame('0', $result);
    }

    public function testFormatFloat(): void
    {
        $result = UnitFormatter::formatFloat(1234.567);

        $this->assertSame('1,234.567', $result);
    }

    public function testFormatFloatWholeNumber(): void
    {
        $result = UnitFormatter::formatFloat(1234.0);

        $this->assertSame('1,234', $result);
    }

    public function testFormatDefault(): void
    {
        $result = UnitFormatter::format('some_string', 'string');

        $this->assertSame('some_string', $result);
    }

    public function testFormatWithColumnTypeTime(): void
    {
        $result = UnitFormatter::format(1500000000, 'time');

        $this->assertSame('1.5s', $result);
    }

    public function testFormatWithColumnTypeBytes(): void
    {
        $result = UnitFormatter::format(5242880, 'bytes');

        $this->assertSame('5M', $result);
    }

    public function testFormatWithColumnTypeInt(): void
    {
        $result = UnitFormatter::format(12345, 'int');

        $this->assertSame('12,345', $result);
    }

    public function testFormatWithColumnTypeBigint(): void
    {
        $result = UnitFormatter::format(1234567890123, 'bigint');

        $this->assertSame('1,234,567,890,123', $result);
    }
}
