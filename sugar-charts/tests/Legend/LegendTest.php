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

    public function testDefaultPositionIsRight(): void
    {
        $legend = Legend::new([['label' => 'Series A', 'color' => 'red']]);
        $out = $legend->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testTopPositionRendersAboveChart(): void
    {
        $legend = Legend::new([['label' => 'A', 'color' => 'red']])
            ->withPosition(Position::Top);
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
        $withBorder = Legend::new([['label' => 'A', 'color' => 'red']])->withShowBorder(true)->view();
        $withoutBorder = Legend::new([['label' => 'A', 'color' => 'red']])->withShowBorder(false)->view();
        $this->assertStringContainsString('│', $withBorder);
        $this->assertStringNotContainsString('│', $withoutBorder);
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
     */
    public function testBorderWidthIgnoresAnsiEscapes(): void
    {
        $out = Legend::new([['label' => 'Alpha', 'color' => 'red']])
            ->withShowBorder(true)
            ->view();
        $rows = explode("\n", $out);
        $borderRun = substr_count($rows[0], "\u{2500}");
        self::assertSame(\SugarCraft\Core\Util\Width::string($rows[1]), $borderRun);
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
