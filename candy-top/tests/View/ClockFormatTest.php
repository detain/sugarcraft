<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\View\ClockFormat;

final class ClockFormatTest extends TestCase
{
    /** 2023-11-14 22:13:20 UTC, a Tuesday. */
    private const T = 1_700_000_000;

    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'btop default %X' => ['%X', '22:13:20'];
        yield 'date + time' => ['%Y-%m-%d %H:%M', '2023-11-14 22:13'];
        yield 'names' => ['%a %A %b %B', 'Tue Tuesday Nov November'];
        yield 'c locale %c' => ['%c', 'Tue Nov 14 22:13:20 2023'];
        yield '12-hour' => ['%I:%M %p', '10:13 PM'];
        yield 'padded day' => ['[%e] %j', '[14] 318'];
        yield 'literal percent + unknown' => ['100%% %Q', '100% %Q'];
        yield 'composites' => ['%D %F %R %T', '11/14/23 2023-11-14 22:13 22:13:20'];
        yield 'epoch' => ['%s', '1700000000'];
    }

    #[DataProvider('formats')]
    public function testStrftimeInTheCLocale(string $format, string $expected): void
    {
        $this->assertSame($expected, ClockFormat::strftime($format, self::T, new \DateTimeZone('UTC')));
    }

    public function testCustomTokens(): void
    {
        $out = ClockFormat::format('/user@/host %H /uptime', self::T, 'joe', 'box', 3_725.0, new \DateTimeZone('UTC'));
        $this->assertSame('joe@box 22 01:02:05', $out);
    }

    public function testUptimeDropsSecondsPastEightCharacters(): void
    {
        // 2d 03:04:05 is 11 chars -> btop trims the trailing ":05".
        $this->assertSame('2d 03:04', ClockFormat::format('/uptime', self::T, uptime: 2 * 86400 + 3 * 3600 + 4 * 60 + 5));
    }

    public function testEmptyFormatDisablesTheClock(): void
    {
        $this->assertSame('', ClockFormat::format('', self::T));
    }

    public function testDhms(): void
    {
        $this->assertSame('00:00:09', ClockFormat::dhms(9));
        $this->assertSame('1d 00:00:00', ClockFormat::dhms(86400));
    }
}
