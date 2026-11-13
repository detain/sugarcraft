<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Settings\SettingsTabStrip;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

final class SettingsTabStripTest extends TestCase
{
    public function testEmptyLabelsOrNoWidthRenderNothing(): void
    {
        self::assertSame(['', []], SettingsTabStrip::render([], 0, 80, Theme::dark()));
        self::assertSame(['', []], SettingsTabStrip::render(['A'], 0, 0, Theme::dark()));
    }

    public function testEveryTabFitsWithSeparatorsAndExactSpans(): void
    {
        [$line, $spans] = SettingsTabStrip::render(['A', 'BB', 'C'], 0, 80, Theme::dark());

        self::assertSame(' A │ BB │ C ', Ansi::strip($line));
        self::assertSame([[0, 3, 0], [4, 8, 1], [9, 12, 2]], $spans);
    }

    public function testSpansIndexThePaintedColumns(): void
    {
        [$line, $spans] = SettingsTabStrip::render(['Alpha', 'Beta'], 1, 80, Theme::dark());
        $plain = Ansi::strip($line);

        foreach ($spans as [$from, $to, $i]) {
            self::assertSame(' ' . ['Alpha', 'Beta'][$i] . ' ', mb_substr($plain, $from, $to - $from));
        }
    }

    public function testANarrowStripScrollsToKeepTheActiveTabAndMarksBothEdges(): void
    {
        $labels = ['One', 'Two', 'Three', 'Four', 'Five'];

        [$line, $spans] = SettingsTabStrip::render($labels, 2, 14, Theme::dark());
        $plain = Ansi::strip($line);

        self::assertLessThanOrEqual(14, Width::string($plain));
        self::assertContains(2, array_column($spans, 2), 'the active tab is always painted');
        self::assertStringContainsString('Three', $plain);
    }

    public function testScrolledPastTheStartShowsALeadingMarkerAndOffsetsSpans(): void
    {
        [$line, $spans] = SettingsTabStrip::render(['One', 'Two', 'Three', 'Four'], 3, 12, Theme::dark());
        $plain = Ansi::strip($line);

        self::assertStringStartsWith('‹', $plain);
        self::assertSame(1, $spans[0][0], 'spans start after the one-cell marker');
        self::assertSame(3, end($spans)[2]);
    }

    public function testTruncatedTailShowsATrailingMarker(): void
    {
        [$line, $spans] = SettingsTabStrip::render(['One', 'Two', 'Three', 'Four'], 0, 12, Theme::dark());
        $plain = Ansi::strip($line);

        self::assertStringEndsWith('›', $plain);
        self::assertLessThan(4, count($spans));
    }

    public function testTheActiveTabIsStyledDifferentlyFromTheRest(): void
    {
        [$line] = SettingsTabStrip::render(['A', 'B'], 1, 40, Theme::dark());

        self::assertNotSame(Ansi::strip($line), $line, 'styled output carries escapes');
        self::assertStringContainsString("\e[", $line);
    }
}
