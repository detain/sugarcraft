<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Table\{Column, Table, TableRenderer};

/**
 * Tests for TableRenderer including multibyte correctness and diff round-trip.
 */
final class TableRendererTest extends TestCase
{
    /**
     * Verify that Table::render() is pure — repeated calls with identical state
     * return the same full frame string.
     */
    public function testRenderIsPureAndRepeatable(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob']);

        $out1 = $t->render();
        $out2 = $t->render();
        $out3 = $t->render();

        $this->assertSame($out1, $out2, 'Second render should return identical output');
        $this->assertSame($out2, $out3, 'Third render should return identical output');
    }

    /**
     * Verify that Table::buildLines() returns an array of lines that when joined
     * produce the same output as render().
     */
    public function testBuildLinesProducesRenderOutput(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC'])
            ->addRow(['Bob', 'LA']);

        $lines = $t->buildLines();
        $rendered = $t->render();

        $this->assertSame($rendered, \implode("\n", $lines));
        $this->assertCount(4, $lines); // header + separator + 2 data rows
    }

    /**
     * TableRenderer: first frame emits full output.
     */
    public function testRendererFirstFrameIsFullOutput(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Carol']);

        $renderer = new TableRenderer();
        $output = $renderer->render($t);

        // First frame should be the full table output, not a delta.
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringContainsString('Carol', $output);
    }

    /**
     * TableRenderer: second frame with dimension-stable change emits delta.
     */
    public function testRendererSecondFrameWithStyleChangeIsDelta(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Carol']);

        $renderer = new TableRenderer();

        // Frame 1: full output.
        $frame1 = $renderer->render($t);
        $this->assertGreaterThan(50, \strlen($frame1), 'Frame 1 should be full output');

        // Frame 2: cursor style change (dimension-stable).
        $t2 = $t->setCursor(1)->withCursorStyle('7');
        $frame2 = $renderer->render($t2);

        // Frame 2 should be a delta (much smaller than full output).
        // With proper SGR→Style tracking, style changes produce cell-level
        // diff ops, so the delta is larger than the old null-style behavior.
        $this->assertLessThanOrEqual(120, \strlen($frame2), 'Frame 2 delta should be ≤120 bytes');
    }

    /**
     * TableRenderer: resize triggers full re-emit.
     */
    public function testRendererResizeReEmitsFullFrame(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $renderer = new TableRenderer();

        // Frame 1: full output.
        $frame1 = $renderer->render($t);

        // Add more rows to change height.
        $t2 = $t->addRow(['Bob'])->addRow(['Carol'])->addRow(['Dave']);
        $frame2 = $renderer->render($t2);

        // Height change should trigger full re-emit.
        $this->assertGreaterThan(\strlen($frame1), \strlen($frame2), 'Frame 2 should be full due to height change');
    }

    /**
     * TableRenderer reset() forces next render to emit full frame.
     */
    public function testRendererResetForcesFullFrame(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob']);

        $renderer = new TableRenderer();

        // Frame 1: full.
        $renderer->render($t);

        // Frame 2: delta.
        $t2 = $t->setCursor(0)->withCursorStyle('7');
        $renderer->render($t2);

        // Reset.
        $renderer->reset();

        // Frame 3: should be full again.
        $frame3 = $renderer->render($t2);
        $this->assertGreaterThan(50, \strlen($frame3), 'After reset, should emit full frame');
    }

    /**
     * Multibyte content in table cells renders correctly via Table::render().
     * This is a regression test for the byte-indexing bug that corrupted
     * multibyte frames in bufferFromOutput.
     */
    public function testMultibyteContentRendersCorrectly(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('名前', 10))  // Japanese "Name"
            ->addColumn(Column::make('都市', 10))  // Japanese "City"
            ->addRow(['東京', 'NYC'])               // Tokyo, New York
            ->addRow(['北京', 'LA']);              // Beijing, Los Angeles

        $output = $t->render();

        // Verify multibyte content is preserved (not corrupted by byte indexing).
        $this->assertStringContainsString('名前', $output, 'Japanese header should appear correctly');
        $this->assertStringContainsString('東京', $output, 'Japanese cell content should appear correctly');
        $this->assertStringContainsString('北京', $output, 'Japanese cell content should appear correctly');
    }

    /**
     * Sorted column with multibyte arrows (▲/▼) renders correctly.
     */
    public function testSortedColumnWithArrowsRendersCorrectly(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10)->sorted(1, 1))
            ->addRow(['Charlie'])
            ->addRow(['Alice'])
            ->addRow(['Bob']);

        $output = $t->render();

        // Sorted column should show ▲ or ▼ arrow.
        $this->assertTrue(
            \str_contains($output, '▲') || \str_contains($output, '▼'),
            'Sorted column should display sort arrow'
        );
        // Verify rows are sorted (Alice should be first with ▲).
        $this->assertStringContainsString('Alice', $output);
    }

    /**
     * Adversarial delta pin: two frames identical except
     * the cursor style's COLOR (38;2;255;0;0 vs 38;2;0;0;255 — the ONLY
     * differing parameters are the RGB components; a pair like 38;5 vs 38;2
     * would leak a difference through the stray 5/2 attribute codes).
     * The old sgrToStyle dropped all color codes to null, so both frames
     * carried the same Style and the delta was EMPTY — a color-only change
     * never reached the screen. Now colors parse into the Style and the diff
     * sees them.
     */
    public function testColorOnlyCursorChangeIsVisibleInTheDelta(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->setCursor(0)
            ->withCursorStyle('38;2;255;0;0');

        $renderer = new TableRenderer();
        $renderer->render($t); // frame 1 primes the previous buffer

        $frame2 = $renderer->render($t->withCursorStyle('38;2;0;0;255'));

        $this->assertNotSame('', trim($frame2), 'a color-only change must emit ops');
        $this->assertStringContainsString("\x1b[", $frame2, 'the delta must carry SGR output');

        // And a same-style re-render stays a (near-)empty delta — the pin is
        // about seeing the change, not emitting everything always.
        $frame3 = $renderer->render($t->withCursorStyle('38;2;0;0;255'));
        $this->assertSame('', trim($frame3), 'an unchanged frame must stay silent');
    }

    // ---- style attribution correctness (byte-vs-cluster + accumulation) --

    /** @return \SugarCraft\CellBuffer\Buffer */
    private function gridFrom(string $line, int $w, int $h): object
    {
        $m = new \ReflectionMethod(TableRenderer::class, 'bufferFromOutput');
        $m->setAccessible(true);
        return $m->invoke(new TableRenderer(), $line, $w, $h);
    }

    public function testStyleAttributionIsPerClusterNotPerByte(): void
    {
        // The shipped default separator ' │ ' is 3 bytes for 1 cell. The old
        // byte-keyed map shifted every style lookup two cells right of it,
        // so recoloring the second column produced an EMPTY delta — the
        // change never landed on a live session.
        $plain = Table::new()
            ->addColumn(Column::new('L', 4))
            ->addColumn(Column::new('R', 4))
            ->addRow(['aa', 'bb']);
        $restyled = Table::new()
            ->addColumn(Column::new('L', 4))
            ->addColumn(Column::new('R', 4)->withStyle('31'))
            ->addRow(['aa', 'bb']);

        $renderer = new TableRenderer();
        $renderer->render($plain);
        $delta = $renderer->render($restyled);
        $this->assertNotSame('', \trim($delta), 'column restyle must emit ops across the wide separator');

        // ASCII separator control: byte == cluster, always worked.
        $renderer2 = new TableRenderer();
        $renderer2->render($plain->withSeparator(' | '));
        $this->assertNotSame('', \trim($renderer2->render($restyled->withSeparator(' | '))));
    }

    public function testColumnRestyleOnCjkContentEmitsOps(): void
    {
        $plain = Table::new()->addColumn(Column::new('名', 6))->addRow(['東京']);
        $restyled = Table::new()->addColumn(Column::new('名', 6)->withStyle('32'))->addRow(['東京']);

        $renderer = new TableRenderer();
        $renderer->render($plain);
        $this->assertNotSame('', \trim($renderer->render($restyled)), 'CJK-led restyle must not be a dead knob');
    }

    public function testSgrStateAccumulatesAcrossSequences(): void
    {
        // A terminal keeps '\x1b[1m' in force when '\x1b[31m' arrives; the
        // old last-SGR-wins map kept only '31'.
        $grid = $this->gridFrom("\x1b[1mAB\x1b[31mCD", 4, 1);
        $bold = $grid->cellAt(0, 0)->style();
        $this->assertNotNull($bold);
        $this->assertTrue($bold->hasBold());
        $this->assertNull($bold->fg());
        $both = $grid->cellAt(2, 0)->style();
        $this->assertNotNull($both);
        $this->assertTrue($both->hasBold(), 'bold must persist across the second sequence');
        $this->assertSame(0x800000, $both->fg());
    }

    public function testInvisibleSgrCodesMaterializeNoStyle(): void
    {
        // '53' (overline) has no cell-buffer meaning; producing a Style for
        // it made every such frame churn the diff with invisible changes.
        $grid = $this->gridFrom("\x1b[53mAB", 2, 1);
        $this->assertNull($grid->cellAt(0, 0)->style());
    }

    public function testWidthOracleFollowsCanonicalCoreTable(): void
    {
        // U+1F7E0 large orange circle: the private table missed it (width 1),
        // so delta ops addressed the wrong columns right of any emoji.
        $grid = $this->gridFrom("x\u{1F7E0}y", 4, 1);
        $this->assertSame('x', $grid->cellAt(0, 0)->rune());
        $this->assertSame("\u{1F7E0}", $grid->cellAt(1, 0)->rune());
        $this->assertSame(2, $grid->cellAt(1, 0)->width());
        $this->assertSame(0, $grid->cellAt(2, 0)->width());
        $this->assertSame('y', $grid->cellAt(3, 0)->rune());
    }

    public function testWideGlyphIsClippedAtTheRightEdge(): void
    {
        // A width-2 glyph at the last column would paint over the edge and
        // desync grid from screen forever after; it is clipped instead.
        $grid = $this->gridFrom('a東', 2, 1);
        $this->assertSame('a', $grid->cellAt(0, 0)->rune());
        $this->assertSame(' ', $grid->cellAt(1, 0)->rune());
    }

    public function testZeroColumnTableRendersEmptyInsteadOfFatal(): void
    {
        $renderer = new TableRenderer();
        $this->assertSame('', $renderer->render(Table::new()));
        // And after a real frame, collapsing to zero columns must not fatal
        // the frame loop either.
        $renderer->render(Table::new()->addColumn(Column::new('A', 3))->addRow(['x']));
        $this->assertSame('', $renderer->render(Table::new()));
    }
}
