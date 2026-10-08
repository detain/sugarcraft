<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Core\Util\Color;

/**
 * candy-top L1: per-bar color resolver on BarChart.
 */
final class BarChartBarColorTest extends TestCase
{
    private const RED = "\x1b[38;2;255;0;0m";

    private static function firstRed(): \Closure
    {
        return static fn(Bar $bar, int $i): ?Color => $i === 0 ? Color::rgb(255, 0, 0) : null;
    }

    public function testVerticalSnapshotColorsOnlyTheResolvedBarBody(): void
    {
        $out = BarChart::new([['a', 1.0], ['b', 2.0]], 3, 3)->withBarColor(self::firstRed())->view();

        $this->assertSame(
            "  █\n"
            . self::RED . "█\x1b[0m █\n"
            . 'a b',
            $out,
        );
    }

    public function testHorizontalSnapshotColorsBodyAndCap(): void
    {
        $out = BarChart::new([['a', 1.0], ['b', 2.0]], 6, 2)
            ->withHorizontal()
            ->withBarColor(self::firstRed())
            ->view();
        $this->assertSame("a " . self::RED . "██\x1b[0m\nb ████", $out);

        $frac = BarChart::new([['a', 0.55], ['b', 1.0]], 6, 2)
            ->withHorizontal()
            ->withFractionalHeights()
            ->withMin(0.0)
            ->withBarColor(self::firstRed())
            ->view();
        // 0.55 × 4 cells = 2.2 → two `█` plus a 2/8 `▎` cap, styled as one run.
        $this->assertStringContainsString(self::RED . "██▎\x1b[0m", $frac);
    }

    public function testPerBarColorsDifferByValue(): void
    {
        $heat = static fn(Bar $bar, int $i): ?Color
            => $bar->value > 1.5 ? Color::rgb(255, 0, 0) : Color::rgb(0, 0, 255);
        $out = BarChart::new([['a', 1.0], ['b', 2.0]], 3, 3)->withBarColor($heat)->view();

        $this->assertStringContainsString(self::RED . "█\x1b[0m", $out);
        $this->assertStringContainsString("\x1b[38;2;0;0;255m█\x1b[0m", $out);
    }

    public function testDefaultAndNullResetAreByteIdentical(): void
    {
        $base = BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 12, 5)->withShowAxis();
        $nil = static fn(Bar $bar, int $i): ?Color => null;

        $this->assertSame($base->view(), $base->withBarColor($nil)->view());
        $reset = $base->withBarColor(self::firstRed())->withBarColor(null);
        $this->assertNull($reset->barColorFn);
        $this->assertSame($base->view(), $reset->view());
    }

    public function testNonColorReturnIsRejectedAtRender(): void
    {
        $bad = static fn(Bar $bar, int $i): mixed => 42;
        $chart = BarChart::new([['a', 1.0]], 3, 3)->withBarColor($bad);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('int given');
        $chart->view();
    }

    public function testWithBarColorIsImmutableAndSurvivesLaterWithers(): void
    {
        $base = BarChart::new([['a', 1.0]], 3, 3);
        $fn = self::firstRed();
        $next = $base->withBarColor($fn);

        $this->assertNotSame($base, $next);
        $this->assertNull($base->barColorFn);
        $this->assertSame($fn, $next->barColorFn);
        $this->assertSame($fn, $next->withBarWidth(2)->barColorFn, 'barWidthCopy keeps the resolver');
        $this->assertSame($fn, $next->withShowAxis()->barColorFn);
    }

    public function testShortAliasMatchesLongForm(): void
    {
        $base = BarChart::new([['a', 1.0], ['b', 2.0]], 3, 3);
        $this->assertSame(
            $base->withBarColor(self::firstRed())->view(),
            $base->barColor(self::firstRed())->view(),
        );
    }
}
