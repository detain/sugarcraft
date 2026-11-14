<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use SugarCraft\Dashboard\Components\Modal\Modal;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;
use PHPUnit\Framework\TestCase;

final class ModalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testModalImplementsSizer(): void
    {
        $modal = Modal::new('Test content');
        $this->assertInstanceOf(Sizer::class, $modal);
    }

    public function testModalImplementsItem(): void
    {
        $modal = Modal::new('Test content');
        $this->assertInstanceOf(Item::class, $modal);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $modal = Modal::new('Test content');
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $modal = Modal::new('Hello World');
        $rendered = $modal->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $modal = Modal::new('Test');
        $rendered = $modal->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $modal = Modal::new('Content')->withTitle('Title');
        $rendered = $modal->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testTitledFactory(): void
    {
        $modal = Modal::titled('Content', 'My Title');
        $rendered = $modal->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $modal = Modal::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $modal->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $modal = Modal::new('Test')
            ->withBgColor(Color::ansi(9));
        $rendered = $modal->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $modal = Modal::new('Test')->withStyle('double');
        $rendered = $modal->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $modal = Modal::new('Test')->withStyle('rounded');
        $rendered = $modal->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $modal = Modal::new('Test')->withStyle('bold');
        $rendered = $modal->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Close button
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowClose(): void
    {
        $modal = Modal::new('Test')->withShowClose(true);
        $rendered = $modal->render();

        $this->assertStringContainsString('×', $rendered);
    }

    public function testWithCloseLabel(): void
    {
        $modal = Modal::new('Test')->withCloseLabel('[X]');
        $rendered = $modal->render();

        $this->assertStringContainsString('[X]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Modal::new('Test');
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $modal = Modal::new('Test content')->setSize(80, 24);
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Modal::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Modal::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $modal = Modal::new('Test');
        [$w, $h] = $modal->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithExplicitSize(): void
    {
        $modal = Modal::new('Test')->setSize(100, 30);
        [$w, $h] = $modal->innerSize();

        $this->assertGreaterThanOrEqual(20, $w);
        $this->assertGreaterThanOrEqual(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $modal = Modal::new('');
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $modal = Modal::new('This is a very long content that should wrap within the modal to test word wrapping.');
        $modal = $modal->setSize(50, 15);
        $rendered = $modal->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $modal = Modal::new('日本語コンテンツ');
        $modal = $modal->setSize(40, 15);
        $rendered = $modal->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell-accurate frame assembly (D-67, findings 061/166 truth leg)
    // ═══════════════════════════════════════════════════════════════

    public function testCJKContentRespectsTheCellBudget(): void
    {
        $modal = Modal::new(str_repeat('日', 20))->setSize(30, 10);
        $rows = explode("\n", $modal->render());

        // Ten allocation rows, each exactly thirty CELLS: the build loop
        // truncates over-wide lines to the modal budget and pads by cells
        // (byte str_pad let wide glyphs overrun the frame).
        $this->assertCount(10, $rows);
        foreach ($rows as $row) {
            $this->assertSame(30, Width::string($row), 'every painted row is exactly the allocation');
        }
    }

    public function testBorderColorOpensAndResetsInsideEveryFrameRow(): void
    {
        $modal = Modal::new('content')->withTitle('T')->setSize(40, 8);
        // new() ships the default purple border; compute its SGR through
        // the hex round trip (display-code instances resolve truecolor).
        $sgr = Color::hex('#874BFD')->toFg(ColorMode::profile());

        $seen = 0;
        foreach (explode("\n", $modal->render()) as $row) {
            if (!preg_match('/[┌┐└┘│]/u', $row)) {
                continue;
            }
            $seen++;
            $this->assertStringContainsString($sgr, $row, 'the border color opens INSIDE every frame row');
            $this->assertStringEndsWith(Ansi::reset(), rtrim($row, ' '), 'and resets before the row ends');
        }
        $this->assertGreaterThan(2, $seen, 'frame rows were inspected');
    }

    public function testUncoloredBorderRowsCarryNoSgr(): void
    {
        // D-68: the bg knob went live, so this uncolored-path pin is
        // re-scoped to FULLY uncolored (no border, no bg); the historic
        // single trailing reset byte is the only escape left.
        $rendered = Modal::new('content')->withBorderColor(null)->withBgColor(null)->setSize(40, 8)->render();

        // The null border path emits no per-row styling; the single ESC
        // occurrence is the pre-existing trailing reset the box always
        // carried (kept byte-stable by D-67 on purpose).
        $this->assertSame(1, substr_count($rendered, "\x1b"));
        foreach (explode("\n", $rendered) as $row) {
            if (preg_match('/[┌┐└┘│]/u', $row)) {
                $this->assertFalse(str_starts_with($row, "\x1b"), 'uncolored frame rows open with the wall, not an SGR');
            }
        }
    }

    public function testOverflowKeepsTheFrameRowsAndDropsInterior(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "Row {$i} of tall content";
        }
        $modal = Modal::new(implode("\n", $lines))->withTitle('Headline')->setSize(40, 8);
        $rendered = $modal->render();

        // modalH(8) = 6: the box keeps its top border (with the title) and
        // its bottom border, dropping interior content rows instead of the
        // old array_shift loop that ate the top border and title first.
        $this->assertStringContainsString('Headline', $rendered, 'the title row survives overflow');
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('└', $rendered);
        $this->assertStringContainsString('Row 1 of tall content', $rendered, 'the first interior rows are kept');
        $this->assertStringNotContainsString('Row 10 of tall content', $rendered, 'the overflow tail is dropped');
        $rows = explode("\n", $rendered);
        $this->assertCount(8, $rows);
        $boxRows = array_values(array_filter($rows, static fn(string $r): bool => (bool) preg_match('/[┌└│]/u', $r)));
        $this->assertCount(6, $boxRows, 'the box occupies exactly modalH rows');
    }

    public function testWithersCarryTheSetSizeAllocation(): void
    {
        $modal = Modal::new('content')->setSize(50, 12)->withTitle('T')->withBorderColor(Color::hex('#FFFFFF'));

        // D-56/D-77 allocation-drop class: every wither must preserve the
        // stored allocation (innerSize answers modalW/modalH of the SET size,
        // 50*0.8=40, 12*0.8=9), not the natural 40/10 fallback.
        $this->assertSame([40, 9], $modal->innerSize());
        $widths = array_map(static fn(string $r): int => Width::string($r), explode("\n", $modal->render()));
        $this->assertSame(50, max($widths), 'the render still fills the fifty-cell allocation');
    }

    public function testBackgroundColorPaintsTheInteriorCells(): void
    {
        // D-68 (finding 063): the bgColor knob was dead - new() default
        // #1E1E2E must now paint the interior content rows cell-accurately.
        $bgSgr = Color::hex('#1E1E2E')->toBg(ColorMode::profile());
        $modal = Modal::new('Hello')->withBgColor(Color::hex('#1E1E2E'))->setSize(40, 8);
        $sawInterior = false;
        foreach (explode("\n", $modal->render()) as $row) {
            if (str_contains($row, 'Hello')) {
                $this->assertStringContainsString($bgSgr, $row, 'interior carries the bg between the walls');
                $sawInterior = true;
            }
        }
        $this->assertTrue($sawInterior, 'an interior row was inspected');
    }

    public function testBackgroundStaysInertWhenNull(): void
    {
        $rendered = Modal::new('Hello')->withBgColor(null)->setSize(40, 8)->render();
        $this->assertFalse(str_contains($rendered, "\x1b[48;"), 'no bg SGR without a bgColor');
    }
}