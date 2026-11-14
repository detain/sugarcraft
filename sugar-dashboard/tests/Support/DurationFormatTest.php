<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Support\DurationFormat;

/**
 * Pins for the single duration clock-face renderer (finding
 * sugar-dashboard-180): Timer and Stopwatch used to hand-roll the same
 * h/m/s decomposition behind two same-named setters carrying different
 * units. The faces below are the exact bytes each component painted
 * before the dedup - the formatter must keep every one of them.
 */
final class DurationFormatTest extends TestCase
{
    /**
     * @return array<string, array{0:int,1:int|null,2:string}>
     */
    public static function faceProvider(): array
    {
        return [
            'zero' => [0, null, '00:00'],
            'seconds only' => [59, null, '00:59'],
            'one minute' => [60, null, '01:00'],
            'below an hour' => [3599, null, '59:59'],
            'exactly one hour' => [3600, null, '1:00:00'],
            'hour cross' => [3661, null, '1:01:01'],
            'hundredths tail below an hour' => [61, 5, '01:01.05'],
            'hundredths tail wide' => [3661, 99, '1:01:01.99'],
            'hundredths zero kept' => [1, 0, '00:01.00'],
        ];
    }

    /**
     * @dataProvider faceProvider
     */
    public function testFacesPaintTheExactHistoricBytes(int $totalSeconds, ?int $hundredths, string $expected): void
    {
        self::assertSame($expected, DurationFormat::hms($totalSeconds, $hundredths));
    }

    public function testBelowAnHourNeverGrowsAnHourSegment(): void
    {
        // The old branch condition was hours > 0, not >= 1-digit: 59:59
        // stays two segments, 60:00 - the next second - promotes.
        self::assertSame('59:59', DurationFormat::hms(3599));
        self::assertSame('1:00:00', DurationFormat::hms(3600));
    }
}
