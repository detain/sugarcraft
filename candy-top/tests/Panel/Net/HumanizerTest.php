<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Net;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Panel\Net\Humanizer;

/**
 * Expected strings are worked through btop's floating_humanizer by hand
 * (src/btop_tools.cpp:419-517): scale by 100 (x8 for bits), shift by 10 /
 * divide by 1000, carve the decimals out of the digit string.
 */
final class HumanizerTest extends TestCase
{
    /** @return iterable<string, array{string, int|float, bool, bool, bool, bool, string}> */
    public static function cases(): iterable
    {
        // label => [expected, value, shorten, bit, perSecond, base10Sizes, base10Bitrate]
        yield 'zero bytes' => ['0 Byte', 0, false, false, false, false, 'Auto'];
        yield 'bytes under 1 KiB' => ['999 Byte', 999, false, false, false, false, 'Auto'];
        yield 'three-digit carve 1.20' => ['1.20 KiB', 1234, false, false, false, false, 'Auto'];
        yield 'four-digit carve 10.0' => ['10.0 KiB', 10240, false, false, false, false, 'Auto'];
        yield 'integer KiB' => ['120 KiB', 123456, false, false, false, false, 'Auto'];
        yield 'MiB' => ['5.50 MiB', 5767168, false, false, false, false, 'Auto'];
        yield 'per second bytes' => ['252 KiB/s', 258048, false, false, true, false, 'Auto'];
        yield 'per second bits' => ['80.0 Kibps', 10240, false, true, true, false, 'Auto'];
        yield 'zero bits' => ['0 bitps', 0, false, true, true, false, 'Auto'];
        yield 'shorten floor' => ['10K', 10240, true, false, false, false, 'Auto'];
        yield 'shorten 1.2K' => ['1.2K', 1234, true, false, false, false, 'Auto'];
        yield 'shorten xyzw promotes' => ['1.0M', 1023 * 1024, true, false, false, false, 'Auto'];
        yield 'shorten bytes' => ['999B', 999, true, false, false, false, 'Auto'];
        yield 'base10 sizes truncate four digits' => ['12 kB', 12345, false, false, false, true, 'Auto'];
        yield 'base10 three-digit carve' => ['1.23 kB', 1234, false, false, false, true, 'Auto'];
        yield 'base10 bits follow sizes on Auto' => ['98 kbps', 12345, false, true, true, true, 'Auto'];
        yield 'base10 bitrate forced True' => ['98 kbps', 12345, false, true, true, false, 'True'];
        yield 'base10 bitrate forced False' => ['96.4 Kibps', 12345, false, true, true, true, 'False'];
        yield 'bitrate override ignores byte rates' => ['12 kB/s', 12345, false, false, true, true, 'False'];
        yield 'negative is zero' => ['0 Byte', -1.0, false, false, false, false, 'Auto'];
    }

    #[DataProvider('cases')]
    public function testFormatsLikeBtop(string $expected, int|float $value, bool $shorten, bool $bit, bool $perSecond, bool $base10, string $bitrate): void
    {
        $this->assertSame($expected, Humanizer::new($base10, $bitrate)->format($value, $shorten, 0, $bit, $perSecond));
    }

    public function testStartOffsetsTheUnit(): void
    {
        $this->assertSame('5.00 KiB', Humanizer::new()->format(5, start: 1));
    }

    public function testHugeValuesSaturateInsteadOfOverflowing(): void
    {
        $this->assertMatchesRegularExpression('/^\d+(\.\d)?[A-Z]$/', Humanizer::new()->format(PHP_INT_MAX, true));
    }
}
