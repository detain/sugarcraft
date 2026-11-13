<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Timing-free complexity guards for the render pass (audit M1).
 *
 * The frame used to be built by chaining {@see \SugarCraft\CellBuffer\Buffer::withCellAt()},
 * copying the whole grid on every cell write: measured ~920ms for a 600-row
 * table, quadratic in rows. The render pass now accumulates cells into one
 * flat {@see \SugarCraft\Table\RenderCanvas} grid and freezes it with a single
 * Buffer::fromGrid(). These pins keep that shape structural, not aspirational:
 * a reintroduced per-write copy loop reddens here, long before anyone times a
 * frame and wonders.
 */
final class TableRenderComplexityTest extends TestCase
{
    /**
     * The table must never write a cell into an immutable Buffer again:
     * every copy site in src/Table.php is a step back toward the quadratic
     * render (the canvas + fromGrid path owns all writes now).
     */
    public function testTableSourceNeverCopiesTheBufferPerCellWrite(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/Table.php');
        self::assertIsString($source);

        self::assertSame(
            0,
            \substr_count($source, '->withCellAt('),
            'src/Table.php must route every cell write through RenderCanvas::put(), not Buffer::withCellAt()'
        );
    }

    /**
     * The canvas is the single mutable staging ground and it freezes exactly
     * once: one Buffer::fromGrid() call in the whole render path. A second
     * fromGrid would mean two full grids in flight (or a silent re-render).
     */
    public function testCanvasFreezesIntoTheBufferExactlyOnce(): void
    {
        $canvas = \file_get_contents(__DIR__ . '/../src/RenderCanvas.php');
        self::assertIsString($canvas);

        self::assertSame(1, \substr_count($canvas, 'Buffer::fromGrid('));

        $table = \file_get_contents(__DIR__ . '/../src/Table.php');
        self::assertIsString($table);
        self::assertSame(
            0,
            \substr_count($table, 'Buffer::fromGrid('),
            'only RenderCanvas::toBuffer() may freeze the grid'
        );
        self::assertSame(1, \substr_count($table, '->toBuffer();'));
        self::assertSame(1, \substr_count($table, 'new RenderCanvas('));
    }

    /**
     * put() is a faithful mutable twin of Buffer's placeWithPair(): a wide
     * lead keeps its continuation, and a real cell over a stranded pair
     * blanks the leftover half (the byte-equivalence harness is the goldens).
     */
    public function testCanvasMirrorsTheWideCellPairInvariant(): void
    {
        $canvas = new \SugarCraft\Table\RenderCanvas(4, 1);
        $wide   = new \SugarCraft\CellBuffer\Cell('あ', null, null, 2);

        $canvas->put(1, 0, $wide);
        self::assertSame($wide, $canvas->at(1, 0));
        self::assertSame(0, $canvas->at(2, 0)?->width());

        // Replacing the lead with a real cell clears the orphaned continuation.
        $canvas->put(1, 0, new \SugarCraft\CellBuffer\Cell('x', null, null, 1));
        self::assertSame(1, $canvas->at(2, 0)?->width());

        $this->expectException(\OutOfBoundsException::class);
        $canvas->put(4, 0, new \SugarCraft\CellBuffer\Cell('!', null, null, 1));
    }

    /**
     * Every shape that used to pay the quadratic cost still renders to the
     * same exact frame the pinned goldens encode (structural pins prove the
     * mechanism; the goldens prove equivalence).
     */
    public function testStyleMemoResetsBetweenRenderPasses(): void
    {
        $t = \SugarCraft\Table\Table::fromColumns([
            \SugarCraft\Table\Column::new('a', 'A', 5),
        ])->withRows([\SugarCraft\Table\Row::new(\SugarCraft\Table\RowData::from(['a' => 'x']))])
            ->withBaseStyle('31');

        self::assertStringContainsString('x', $t->view());
        $red = $t->withBaseStyle('91');
        $this->assertNotSame($t->view(), $red->view(), 'a stale style memo must not leak across passes');
    }
}
