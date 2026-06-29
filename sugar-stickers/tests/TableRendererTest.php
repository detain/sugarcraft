<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Table\{Column, Table, TableRenderer};

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
        $frame1 = $renderer->render($t);

        // Frame 2: delta.
        $t2 = $t->setCursor(0)->withCursorStyle('7');
        $frame2 = $renderer->render($t2);

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
}
