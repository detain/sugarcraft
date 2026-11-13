<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Legend;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Legend\Legend;
use PHPUnit\Framework\TestCase;

final class LegendTest extends TestCase
{
    public function testEmptyLegendRendersEmpty(): void
    {
        $this->assertSame('', Legend::new([])->view());
    }

    public function testDefaultIsBottomWithoutBorder(): void
    {
        // sugar-charts-097: the DEFAULT legend must fit fixed panes — the
        // sidecar overshoot is opt-in.
        $legend = Legend::new([['label' => 'Series A', 'color' => 'red']]);
        $this->assertSame(Position::Bottom, $legend->position);
        $this->assertFalse($legend->showBorder);
        $this->assertStringContainsString('Series A', $legend->view());
    }

    public function testTopPositionRendersAboveChart(): void
    {
        $legend = Legend::new([['label' => 'A', 'color' => 'red']])
            ->withPosition(Position::Top)
            ->withShowBorder(true);
        $out = $legend->view();
        $this->assertStringContainsString('A', $out);
        $this->assertStringContainsString('┌', $out);
        $this->assertStringContainsString('┐', $out);
    }

    public function testBottomPositionRendersBelow(): void
    {
        $legend = Legend::new([['label' => 'B', 'color' => 'green']])
            ->withPosition(Position::Bottom);
        $out = $legend->view();
        $this->assertStringContainsString('B', $out);
    }

    public function testLeftPositionRendersVerticalList(): void
    {
        $legend = Legend::new([
            ['label' => 'A', 'color' => 'red'],
            ['label' => 'B', 'color' => 'blue'],
        ])->withPosition(Position::Left);
        $out = $legend->view();
        $this->assertStringContainsString('A', $out);
        $this->assertStringContainsString('B', $out);
    }

    public function testRightPositionRendersVerticalList(): void
    {
        $legend = Legend::new([
            ['label' => 'X', 'color' => 'cyan'],
            ['label' => 'Y', 'color' => 'magenta'],
        ])->withPosition(Position::Right);
        $out = $legend->view();
        $this->assertStringContainsString('X', $out);
        $this->assertStringContainsString('Y', $out);
    }

    public function testCustomIndicatorChar(): void
    {
        $legend = Legend::new([['label' => 'Test', 'color' => 'red']])
            ->withIndicatorChar('●');
        $out = $legend->view();
        // Should contain the custom char wrapped in ANSI color
        $this->assertStringContainsString('●', $out);
    }

    public function testShowBorderToggle(): void
    {
        $right = static fn (bool $border): string => Legend::new([['label' => 'A', 'color' => 'red']])
            ->withPosition(\SugarCraft\Charts\Chart\Position::Right)
            ->withShowBorder($border)
            ->view();
        $this->assertStringContainsString('│', $right(true));
        $this->assertStringNotContainsString('│', $right(false));
    }

    public function testMultipleItemsWithDifferentColors(): void
    {
        $legend = Legend::new([
            ['label' => 'Red Series', 'color' => 'red'],
            ['label' => 'Green Series', 'color' => 'green'],
            ['label' => 'Blue Series', 'color' => 'blue'],
        ]);
        $out = $legend->view();
        $this->assertStringContainsString('Red Series', $out);
        $this->assertStringContainsString('Green Series', $out);
        $this->assertStringContainsString('Blue Series', $out);
    }

    public function testToStringMagicMethod(): void
    {
        $legend = Legend::new([['label' => 'Test', 'color' => 'red']]);
        $this->assertSame($legend->view(), (string) $legend);
    }

    public function testFluentInterface(): void
    {
        $legend = Legend::new()
            ->withItems([['label' => 'A', 'color' => 'red']])
            ->withPosition(Position::Top)
            ->withIndicatorChar('■')
            ->withShowBorder(true);

        $this->assertStringContainsString('A', $legend->view());
        $this->assertStringContainsString('■', $legend->view());
    }

    /**
     * Audit F5: borders are sized by DISPLAY width; with the old
     * mb_strlen over ANSI the frame grew by one en-dash per escape byte.
     * (The separator's dash count equals the walled content row's
     * display width — the same structural geometry the ASCII path always
     * had, now escape-blind.)
     *
     * lib-reviews-1103: the side box is now column-rectangular — the two
     * side bars sit at the row ends, so the dash run spans the content
     * row width minus those bars.
     */
    public function testBorderWidthIgnoresAnsiEscapes(): void
    {
        $out = Legend::new([['label' => 'Alpha', 'color' => 'red']])
            ->withPosition(\SugarCraft\Charts\Chart\Position::Right)
            ->withShowBorder(true)
            ->view();
        $rows = explode("\n", $out);
        $borderRun = substr_count($rows[0], "\u{2500}");
        self::assertSame(\SugarCraft\Core\Util\Width::string($rows[1]) - 2, $borderRun);
    }

    /**
     * Audit F24: a 3-digit shorthand hex colors the indicator instead of
     * silently falling back to unstyled output.
     */
    public function testThreeDigitHexColorsIndicator(): void
    {
        $out = Legend::new([['label' => 'Alpha', 'color' => '#abc']])->view();
        self::assertStringContainsString('38;2;170;187;204', $out);
    }
}
