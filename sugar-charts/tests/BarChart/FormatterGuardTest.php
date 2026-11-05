<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;

/**
 * CH-1 (docs-reviews-1104 M15/m14): withDataLabelFormatter() results were
 * cast (string) into both render paths unguarded — only incidentally
 * cleaned by Width::truncate (strips ESC+BEL); bidi overrides and the
 * U+E000/U+E001 zone sentinels survived. dataLabel() now passes every
 * formatter return through UntrustedText::guard() (guard-then-budget,
 * the guardItems() pattern).
 */
final class FormatterGuardTest extends TestCase
{
    public function testHostileDataLabelFormatterCannotReachTheVerticalFrame(): void
    {
        // width 20 → colW 9, so the 8-char guarded payload survives the
        // cell budget and only the control bytes are gone.
        $chart = BarChart::new([['A', 3.0], ['B', 7.0]], 20, 4)
            ->withShowLabels(false)
            ->withDataLabels(true)
            ->withDataLabelFormatter(static fn (Bar $b, int $i): string => "ZQ\x1b[7mtok\x07end");

        $view = $chart->view();

        self::assertStringNotContainsString("\x1b", $view, 'raw CSI leaked through the vertical data label');
        self::assertStringNotContainsString("\x07", $view, 'BEL leaked through the vertical data label');
        self::assertStringContainsString('ZQtokend', $view);
    }

    public function testHostileDataLabelFormatterCannotReachTheHorizontalFrame(): void
    {
        // max 100 keeps the bar at one cell so the whole converted
        // payload — markInvisibleFormatting renders the bidi override as
        // the printable marker '<U+202E>', sentinel deleted — fits the
        // trailing room and only control bytes are gone.
        $chart = BarChart::new([['A', 3.0]], 20, 2)
            ->withHorizontal()
            ->withMax(100.0)
            ->withDataLabels(true)
            ->withDataLabelFormatter(static fn (Bar $b, int $i): string => "\u{202e}acct\u{e000}bal");

        $view = $chart->view();

        self::assertStringNotContainsString("\u{202e}", $view, 'raw bidi override leaked through the horizontal data label');
        self::assertStringNotContainsString("\u{e000}", $view, 'zone sentinel leaked through the horizontal data label');
        self::assertStringContainsString('acctbal', $view);
    }

    public function testCleanDataLabelFormatterIsByteIdentical(): void
    {
        // The 3-column vertical geometry from BarChartLabelsTest with the
        // default label: guards must not disturb ordinary printable output.
        $plain = BarChart::new([['A', 3], ['B', 7]], 10, 4)
            ->withShowLabels(false)
            ->withDataLabels(true)
            ->view();
        $formatted = BarChart::new([['A', 3], ['B', 7]], 10, 4)
            ->withShowLabels(false)
            ->withDataLabels(true)
            ->withDataLabelFormatter(static fn (Bar $b, int $i): string => (string) $b->value)
            ->view();

        self::assertSame($plain, $formatted, 'guard changed byte-identical clean formatter output');
    }
}
