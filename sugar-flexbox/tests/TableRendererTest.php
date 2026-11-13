<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
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

        // First frame is the full table output BYTE-EXACT, not a
        // contains-only proxy for it.
        $this->assertSame($t->render(), $output, 'first frame equals the full frame');
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

        // Frame 3: full again after reset — identity, not a length proxy.
        $frame3 = $renderer->render($t2);
        $this->assertSame($t2->render(), $frame3, 'after reset the frame is the full table');

        $fresh = new TableRenderer();
        $this->assertSame($t2->render(), $fresh->render($t2), 'a fresh renderer emits the full table');
    }

        /**
     * Cells a delta frame clears (ECH coverage and blank writes), as
     * [col, row] 0-based pairs. Parses the encoder's own grammar: CUP sets
     * the cursor, ECH blanks from it without advancing, plain characters
     * write and step right.
     *
     * @return list<array{int,int}>
     */
    private static function clearedCells(string $frame, int $width, int $height): array
    {
        $cleared = [];
        $pattern = '/\x1b\[(?<r>\d+);(?<c>\d+)H|\x1b\[(?<n>\d+)X|(?<lit>[^\x1b]+)/';
        if (preg_match_all($pattern, $frame, $tokens, PREG_SET_ORDER)) {
            $col = 0;
            $row = 0;
            foreach ($tokens as $tok) {
                if (isset($tok['lit'])) {
                    foreach (mb_str_split($tok['lit'], 1, 'UTF-8') as $ch) {
                        if ($ch === ' ' && $col < $width && $row < $height) {
                            $cleared[] = [$col, $row];
                        }
                        $col++;
                    }
                } elseif (isset($tok['n'])) {
                    for ($i = 0; $i < (int) $tok['n']; $i++) {
                        if ($col < $width && $row < $height) {
                            $cleared[] = [$col, $row];
                        }
                        $col++;
                    }
                } else {
                    $row = (int) $tok['r'] - 1;
                    $col = (int) $tok['c'] - 1;
                }
            }
        }
        return $cleared;
    }

    /**
/**
     * FBX-04: a SHRINK must clear the cells the new frame no longer paints
     * (finding 004 — dropping the previous frame bare left every ghost).
     */
    public function testRendererShrinkInWidthAndHeightClearsGhosts(): void
    {
        $wide = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC'])
            ->addRow(['Bob', 'LA'])
            ->addRow(['Carol', 'SF']);
        $renderer = new TableRenderer();
        $renderer->render($wide); // full frame: 23 cells x 5 rows

        $narrow = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']); // 10 cells x 3 rows

        $delta = $renderer->render($narrow);

        // Every non-blank cell the wide frame painted OUTSIDE the narrow
        // box must be covered by a clear (ECH run or blank write) in the
        // delta — that is the ghost law, regardless of how the encoder
        // groups the runs.
        $cleared = self::clearedCells($delta, 23, 5);
        foreach (explode("\n", $wide->render()) as $r => $line) {
            foreach (mb_str_split($line, 1, 'UTF-8') as $c => $ch) {
                if ($ch === ' ' || ($c < 10 && $r < 3)) {
                    continue; // blank paints nothing; in-box survivors are not ghosts
                }
                $this->assertContains([$c, $r], $cleared, "ghost cell ({$c},{$r}) '{$ch}' must be cleared");
            }
        }
        // Surviving in-box cells are NOT re-painted (no full-frame proxy):
        // the header row is unchanged, so its text must not ride the delta.
        $this->assertStringNotContainsString('Name', $delta, 'survivors stay painted');
        $this->assertStringNotContainsString('Carol', $delta);
    }

    public function testRendererShrinkInWidthOnlyClearsPerRow(): void
    {
        $three = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addColumn(Column::make('C', 5));
        $three = $three->addRow(['1', '2', '3']);
        $renderer = new TableRenderer();
        $renderer->render($three);

        $two = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5));
        $delta = $renderer->render($two->addRow(['1', '2']));

        $narrowW = \SugarCraft\Core\Util\Width::string(explode("\n", $two->render())[0]);
        $cleared = self::clearedCells($delta, 21, 3);
        foreach (explode("\n", $three->render()) as $r => $line) {
            foreach (mb_str_split($line, 1, 'UTF-8') as $c => $ch) {
                if ($ch === ' ' || $c < $narrowW) {
                    continue;
                }
                $this->assertContains([$c, $r], $cleared, "ghost cell ({$c},{$r}) '{$ch}' must be cleared");
            }
        }
    }

    public function testRendererGrowthStillEmitsFullFrame(): void
    {
        // Growth has nothing outside the box to clear — the plain full
        // re-emit law (and the pinned resize behaviour) stays.
        $t = (new Table())->addColumn(Column::make('Name', 10))->addRow(['Alice']);
        $renderer = new TableRenderer();
        $renderer->render($t);
        $t2 = $t->addRow(['Bob'])->addRow(['Carol']);
        $this->assertSame($t2->render(), $renderer->render($t2), 'growth re-emits the full frame');
    }

    public function testRendererShrinkThenSameSizeKeepsStreamingDeltas(): void
    {
        $wide = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC'])
            ->addRow(['Bob', 'LA']);
        $renderer = new TableRenderer();
        $renderer->render($wide);

        $narrow = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);
        $renderer->render($narrow); // shrink frame (delta + clears)

        $second = $narrow->addRow(['Bob']); // same dims as narrow: content delta
        $frame = $renderer->render($second);
        $this->assertStringContainsString('Bob', $frame, 'the new row rides a delta');
        $this->assertStringNotContainsString('NYC', $frame, 'untouched cells are not re-painted');
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

    /**
     * DiffEncoder opens every stream with a home CUP, even an empty one
     * (216b32c12); an op-less delta must still be ZERO bytes, not trim()-empty.
     */
    public function testUnchangedFrameEmitsZeroBytesNotAHomeAnchor(): void
    {
        $t = (new Table())->addColumn(Column::make('Name', 10))->addRow(['Alice']);
        $renderer = new TableRenderer();
        $renderer->render($t);
        self::assertSame('', $renderer->render($t));
        self::assertSame('', $renderer->render($t));
    }

    // ---- style attribution correctness (byte-vs-cluster + accumulation) --

    /** @return \SugarCraft\CellBuffer\Buffer */
    private function gridFrom(string $line, int $w, int $h): object
    {
        $m = new \ReflectionMethod(TableRenderer::class, 'bufferFromOutput');
        $m->setAccessible(true);
        // bufferFromOutput takes the rendered LINES list (FBX-16): one line
        // in, a one-line canvas out.
        return $m->invoke(new TableRenderer(), [$line], $w, $h);
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
        // A width-0 grid has no cells to address: the renderer answers
        // empty even mid-stream, and recovers on the next real frame.
        $renderer = new TableRenderer();
        $this->assertSame('', $renderer->render(Table::new()));
        // And after a real frame, collapsing to zero columns must not fatal
        // the frame loop either.
        $renderer->render(Table::new()->addColumn(Column::new('A', 3))->addRow(['x']));
        $this->assertSame('', $renderer->render(Table::new()));
    }

    public function testMemoizedCursorSweepEmitsIdenticalDeltasToFresh(): void
    {
        // The Table line memo (FBX-10) is invisible to the delta encoder:
        // a chain that accumulates memo history emits the exact ops of a
        // twin chain built fresh per frame — otherwise cached vs computed
        // lines would differ inside the diff and corrupt long sessions.
        $base = static function (): Table {
            $t = Table::new()
                ->withColumn(Column::new('A', 5))
                ->withColumn(Column::new('B', 5))
                ->withCursorStyle('7');
            foreach ([['one', 'two'], ['three', 'four'], ['five', 'six'], ['seven', 'eight']] as $r) {
                $t = $t->withRow($r);
            }
            return $t;
        };

        $swept = $base();
        $renMemo = new TableRenderer();
        $renFresh = new TableRenderer();
        foreach ([0, 2, 1, 3, 0] as $cursor) {
            $swept = $swept->withCursor($cursor);
            $this->assertSame(
                $renFresh->render($base()->withCursor($cursor)),
                $renMemo->render($swept),
                "cursor {$cursor} delta"
            );
        }
    }

    /**
     * FBX-17 oracle: the preg_split walk answers exactly what the old
     * byte-indexed walk answered, cluster for cluster and style for style,
     * across the corpus AND the malformed shapes the old scan swallowed by
     * consuming every CSI run to its first alphabetic terminator (junk
     * bodies stay off the grid without touching SGR state).
     *
     * @return array{list<string>, list<\SugarCraft\CellBuffer\Style|null>}
     */
    private function splitLine(string $line): array
    {
        $m = new \ReflectionMethod(TableRenderer::class, 'splitStyledLine');
        $m->setAccessible(true);
        /** @var array{list<string>, list<\SugarCraft\CellBuffer\Style|null>} $r */
        $r = $m->invoke(null, $line);
        return $r;
    }

    public function testSplitStyledLineKeepsTheOldWalkContract(): void
    {
        $bold = \SugarCraft\CellBuffer\Style::new(null, null, \SugarCraft\CellBuffer\Style::ATTR_BOLD);
        $red = \SugarCraft\CellBuffer\Style::new(0x800000, null, 0);
        $boldRed = \SugarCraft\CellBuffer\Style::new(0x800000, null, \SugarCraft\CellBuffer\Style::ATTR_BOLD);

        [$g, $s] = $this->splitLine('');
        $this->assertSame([], $g);
        $this->assertSame([], $s);

        [$g, $s] = $this->splitLine("A\x1b[1mB\x1b[0mC");
        $this->assertSame(['A', 'B', 'C'], $g);
        $this->assertNull($s[0]);
        $this->assertEquals($bold, $s[1]);
        $this->assertNull($s[2]);

        [$g, $s] = $this->splitLine("A\x1b[31mRED\x1b[0m");
        $this->assertSame(['A', 'R', 'E', 'D'], $g);
        $this->assertNull($s[0]);
        $this->assertEquals($red, $s[1]);
        $this->assertEquals($red, $s[3]);

        [$g, $s] = $this->splitLine("A\x1b[2Jb");
        $this->assertSame(['A', 'b'], $g, 'mid-line CSI paints nothing');
        $this->assertNull($s[0]);
        $this->assertNull($s[1]);

        [$g, $s] = $this->splitLine("A\x1b[31");
        $this->assertSame(['A'], $g, 'a truncated CSI tail is consumed, never cells');
        $this->assertNull($s[0]);

        [$g, $s] = $this->splitLine("A\x1b[1!mB");
        $this->assertSame(['A', 'B'], $g, 'a junk-body CSI run is consumed whole…');
        $this->assertNull($s[0]);
        $this->assertNull($s[1], '…and never touches SGR state');

        [$g, $s] = $this->splitLine("A\x1b[1!4BC");
        $this->assertSame(['A', 'C'], $g, 'old scan ran the terminator to the FIRST letter');
        $this->assertNull($s[0]);
        $this->assertNull($s[1]);

        [$g, $s] = $this->splitLine("A\x1b[38:2:0:1:2:3mB");
        $this->assertSame(['A', 'B'], $g, 'colon sub-params are invisible at the split');
        $this->assertNull($s[1]);

        [$g, $s] = $this->splitLine("X\x1b");
        $this->assertSame(["X", "\x1b"], $g, 'a bare ESC is a zero-width literal, as always');
        $this->assertNull($s[0]);
        $this->assertNull($s[1]);

        [$g, $s] = $this->splitLine("a│b\x1b[7mT");
        $this->assertSame(['a', '│', 'b', 'T'], $g, 'multibyte clusters never shift attribution');
        $this->assertNull($s[1]);

        [$g, $s] = $this->splitLine("\x1b[1m\x1b[31mX");
        $this->assertSame(['X'], $g);
        $this->assertEquals($boldRed, $s[0], 'attributes accumulate across back-to-back SGRs');
    }

    public function testSplitStyledLineWalksEveryRenderedCorpusLine(): void
    {
        // Property leg: no line any Table shape can produce — header
        // arrows, styles, markers, separators — may change cluster count
        // or attribution under the new walk: the split must cover every
        // visible cell and leave invisible SGR outside it.
        $t = Table::new()
            ->withColumn(Column::new('Nāme', 6))
            ->withColumn(Column::new('Ci🌆ty', 5)->withStyle('31'))
            ->withColumn(Column::new('Hidden', 0))
            ->withCursorStyle('7')
            ->withCursorMarker('> ')
            ->withSeparator(' │ ')
            ->withSort(0, true);
        $t = $t
            ->withRow(['Alice', 'Berlíén', 'x'])
            ->withRow(['東', '🙂', 'y'])
            ->withRow(['Bob', 'Cairo', 'z'])
            ->withCursor(1);

        foreach ($t->buildLines() as $i => $line) {
            [$g, $s] = $this->splitLine($line);
            $visible = \array_values(\array_filter($g, static fn(string $c): bool => Width::string($c) > 0));
            $this->assertSame(
                Width::string($line),
                \array_sum(\array_map(Width::string(...), $g)),
                "line {$i}: clusters account for every cell of the line"
            );
            $this->assertCount(\count($g), $s);
            $this->assertNotSame([], $visible, "line {$i} carries content clusters");
        }
    }

    // ---- SGR grammar coupling (finding 035/036, FBX-32) -------------------

    public function testSgr28LiftsInvisibleMidLine(): void
    {
        // 8/28 must be symmetric like 9/29: the old table cleared everything
        // except INVISIBLE, a documented asymmetry pinned zero ways.
        $invisible = \SugarCraft\CellBuffer\Style::ATTR_INVISIBLE;
        [$g, $s] = $this->splitLine("A\x1b[8mB\x1b[28mC");
        $this->assertSame(['A', 'B', 'C'], $g);
        $this->assertNull($s[0]);
        $this->assertNotNull($s[1], 'the invisible run materializes a Style');
        $this->assertSame($invisible, $s[1]->attrs() & $invisible);
        $this->assertNull($s[2], '28 lifts 8 without a full reset');
    }

    public function testXterm256MaterializesThroughTheCanonicalPalette(): void
    {
        // 38;5;n / 48;5;n must land on the grid as real colors (the palette
        // mapping is the diff-visible representation), greys and cube arms
        // both exercised — the grey ramp 244 is 8+12*10 = 128³.
        [$g, $s] = $this->splitLine("\x1b[38;5;244mG\x1b[38;5;196mR\x1b[48;5;226mY");
        $this->assertSame(['G', 'R', 'Y'], $g);
        $this->assertEquals(\SugarCraft\CellBuffer\Style::new(0x808080, null, 0), $s[0], 'grey ramp 244');
        $this->assertEquals(\SugarCraft\CellBuffer\Style::new(0xff0000, null, 0), $s[1], 'system color 196');
        $this->assertEquals(\SugarCraft\CellBuffer\Style::new(0xff0000, 0xffff00, 0), $s[2], 'cube 226 bg rides along');
    }

    public function testColonBytesThatBypassTheDoorStayInvisibleToTheParser(): void
    {
        // Coupling half 1: the door canonicalizes, so the parser NEVER has
        // to speak colon grammar. A hand-built line that smuggles a colon
        // past every setter is consumed by the walk but changes no state —
        // identical to the pre-canonicalization contract, pinned so a
        // future "parser colon support" shortcut cannot desync the pair.
        [$g, $s] = $this->splitLine("A\x1b[38:2:0:10:20:30mB");
        $this->assertSame(['A', 'B'], $g);
        $this->assertNull($s[0]);
        $this->assertNull($s[1], 'a colon SGR never reaches applySgr through the door');

        // The door-fed equivalent DOES materialize the same color on the
        // grid (full-frame bytes and parser state agree by construction).
        [$g2, $s2] = $this->splitLine("A\x1b[38;2;10;20;30mB");
        $this->assertEquals(
            \SugarCraft\CellBuffer\Style::new(0x0a141e, null, 0),
            $s2[1],
            'the canonicalized form materializes fg 10,20,30 = 0x0a141e'
        );
    }

    public function testEmittedGrammarAlwaysMatchesTheParserGrammar(): void
    {
        // Coupling half 2 (structural, finding 035's "emitted-SGR set ==
        // parsed set over the corpus"): every escape sequence the library
        // writes into a frame must be exactly the form applySgr reads.
        // Colon grammar may only exist at the door's INPUT side.
        $tables = [
            'plain+styles' => Table::new()
                ->withColumn(Column::new('H', 6)->withStyle('38:5:196'))
                ->withHeaderStyle('1;38:2:0:255:0:0')
                ->withCursorStyle('48:5:244')
                ->withCursorMarker('> ')
                ->withRow(['a'])->withRow(['b'])->withCursor(1),
            'truecolor+basic' => Table::new()
                ->withColumn(Column::new('X', 4)->withStyle('38;2;1;2;3'))
                ->addColumn(Column::new('Y', 4)->withStyle('91'))
                ->withHeaderStyle('4;93')
                ->withRow(['p', 'q']),
            'attrs' => Table::new()
                ->withColumn(Column::new('Z', 4))
                ->withHeaderStyle('8;28;9;29;7;27')
                ->withCursorStyle('2;22')
                ->withRow(['w']),
        ];
        $parserGrammar = '/^\x1b\[([0-9;]*)m$/';
        $seen = 0;
        foreach ($tables as $label => $table) {
            foreach ($table->buildLines() as $line) {
                $this->assertNotNull($this->gridFrom($line, \max(1, $table->computeTotalWidth()), 1), $label . ': every emitted line builds a grid');
                \preg_match_all('/\x1b\[[0-9:;]*m/', $line, $found);
                foreach ($found[0] as $sgr) {
                    $seen++;
                    $this->assertSame(1, \preg_match($parserGrammar, $sgr), $label . ': emitted ' . addcslashes($sgr, "\x1b") . ' is parsable grammar');
                }
            }
        }
        $this->assertGreaterThan(10, $seen, 'the corpus actually carried SGR sequences to compare');
    }
}
