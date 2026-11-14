<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use SugarCraft\Dashboard\Components\Modal\Drawer;
use SugarCraft\Dashboard\Foundation\ColorMode;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class DrawerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDrawerImplementsSizer(): void
    {
        $drawer = Drawer::new('Test content');
        $this->assertInstanceOf(Sizer::class, $drawer);
    }

    public function testDrawerImplementsItem(): void
    {
        $drawer = Drawer::new('Test content');
        $this->assertInstanceOf(Item::class, $drawer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $drawer = Drawer::new('Test content');
        $rendered = $drawer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $drawer = Drawer::new('Hello World');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $drawer = Drawer::new('Test');
        $rendered = $drawer->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Position handling
    // ═══════════════════════════════════════════════════════════════

    public function testLeftPosition(): void
    {
        $drawer = Drawer::left('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testRightPosition(): void
    {
        $drawer = Drawer::right('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testTopPosition(): void
    {
        $drawer = Drawer::top('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testBottomPosition(): void
    {
        $drawer = Drawer::bottom('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testWithPosition(): void
    {
        $drawer = Drawer::new('Content')->withPosition(Drawer::POSITION_RIGHT);
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithSize(): void
    {
        $drawer = Drawer::new('Content')->withSize(30);
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Drawer::new('Test');
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('double');
        $rendered = $drawer->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('rounded');
        $rendered = $drawer->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('bold');
        $rendered = $drawer->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $drawer = Drawer::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $drawer->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Drawer::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Drawer::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    public function testWithTitle(): void
    {
        // Finding sugar-dashboard-062 (D-71): this pin was a false witness -
        // it rendered a titled drawer but asserted only that CONTENT
        // survived, never that the title was painted (it never was).
        $drawer = Drawer::new('Content')->withTitle('Drawer Title');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
        $this->assertStringContainsString('Drawer Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $drawer = Drawer::new('Test');
        [$w, $h] = $drawer->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithExplicitSize(): void
    {
        $drawer = Drawer::new('Test')->setSize(80, 24);
        [$w, $h] = $drawer->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $drawer = Drawer::new('');
        $rendered = $drawer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $drawer = Drawer::new('This is a very long content that should wrap within the drawer to test word wrapping.');
        $drawer = $drawer->setSize(30, 15);
        $rendered = $drawer->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $drawer = Drawer::new('日本語コンテンツ');
        $rendered = $drawer->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // D-71 (finding sugar-dashboard-062): title/footer emission,
    // per-row border coloring, collapsed twins, allocation carry
    // ═══════════════════════════════════════════════════════════════

    private function topBox(?string $title = null, ?string $footer = null): Drawer
    {
        $drawer = Drawer::new('Body')
            ->setSize(40, 6)
            ->withPosition(Drawer::POSITION_TOP)
            ->withSize(6)
            ->withBorderColor(null);
        if ($title !== null) {
            $drawer = $drawer->withTitle($title);
        }
        if ($footer !== null) {
            $drawer = $drawer->withFooter($footer);
        }
        return $drawer;
    }

    public function testTitlePaintsTheHeaderRow(): void
    {
        $rows = explode("\n", $this->topBox('Drawer Title')->render());

        $this->assertCount(6, $rows);
        $this->assertStringContainsString('Drawer Title', $rows[1]);
        $this->assertStringStartsWith('│', $rows[1]);
        $this->assertStringEndsWith('│', $rows[1]);
        $this->assertSame(40, Width::string($rows[1]));
    }

    public function testFooterPaintsAboveTheBottomFrame(): void
    {
        $rows = explode("\n", $this->topBox(null, 'Saved 12:04')->render());

        $this->assertCount(6, $rows);
        $this->assertStringContainsString('Saved 12:04', $rows[4]);
        $this->assertStringStartsWith('└', $rows[5]);
    }

    public function testTitleAndFooterBothReserveBoxRows(): void
    {
        $rows = explode("\n", $this->topBox('Chrome', 'Save | Cancel')->render());

        // frame 2 + title 1 + footer 1 leaves a content budget of 2.
        $this->assertCount(6, $rows);
        $this->assertStringContainsString('Chrome', $rows[1]);
        $this->assertStringContainsString('Body', $rows[2]);
        $this->assertStringContainsString('Save | Cancel', $rows[4]);
        foreach ($rows as $row) {
            $this->assertSame(40, Width::string($row));
        }
    }

    public function testOverlongTitleIsCellTruncated(): void
    {
        $drawer = $this->topBox('A title far wider than the forty-cell interior budget could ever show');
        foreach (explode("\n", $drawer->render()) as $row) {
            $this->assertSame(40, Width::string($row));
        }
    }

    public function testBorderColorOpensAndResetsInsideEveryRow(): void
    {
        $drawer = Drawer::new('Body')
            ->setSize(40, 6)
            ->withPosition(Drawer::POSITION_TOP)
            ->withSize(6)
            ->withTitle('Chrome');
        $rendered = $drawer->render();
        $sgr = Color::hex('#874BFD')->toFg(ColorMode::profile());
        $end = Ansi::reset();

        // Pre-cure this count was ZERO: the SGR prepend was clobbered by
        // the twin branch re-assignment before any row was painted.
        $this->assertSame(6, substr_count($rendered, $sgr));
        $rows = explode("\n", $rendered);
        foreach (array_slice($rows, 0, 5) as $row) {
            $this->assertStringStartsWith($sgr, $row);
            $this->assertStringEndsWith($end, $row);
        }
        $this->assertStringStartsWith($sgr, $rows[5]);
    }

    public function testUncoloredDrawerKeepsHistoricBytes(): void
    {
        $rendered = $this->topBox('Chrome')->render();

        $this->assertSame(1, substr_count($rendered, "\x1b["));
        $this->assertStringStartsWith('┌', $rendered);
        $this->assertCount(6, explode("\n", $rendered));
    }

    public function testAllFourPositionsRenderFromOneBuilder(): void
    {
        $class = new \ReflectionClass(Drawer::class);
        // hasMethod sees PRIVATE methods - get_class_methods would not, and
        // a pin that cannot observe the twins' return is vacuous.
        $this->assertFalse($class->hasMethod('renderVerticalDrawer'), 'twin collapse: finding 062');
        $this->assertFalse($class->hasMethod('renderHorizontalDrawer'), 'twin collapse: finding 062');
        $this->assertTrue($class->hasMethod('renderBox'), 'twin collapse: finding 062');

        foreach ([Drawer::POSITION_LEFT, Drawer::POSITION_RIGHT, Drawer::POSITION_TOP, Drawer::POSITION_BOTTOM] as $position) {
            $drawer = Drawer::new('X')
                ->setSize(40, 8)
                ->withPosition($position)
                ->withBorderColor(null);
            [$w, $h] = $drawer->innerSize();
            $rows = explode("\n", $drawer->render());
            $this->assertCount($h, $rows, "row budget for position $position");
            foreach ($rows as $row) {
                $this->assertSame($w, Width::string($row), "row width for position $position");
            }
        }
    }

    public function testWithersCarryTheAllocation(): void
    {
        $drawer = Drawer::new('C')->setSize(50, 12)->withBorderColor(null);
        $expected = $drawer->innerSize();
        $this->assertSame([15, 12], $expected);

        $variants = [
            $drawer->withContent('D'),
            $drawer->withPosition(Drawer::POSITION_RIGHT),
            $drawer->withTitle('T'),
            $drawer->withBorderColor(Color::ansi(9)),
            $drawer->withStyle('double'),
            $drawer->withFooter('F'),
        ];
        foreach ($variants as $variant) {
            $this->assertSame($expected, $variant->innerSize());
        }
        // withSize changes the cross-axis on purpose; the carried height
        // must still survive the rebuild.
        $this->assertSame([20, 12], $drawer->withSize(20)->innerSize());
    }

    public function testFloorBoxWithChromeNeverThrows(): void
    {
        $rendered = Drawer::new('Some longish content here')
            ->withTitle('T')
            ->withFooter('F')
            ->withBorderColor(null)
            ->setSize(3, 3)
            ->render();

        $rows = explode("\n", $rendered);
        $this->assertGreaterThanOrEqual(5, count($rows));
        $this->assertSame(3, Width::string($rows[0]));
        $this->assertSame(3, Width::string($rows[count($rows) - 1]));
    }
}
