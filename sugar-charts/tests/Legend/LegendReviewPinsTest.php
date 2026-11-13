<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Legend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Core\Util\Width;

/**
 * Pins for the lib-reviews-1103 Legend fixes: the bordered side legends
 * now render closed, uniform-width boxes (previously ├/┤ tee junctions
 * left the top and bottom open and short labels ragged the frame), and
 * the NO_COLOR family suppresses the legend's raw CSI color emission
 * (the legend bypassed sugar-style's profile machinery entirely).
 */
final class LegendReviewPinsTest extends TestCase
{
    /** @var list<string> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR'] as $key) {
            $this->envBackup[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    /** @param list<array{label:string,color?:string}> $items */
    private function side(array $items, Position $position): string
    {
        return Legend::new($items)->withPosition($position)->withShowBorder(true)->view();
    }

    public function testBorderedSideBoxesAreClosedRectanglesOfUniformWidth(): void
    {
        $items = [
            ['label' => 'Series A', 'color' => 'blue'],
            ['label' => 'B', 'color' => 'red'],
        ];
        foreach ([Position::Left, Position::Right] as $position) {
            $rows = explode("\n", $this->side($items, $position));
            $this->assertCount(4, $rows);
            $this->assertStringStartsWith('┌', $rows[0]);
            $this->assertStringEndsWith('┐', $rows[0]);
            $this->assertStringStartsWith('└', $rows[3]);
            $this->assertStringEndsWith('┘', $rows[3]);
            $this->assertStringStartsWith('│', $rows[1]);
            $this->assertStringEndsWith('│', $rows[1]);

            // Every row — border included — spans identical display cells,
            // measured with escapes stripped (the escape bytes must not
            // count toward the frame).
            $widths = array_map(
                static fn (string $row): int => Width::string(preg_replace('/\x1b\[[0-9;]*m/', '', $row) ?? $row),
                $rows,
            );
            $this->assertSame([$widths[0], $widths[0], $widths[0], $widths[0]], $widths);

            // Corners are box corners, not tee junctions.
            $this->assertStringNotContainsString('├', $rows[0]);
            $this->assertStringNotContainsString('┤', $rows[0]);
            $this->assertStringNotContainsString('├', $rows[3]);
            $this->assertStringNotContainsString('┤', $rows[3]);
        }
    }

    public function testRightSideBoxPadsContentBothSides(): void
    {
        $rows = explode("\n", $this->side(
            [['label' => 'AB', 'color' => 'blue']],
            Position::Right,
        ));
        // Right legend breathes: '│ AB │' style interior.
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rows[1]) ?? $rows[1];
        $this->assertStringStartsWith('│ ', $stripped);
        $this->assertStringEndsWith(' │', $stripped);
    }

    public function testNoColorSuppressesLegendColorEmission(): void
    {
        putenv('NO_COLOR=1');
        $view = $this->side([['label' => 'A', 'color' => 'blue']], Position::Left);
        $this->assertStringNotContainsString("\x1b[34m", $view);
        $this->assertStringNotContainsString("\x1b[39m", $view);
        // The block itself is still drawn, just unstyled.
        $this->assertStringContainsString('█', $view);
        // Hex paths are suppressed too, not just named colors.
        $hex = $this->side([['label' => 'A', 'color' => '#ff00ff']], Position::Left);
        $this->assertStringNotContainsString("\x1b[38;", $hex);
    }

    public function testEmptyNoColorValueDoesNotSuppress(): void
    {
        // Fleet-canonical reading (mirrors sugar-core EnvColors and the
        // candy-mdview trilemma): an EMPTY NO_COLOR counts as unset; a
        // non-empty value of anything disables color.
        putenv('NO_COLOR=');
        $view = $this->side([['label' => 'A', 'color' => 'blue']], Position::Left);
        $this->assertStringContainsString("\x1b[34m", $view);
    }

    public function testClicolorZeroSuppresses(): void
    {
        putenv('CLICOLOR=0');
        $view = $this->side([['label' => 'A', 'color' => 'blue']], Position::Left);
        $this->assertStringNotContainsString("\x1b[34m", $view);
    }

    public function testClicolorForceBeatsNoColor(): void
    {
        putenv('NO_COLOR=1');
        putenv('CLICOLOR_FORCE=1');
        $view = $this->side([['label' => 'A', 'color' => 'blue']], Position::Left);
        $this->assertStringContainsString("\x1b[34m", $view);
    }

    public function testColorsOnByDefault(): void
    {
        $view = $this->side([['label' => 'A', 'color' => 'blue']], Position::Left);
        $this->assertStringContainsString("\x1b[34m", $view);
    }

    public function testIndicatorCharacterIsGuardedAgainstInjection(): void
    {
        $legend = Legend::new([['label' => 'A', 'color' => 'blue']])
            ->withIndicatorChar("\x1b[2J*");
        $this->assertStringNotContainsString("\x1b[2J", $legend->view());
        $this->assertStringContainsString('*', $legend->view());
    }

    public function testItemLabelsAreGuardedAgainstInjection(): void
    {
        $legend = Legend::new([['label' => "\x1b]8;;http://evil\x07click", 'color' => 'blue']]);
        $view = $legend->view();
        $this->assertStringNotContainsString("\x1b]", $view);
        $this->assertStringNotContainsString("\x07", $view);
        $this->assertStringContainsString('click', $view);
    }
}
