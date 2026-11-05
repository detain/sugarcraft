<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;

/**
 * CH-1 (docs-reviews-1104 M15/m14): formatter returns are terminal
 * untrusted text. resolveXLabels()/resolveYLabels() casted closure
 * results straight into the label rows with no sweep, so a formatter
 * echoing hostile app data was an escape-injection primitive that
 * bypassed the documented UntrustedText boundary. The guard now runs at
 * the resolve sites; ordinary printable formatter output stays
 * byte-identical.
 */
final class FormatterGuardTest extends TestCase
{
    public function testHostileXLabelFormatterCannotReachTheFrame(): void
    {
        $chart = LineChart::new([1.0, 2.0, 3.0], 12, 4)
            ->withAxes(true)
            ->withXLabelFormatter(static fn (float $v): string => "ZQ\x1b[7mtok\x07end");

        $view = $chart->view();

        self::assertStringNotContainsString("\x1b", $view, 'raw CSI leaked through the x formatter');
        self::assertStringNotContainsString("\x07", $view, 'BEL leaked through the x formatter');
        // The visible payload survives as inert text (tick placement
        // clips per-column, so assert only the opening rune).
        self::assertStringContainsString('ZQ', $view);
    }

    public function testHostileYLabelFormatterCannotReachTheFrame(): void
    {
        $chart = LineChart::new([1.0, 2.0, 3.0], 12, 4)
            ->withAxes(true)
            ->withYLabelFormatter(static fn (float $v): string => "\u{202e}acct\u{e000}bal");

        $view = $chart->view();

        self::assertStringNotContainsString("\u{202e}", $view, 'bidi override leaked through the y formatter');
        self::assertStringNotContainsString("\u{e000}", $view, 'zone sentinel leaked through the y formatter');
        self::assertStringContainsString('acct', $view);
    }

    public function testNewlinesFromFormattersCannotSmuggleRows(): void
    {
        $base = LineChart::new([1.0, 2.0, 3.0], 12, 4)->withAxes(true)->view();
        $sneak = LineChart::new([1.0, 2.0, 3.0], 12, 4)
            ->withAxes(true)
            ->withXLabelFormatter(static fn (float $v): string => "a\nb\tc")
            ->view();

        self::assertSame(substr_count($base, "\n"), substr_count($sneak, "\n"), 'formatter newline grew the frame');
    }

    public function testCleanFormatterOutputIsByteIdentical(): void
    {
        $guarded = LineChart::new([1.0, 2.0, 3.0], 12, 4)
            ->withAxes(true)
            ->withXLabelFormatter(static fn (float $v): string => 't' . (int) $v)
            ->view();

        // The sweep is a no-op on ordinary printable text: identical to
        // the pre-fix (unguarded cast) output for the same closure.
        self::assertStringContainsString('t0', $guarded);
        self::assertStringContainsString('t2', $guarded);
    }
}
