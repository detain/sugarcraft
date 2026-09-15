<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SplObjectStorage;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\State;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell as RendererCell;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Parser\OscHandlerImpl;
use SugarCraft\Vt\Screen\Screen;
use SugarCraft\Vt\Screen\Scrollback;
use SugarCraft\Vt\Terminal as RendererTerminal;
use SugarCraft\Vt\Terminal\Terminal;
use SugarCraft\Vt\Tests\Support\NullHandler;
use SugarCraft\Vt\Theme;

/**
 * Allocation-growth proof for the grid/Buffer/Screen/Parser surfaces.
 *
 * Every test here is a *boundedness* statement, expressed two ways so that
 * neither a heap leak nor a silently growing data structure can pass:
 *
 *  1. Exact structural counts — grid row counts, per-row cell counts,
 *     distinct live Cell objects, scrollback ring occupancy, parser state —
 *     asserted with `assertSame`, independent of the environment.
 *  2. Settled live-heap measurements — `memory_get_usage()` after
 *     `gc_collect_cycles()` + `gc_mem_caches()`, compared across warm and
 *     cold phases of a long churn loop. A retained reference per iteration
 *     (the failure mode this guards) is megabytes at these grid sizes and
 *     trips the ceiling immediately; allocator bookkeeping slack does not.
 *
 * The churn loops deliberately re-allocate the SAME shapes hundreds of
 * times so an allocator reuse story cannot mask a retention bug either:
 * growth would have to come from objects still reachable from the
 * terminal/buffer/screen/parser, which is exactly what the brief forbids.
 *
 * `SugarCraft\Vt\Grid\Grid` and `ScrollingBuffer`/`FixedBuffer` named in the
 * source audit do not exist in this lib; the real allocation surfaces are
 * {@see Buffer} (emulator path, `Cell\Cell` singleton-filled),
 * {@see CellGrid} (vcr renderer path, per-cell `Vt\Cell`),
 * {@see Screen}/{@see Scrollback} (snapshot + ring), and the candy-ansi
 * {@see Parser} driving {@see ScreenHandler} via {@see Terminal}. The Parser
 * itself has no resize — resize lives on Buffer/CellGrid and is exercised
 * through Terminal::resize(); parser churn is therefore feed()+reset().
 */
final class AllocationTest extends TestCase
{
    /**
     * Live-heap growth ceiling allowed across a full churn phase, in bytes.
     *
     * The warm baseline and the cold measurement hold the same steady-state
     * live set (one grid of the current geometry), so that shared instance
     * cancels out of the delta — what the ceiling actually bounds is
     * *per-iteration retention*. A genuine leak overshoots it immediately:
     * one leaked 320x120 Buffer clone is ~1.5 MB and one leaked CellGrid
     * ~6.3 MB, i.e. two orders of magnitude over this 256 KiB slack, which
     * is sized only for allocator metadata on a shared CI runner.
     */
    private const GROWTH_CEILING_BYTES = 262_144;

    /**
     * Peak-usage ceiling across a churn phase, in bytes — deliberately an
     * order of magnitude looser than {@see GROWTH_CEILING_BYTES} because it
     * measures a different thing: the working set, not the retained set.
     *
     * Healthy churn transiently holds one extra full grid: `resize()` and
     * `Screen::fromBuffer()` build the new structure before the old one is
     * released, so peak sits ~one grid above the settled value by design
     * (measured ~1.3-3.0 MB for the 320x120 Buffer path, ~1.3 MB for the
     * 160x50 CellGrid path). 8 MiB covers the largest such transient with
     * slack; a per-iteration retention (≥1.5 MB leaked clone × dozens of
     * cycles) smashes straight through it. Each test scopes this to its own
     * phase via {@see capturePeakBaseline()} resetting the monotone mark.
     */
    private const PEAK_CEILING_BYTES = 8_388_608;

    // ─── Structural counts: grids are exactly cols x rows, never more ───

    public function testBufferGridHoldsExactlyColsTimesRows(): void
    {
        $buffer = new Buffer(320, 120);

        $copy = $buffer->copy();
        self::assertCount(120, $copy, 'a fresh Buffer must expose exactly `rows` row arrays');
        foreach ($copy as $row => $cells) {
            self::assertCount(320, $cells, "row {$row} must expose exactly `cols` cells");
        }

        // Churn: 120 alternating resize round-trips. The live structure is
        // re-created every call, so a widening grid or an appended stray row
        // would show up here even if the heap numbers stayed flat.
        for ($i = 0; $i < 120; $i++) {
            $buffer = $buffer->resize($i % 2 === 0 ? 321 : 320, 120);
            $copy = $buffer->copy();
            self::assertCount(120, $copy);
            self::assertCount($i % 2 === 0 ? 321 : 320, $copy[0]);
        }
    }

    public function testPristineBufferSharesTheEmptyCellSingletonAcrossAllSlots(): void
    {
        // Cell::empty() memoises one instance per PHP process, so an empty
        // 320x120 grid allocates 38400 array *slots* but exactly one cell
        // object. This is the strongest available guard against a future
        // change that re-instantiates empty cells per slot: the count below
        // would jump from 1 to 38400 (~6 MB) and this test would fail.
        $buffer = new Buffer(320, 120);

        $distinct = new SplObjectStorage();
        foreach ($buffer->each() as $slot) {
            $distinct->attach($slot['cell']);
        }

        self::assertSame(1, $distinct->count(), 'all empty slots must be one shared immutable Cell');
    }

    public function testWrittenCellsDoNotAccumulateAcrossRepeatedFeedOfTheSameStream(): void
    {
        // The exact per-cell object census, re-measured every cycle: one
        // shared singleton + exactly the printed cells. Feeding the same
        // stream 80 times must NOT raise the count — overwritten cells are
        // dropped on the floor, and the singleton keeps the blanks at one.
        $stream = "\x1b[2;3H" . str_repeat('x', 60) . "\x1b[3;1H" . '你好 world';

        $terminal = Terminal::new(320, 120);
        $baseline = null;

        for ($cycle = 0; $cycle < 80; $cycle++) {
            $terminal->feed($stream);
            $distinct = $this->distinctLiveCells($this->flattenCells($terminal->screen()));
            if ($baseline === null) {
                $baseline = $distinct;
            }
            self::assertSame($baseline, $distinct, "cycle {$cycle}: written-cell census must not drift");
            $terminal->resize(320, 120);
        }
    }

    public function testCellGridAllocatesExactlyColsTimesRowsValueCells(): void
    {
        // The vcr path has no shared empty() singleton: every slot is its
        // own immutable value object. Assert the census is *exactly*
        // cols*rows — not cols*rows + slack retained by a stale resize —
        // and that 80 clear/resize round-trips leave the count unchanged.
        $grid = new CellGrid(160, 50);
        self::assertSame(160 * 50, $this->distinctCellCensus($grid));

        for ($i = 0; $i < 80; $i++) {
            // clear() re-allocates the whole grid; resize() round-trips a
            // wider grid and back. Either way the live census must settle
            // on exactly cols*rows — no slack from the discarded shape.
            $grid = $grid->resize(161, 51)->resize(160, 50);
            $grid = $grid->clear();
            self::assertSame(160 * 50, $this->distinctCellCensus($grid), "cycle {$i}: grid stayed cols*rows");
        }
    }

    public function testDirtyRegionBoundsStayInsideGridAcrossChurn(): void
    {
        $grid = new CellGrid(160, 50);
        $handler = new HandlerAdapter(
            new CsiHandlerImpl($grid, new Cursor(), new Theme()),
            new OscHandlerImpl(),
        );
        $parser = new Parser($handler, maxStringBuffer: 65536);

        for ($i = 0; $i < 150; $i++) {
            $row = $i % 50;
            $col = $i % 160;
            $parser->feed("\x1b[" . ($row + 1) . ';' . ($col + 1) . 'H' . 'z');
            $dirty = $grid->dirtyRegion();
            // The sentinel values (min=PHP_INT_MAX, max=-1) would satisfy a
            // bare `0 <= x < rows` check forever; pin them to real, written
            // bounds so the test can't pass on an empty grid.
            self::assertNotSame(PHP_INT_MAX, $dirty['minRow'], 'dirty region must open after a write');
            self::assertNotSame(PHP_INT_MAX, $dirty['minCol'], 'dirty region must open after a write');
            self::assertLessThanOrEqual($row, $dirty['minRow']);
            self::assertGreaterThanOrEqual($row, $dirty['maxRow']);
            self::assertLessThan(50, $dirty['maxRow'], 'dirty region must never exceed the grid');
            self::assertGreaterThanOrEqual(0, $dirty['minCol']);
            self::assertLessThan(160, $dirty['maxCol'], 'dirty region must never exceed the grid');
            self::assertSame('z', $grid->get($row, $col)->char, 'the dispatched write must land in the grid');
        }
    }

    // ─── Scrollback ring: bounded occupancy regardless of push volume ───

    public function testScrollbackRingOccupancyStaysPinnedAtMaxSize(): void
    {
        $maxSize = 500;
        $scrollback = new Scrollback($maxSize);
        $row = array_fill(0, 80, Cell::empty());

        self::assertSame(0, $scrollback->count());
        self::assertSame($maxSize, $scrollback->maxSize());

        for ($i = 0; $i < $maxSize; $i++) {
            $scrollback->push($row);
            self::assertSame($i + 1, $scrollback->count(), 'ring fills one-for-one before saturation');
        }

        $settledFull = $this->settledUsage();

        // Another 4 * maxSize pushes past saturation: occupancy must stay
        // pinned at maxSize and the settled heap must not move (the ring
        // overwrites in place, it never appends).
        for ($i = 0; $i < $maxSize * 4; $i++) {
            $scrollback->push($row);
            self::assertSame($maxSize, $scrollback->count());
        }

        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - $settledFull,
            'saturated ring must reuse slots; live heap must not climb with push volume',
        );

        // Content identity: oldest survives at offset 0 until displaced, and
        // every slot is still one shared empty-cell row (singleton census).
        $distinct = new SplObjectStorage();
        foreach ($scrollback->all() as $scrolledRow) {
            foreach ($scrolledRow as $cell) {
                $distinct->attach($cell);
            }
        }
        self::assertSame(1, $distinct->count(), 'ring slots must not clone the shared empty cell');
    }

    public function testTerminalScrollCycleKeepsScrollbackRingBounded(): void
    {
        $scrollbackSize = 300;
        $terminal = Terminal::new(80, 24, $scrollbackSize);
        $settledWarm = null;

        // 1200 feed/scroll cycles of one line each, on a terminal whose
        // scrollback holds 300 rows: 4x oversupply, so the ring is deep in
        // overwrite territory for the measured phase.
        for ($cycle = 0; $cycle < 1200; $cycle++) {
            $terminal->feed("cycle line {$cycle}\r\n");
            if ($cycle === 400) {
                $settledWarm = $this->settledUsage();
                self::assertSame($scrollbackSize, $terminal->screen()->scrollback()?->count());
            }
        }

        self::assertSame($scrollbackSize, $terminal->screen()->scrollback()?->count(), 'ring stayed pinned at maxSize');
        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - (int) $settledWarm,
            'scroll churn must not grow the heap beyond a ring of maxSize rows',
        );
    }

    // ─── Heap boundedness: resize/feed/snapshot churn ───

    public function testBufferResizeRoundTripCycleDoesNotGrowHeap(): void
    {
        $buffer = new Buffer(320, 120);
        $buffer->put(10, 10, new Cell(grapheme: 'A'));

        // Warm the allocator first: identical-shape allocations land on
        // recycled chunks, so the cold-start cost is excluded from the
        // measurement and what remains is only *retained* memory.
        for ($i = 0; $i < 40; $i++) {
            $buffer = $buffer->resize(321, 121)->resize(320, 120);
        }
        $settledWarm = $this->settledUsage();
        $peakWarm = $this->capturePeakBaseline();

        for ($i = 0; $i < 150; $i++) {
            $buffer = $buffer->resize(321, 121)->resize(320, 120);
        }

        self::assertSame(320, $buffer->cols);
        self::assertSame(120, $buffer->rows);
        self::assertSame('A', $buffer->cell(10, 10)->grapheme, 'resize round-trip must preserve content');
        $this->assertNoGrowth($settledWarm, $peakWarm);
    }

    public function testTerminalResizeFeedCycleDoesNotGrowHeap(): void
    {
        // The hostile-environment shape named in the brief: resize to
        // 320x120, feed a deterministic SGR/CSI/UTF-8 stream, snapshot,
        // repeat 100x+ — with alt-screen enter/leave folded in because it
        // allocates a whole fresh Buffer per cycle (DEC 1049), which is the
        // single largest per-cycle allocation in the lib.
        $stream = "\x1b[1;31mred \x1b[0m日本 \x1b[2J\x1b[10;20Hx\x1b]0;t\x07\x1b[?1049h\x1b[?1049l";
        $terminal = Terminal::new(80, 24);

        for ($i = 0; $i < 5; $i++) {
            $terminal->resize(320, 120);
            $terminal->feed($stream);
            $terminal->screen();
            $terminal->resize(80, 24);
            $terminal->feed($stream);
            $terminal->screen();
        }
        $settledWarm = $this->settledUsage();
        $peakWarm = $this->capturePeakBaseline();

        for ($i = 0; $i < 100; $i++) {
            $terminal->resize(320, 120);
            $terminal->feed($stream);
            $screen = $terminal->screen();
            $terminal->resize(80, 24);
            $terminal->feed($stream);
            $screen = $terminal->screen();
            unset($screen);
        }

        self::assertSame(80, $terminal->screen()->cols);
        $this->assertNoGrowth($settledWarm, $peakWarm);
    }

    public function testWideCharacterReflowDuringResizeDoesNotAccumulateCells(): void
    {
        // CJK + emoji + combining marks, resized down and up so wide cells
        // are repeatedly split/dropped/re-created. Every cycle homes the
        // cursor first, so writes land in the same region each time: the
        // census is then an exact invariant, not a saturation curve.
        $utf8 = "日本語テキスト \u{1F600} e\u{0301} \u{1F1EF}\u{1F1F5} üö";
        $stream = "\x1b[H" . $utf8;
        $terminal = Terminal::new(320, 120);
        $settledSteady = null;
        $censusAt320 = null;

        for ($cycle = 0; $cycle < 100; $cycle++) {
            $terminal->feed($stream);
            $terminal->resize(160, 60);   // drop half the grid, reflow wide cells
            $terminal->feed($stream);
            $terminal->resize(320, 120);  // widen back, empty slots return
            $terminal->feed($stream);

            $cells = $this->flattenCells($terminal->screen());
            self::assertCount(320 * 120, $cells, 'grid stayed exactly cols*rows');
            $census = $this->distinctLiveCells($cells);
            if ($censusAt320 === null) {
                $censusAt320 = $census;
            } else {
                self::assertSame($censusAt320, $census, "cycle {$cycle}: wide-cell churn must not grow the live census");
            }

            if ($cycle === 50) {
                $settledSteady = $this->settledUsage();
            }
        }

        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - (int) $settledSteady,
            'wide-character reflow churn must not grow the settled heap',
        );
    }

    public function testScreenSnapshotChurnDropsPreviousSnapshotsCleanly(): void
    {
        // 500 back-to-back screen() snapshots, each dropped by reassignment
        // without a reset in between: Screen/Buffer are value-copied per
        // snapshot, so a leaked self-reference would grow linearly here.
        $buffer = new Buffer(320, 120);
        $buffer->put(0, 0, new Cell(grapheme: 'x'));
        $screen = Screen::fromBuffer($buffer);
        for ($i = 0; $i < 25; $i++) {
            $screen = Screen::fromBuffer($buffer);
        }
        $settledWarm = $this->settledUsage();
        $peakWarm = $this->capturePeakBaseline();

        for ($i = 0; $i < 500; $i++) {
            $screen = Screen::fromBuffer($buffer);
        }
        self::assertSame('x', $screen->cell(0, 0)->grapheme);
        self::assertSame(120, iterator_count($screen->lines()));
        $this->assertNoGrowth($settledWarm, $peakWarm);
    }

    public function testScreenDiffCycleDoesNotAccumulateChanges(): void
    {
        $buffer = new Buffer(120, 60);
        $settledWarm = null;
        $peakWarm = 0;

        for ($cycle = 0; $cycle < 60; $cycle++) {
            $before = Screen::fromBuffer($buffer);
            $buffer->put($cycle % 60, $cycle % 120, new Cell(grapheme: 'q'));
            $after = Screen::fromBuffer($buffer);
            $changes = $before->diff($after);
            self::assertLessThanOrEqual(1, count($changes), 'one write diffs to at most one cell change');
            unset($before, $after, $changes);
            if ($cycle === 30) {
                $settledWarm = $this->settledUsage();
                $peakWarm = $this->capturePeakBaseline();
            }
        }

        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - (int) $settledWarm,
            'diff churn must not grow the settled heap',
        );
    }

    public function testParserFeedResetCycleDoesNotGrowHeap(): void
    {
        // Parser exposes feed()/flush()/reset() but no resize — resize lives
        // on Buffer/CellGrid and is exercised through the terminal tests
        // above, so the parser half of the cycle is feed()+reset(). The
        // handler discards dispatches on purpose: a DebugHandler $log grows
        // with input volume (legitimately) and would measure the fixture,
        // not the parser's internal buffers (params, stringBuffer,
        // utf8Buffer), which is what reset() must reclaim.
        $parser = new Parser($handler = new NullHandler(), maxStringBuffer: 65536);
        $stream = "\x1b[38;2;12;34;56;48;49;50m \x1b]8;;https://example.test/a\x07"
            . "\x1b[1;2;3;4H 日本 e\u{0301} \x1b[?25l\x1b[?25h";

        for ($i = 0; $i < 200; $i++) {
            $parser->feed($stream);
            $parser->reset();
        }
        $settledWarm = $this->settledUsage();
        $peakWarm = $this->capturePeakBaseline();

        for ($i = 0; $i < 2000; $i++) {
            $parser->feed($stream);
            $parser->reset();
        }

        // Liveness guard: the churn must have actually dispatched — an idle
        // parser (state-machine regression) would keep the heap flat too.
        self::assertGreaterThan(2000, $handler->dispatches, 'parser must keep dispatching across reset()');
        self::assertSame(State::Ground, $parser->currentState(), 'reset() must return the parser to ground');
        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - $settledWarm,
            'feed/reset churn must not grow the parser footprint',
        );
    }

    public function testUnterminatedSequencesDoNotGrowParserAcrossResets(): void
    {
        // Hostile input on purpose: truncated OSC/DCS (no terminator),
        // partial UTF-8, a CSI at the 32-param cap. flush() + reset() must
        // reclaim the in-flight string buffer every cycle — this is the
        // exact pattern where a missing clear() would smear a growing
        // stringBuffer across thousands of cycles.
        $hostile = "\x1b]0;unterminated title"
            . "\x1bP0;1qDCS-payload-no-st"
            . "\xC3"                                   // lead byte of a 2-byte rune, cut off
            . "\x1b[1;2;3;4;5;6;7;8;9;10;11;12;13;14;15;16;17;18;19;20"
            . ';21;22;23;24;25;26;27;28;29;30;31;32;33;34;35;36;37;38;39;40;41;42m';

        $parser = new Parser($handler = new NullHandler(), maxStringBuffer: 65536);
        $settledWarm = null;
        $peakWarm = 0;

        for ($i = 0; $i < 5000; $i++) {
            $parser->feed($hostile);
            $parser->flush();
            $parser->reset();
            if ($i === 500) {
                $settledWarm = $this->settledUsage();
                $peakWarm = $this->capturePeakBaseline();
            }
        }

        self::assertGreaterThan(5000, $handler->dispatches, 'hostile input must still drive dispatches');
        self::assertSame(State::Ground, $parser->currentState());
        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $this->settledUsage() - (int) $settledWarm,
            '5000 cycles of hostile truncated sequences must not grow the settled heap',
        );
    }

    public function testRendererTerminalGridAndSnapshotChurnDoesNotGrowHeap(): void
    {
        // The second pipeline ({@see RendererTerminal}, vcr renderer path)
        // owns a CellGrid + Snapshot per frame. 300 feed/snapshot cycles at
        // 160x50 (~1.3 MB per grid of value cells) — with snapshots dropped
        // each cycle.
        $terminal = RendererTerminal::new(160, 50);
        $stream = "\x1b[1;1Hhello 日本 \x1b[2J\x1b[10;20Hx";

        for ($i = 0; $i < 20; $i++) {
            $terminal->feed($stream);
            self::assertSame(160, $terminal->snapshot()->grid->cols);
        }
        $settledWarm = $this->settledUsage();
        $peakWarm = $this->capturePeakBaseline();

        for ($i = 0; $i < 300; $i++) {
            $terminal->feed($stream);
            $snap = $terminal->snapshot();
            unset($snap);
        }

        self::assertSame(160, $terminal->grid()->cols);
        self::assertSame(50, $terminal->grid()->rows);
        $this->assertNoGrowth($settledWarm, $peakWarm);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Settled live-heap size: run cycle collection and give the allocator's
     * remote caches back, so the number reflects *retained* memory rather
     * than recycling-pending bookkeeping. Immune to other processes on a
     * shared runner — this is the zend EMALLOC live bytes of *this* process.
     */
    private function settledUsage(): int
    {
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }

        return memory_get_usage();
    }

    /**
     * Distinct live `Vt\Cell` object count across the whole vcr grid —
     * exactly cols*rows while nothing else references the discarded grids.
     */
    private function distinctCellCensus(CellGrid $grid): int
    {
        $ids = [];
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $ids[spl_object_id($grid->get($r, $c))] = true;
            }
        }

        return count($ids);
    }

    /**
     * @return list<Cell>
     */
    private function flattenCells(Screen $screen): array
    {
        $out = [];
        for ($r = 0; $r < $screen->rows; $r++) {
            for ($c = 0; $c < $screen->cols; $c++) {
                $out[] = $screen->cell($r, $c);
            }
        }

        return $out;
    }

    /**
     * Count distinct live objects among the given cells — the allocation
     * census the brief asks for, expressed as a number rather than bytes.
     *
     * @param list<Cell|RendererCell> $cells
     */
    private function distinctLiveCells(array $cells): int
    {
        $ids = [];
        foreach ($cells as $cell) {
            $ids[spl_object_id($cell)] = true;
        }

        return count($ids);
    }

    /**
     * Reset the process-monotone peak high-water mark and return the current
     * usage as this test's peak baseline. Without the reset, an earlier test
     * in the same PHPUnit process can leave the high-water mark above
     * anything this test allocates, making the peak assertion read 0 and pass
     * vacuously; resetting scopes it to the test's own transients.
     */
    private function capturePeakBaseline(): int
    {
        memory_reset_peak_usage();

        return memory_get_peak_usage();
    }

    private function assertNoGrowth(int $settledWarm, int $peakWarm): void
    {
        $settledNow = $this->settledUsage();
        self::assertLessThan(
            self::GROWTH_CEILING_BYTES,
            $settledNow - $settledWarm,
            "settled heap grew " . ($settledNow - $settledWarm) . ' bytes across churn',
        );
        self::assertLessThan(
            self::PEAK_CEILING_BYTES,
            memory_get_peak_usage() - $peakWarm,
            'peak working set climbed during churn — allocation outran release beyond one transient grid',
        );
    }
}
