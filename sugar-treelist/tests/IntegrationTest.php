<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\{DefaultPrefixer, DefaultSuffixer, FilterState, Model, StringItem};
use SugarCraft\TreeList\Tests\Support\RowDeltaAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Step 18: Comprehensive integration test — exercises the full lifecycle
 * of the Lister component end-to-end.
 *
 * Covers:
 * - Full lifecycle: create → add items → navigate → render
 * - Diff pipeline: first frame full, subsequent frames delta
 * - Filtering + rendering combined
 * - Sorting + cursor preservation integrated with render
 * - Viewport resize behavior
 * - Multi-item scenarios with DefaultPrefixer/DefaultSuffixer
 * - Style application end-to-end
 */
final class IntegrationTest extends TestCase
{
    use RowDeltaAssertions;
    /**
     * Full lifecycle: model creation through rendering with cursor navigation.
     * Verifies that after creating a model, adding items, and navigating,
     * the rendered output contains all expected content and styles.
     *
     * Note: With cursorOffset=2 and cursor on index 1, viewport-follow shifts
     * the window down so item 0 may not be visible (the cursor stays 2 lines
     * from the bottom edge). We use cursorIndex=0 to keep the first item
     * visible. The shifted path itself is pinned exactly in ModelTest
     * (testLinesViewportFollowBottomGap + the audit-M1 tail-duplication repro).
     */
    public function testFullLifecycleCreateNavigateRender(): void
    {
        $m = Model::new()
            ->withViewport(60, 10)
            ->withCursorOffset(2)
            ->withItem(new StringItem('first item'))
            ->withItem(new StringItem('second item'))
            ->withItem(new StringItem('third item'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle("\x1b[2m")
            ->withCurrentStyle("\x1b[1m");

        // Cursor is already on index 0 (first item) - no navigation needed
        $this->assertSame(0, $m->cursorIndex());
        $this->assertSame('first item', (string) $m->cursorItem());

        // Render with cursor on first item
        $out = $m->view();
        $this->assertIsString($out);
        $this->assertStringContainsString('first item', $out);
        $this->assertStringContainsString('second item', $out);
        $this->assertStringContainsString('╭', $out); // DefaultPrefixer box-drawing
        // Cursor marker '>' should appear on first item's line
        $this->assertStringContainsString('>', $out);
    }

    /**
     * Diff pipeline: first View() emits the full frame; later frames carry
     * only the rows whose bytes changed, and replaying them reconstructs a
     * fresh render byte for byte. (The old "delta is smaller than full"
     * length heuristic was replaced in the 2026-11-03 fix wave — size says
     * nothing about correctness.)
     */
    public function testDiffPipelineEmitsFullThenDelta(): void
    {
        $build = static fn(int $cursor): Model => Model::new()
            ->withViewport(80, 24)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withItem(new StringItem('item 2'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCursor($cursor);

        // Frame 1: full output
        $m = $build(0);
        $frame1 = $m->view();
        $this->assertNotEmpty($frame1);
        $this->assertStringContainsString('item 0', $frame1);

        // Frame 2: cursor move → delta rewriting the two touched rows only
        $m2 = $m->withCursor(1);
        $frame2 = $m2->view();
        $this->assertNotEmpty($frame2);
        $this->assertDeltaWritesRows($frame2, 2);
        $this->assertStringNotContainsString('item 2', $frame2);
        $this->assertDeltaRebuildsFullFrame($frame1, $frame2, $build(1));

        // Frame 3: another cursor move → delta chained over the rebuilt screen
        $m3 = $m2->withCursor(2);
        $frame3 = $m3->view();
        $this->assertNotEmpty($frame3);
        $this->assertDeltaWritesRows($frame3, 2);
        $this->assertStringNotContainsString('item 0', $frame3);
        $screen = $this->applyRowDelta($frame1, $frame2);
        $this->assertSame($build(2)->view(), $this->applyRowDelta($screen, $frame3));
    }

    /**
     * Filtering combined with rendering: filter reduces visible items,
     * cursor is clamped, and first filtered render is full (not delta).
     */
    public function testFilterCombinedWithRender(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('apricot'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        // Render unfiltered first (establishes previousFrame)
        $m->view();

        // Apply filter and render
        $filtered = $m->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));
        $this->assertSame(2, $filtered->length());
        $this->assertSame('apple', (string) $filtered->cursorItem());

        $out = $filtered->view();
        $this->assertStringContainsString('apple', $out);
        $this->assertStringContainsString('apricot', $out);
        $this->assertStringNotContainsString('banana', $out);

        // Remove filter and verify restoration
        $restored = $filtered->withoutFilter();
        $this->assertSame(4, $restored->length());
        $this->assertNull($restored->filterFn());
        $this->assertSame(FilterState::unfiltered, $restored->filterState());
    }

    /**
     * Sorting combined with cursor preservation: after sort,
     * cursor stays on the same logical item even though positions changed.
     */
    public function testSortCombinedWithCursorPreservationAndRender(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('zebra'))
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('mango'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        // Cursor on 'zebra' (index 0)
        $this->assertSame('zebra', (string) $m->cursorItem());

        // Sort alphabetically
        $m = $m->sort();
        $this->assertSame('zebra', (string) $m->cursorItem(), 'Cursor must stay on same logical item after sort');

        // Render and verify order
        $out = $m->view();
        $this->assertStringContainsString('apple', $out);
        $this->assertStringContainsString('mango', $out);
        $this->assertStringContainsString('zebra', $out);
    }

    /**
     * Viewport resize triggers full frame repaint, not a delta against
     * the pre-resize frame (byte-equality with a fresh instance at the new
     * width, 2026-11-03 fix wave).
     */
    public function testResizeTriggersFullRepaint(): void
    {
        $build = static fn(int $width, int $cursor): Model => Model::new()
            ->withViewport($width, 5)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withItem(new StringItem('item 2'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCursor($cursor);

        $m = $build(40, 0);
        $frame1 = $m->view();

        // Cursor move → delta over the two marker-touched rows
        $m2 = $m->withCursor(1);
        $frame2 = $m2->view();
        $this->assertDeltaWritesRows($frame2, 2);
        $this->assertDeltaRebuildsFullFrame($frame1, $frame2, $build(40, 1));

        // Resize to wider viewport → full frame at the new width
        $m3 = $m2->withViewport(80, 5);
        $frame3 = $m3->view();
        $this->assertSame($build(80, 1)->view(), $frame3);
        $this->assertStringContainsString('item 0', $frame3);
        $this->assertStringNotContainsString("\x1b[", $frame3);
    }

    /**
     * Multi-line items render correctly within viewport constraints.
     */
    public function testMultiLineItemsRenderWithinViewport(): void
    {
        $m = Model::new()
            ->withViewport(30, 8)
            ->withItem(new StringItem("line1\nline2\nline3"))
            ->withItem(new StringItem('single line'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withWrap(2);  // limit to 2 lines per item

        $lines = $m->lines();
        // With wrap=2, the first item contributes at most 2 lines
        $this->assertLessThanOrEqual(4, \count($lines));
    }

    /**
     * Style application end-to-end: lineStyle applies to non-current items,
     * currentStyle applies to the cursor item, and both styles are present
     * in the rendered output.
     *
     * Note: View() returns full styled output on first render, but subsequent
     * renders with same dimensions return delta-encoded output. We use two
     * separate models (one per cursor position) to test style application.
     */
    public function testStyleApplicationEndToEnd(): void
    {
        // Model with cursor on item 0
        $m0 = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle("\x1b[2m")   // dim for non-current
            ->withCurrentStyle("\x1b[1m"); // bold for current

        $out0 = $m0->view();
        $this->assertIsString($out0);
        $this->assertStringContainsString('item 0', $out0);
        $this->assertStringContainsString('item 1', $out0);
        // First frame is full output with styling
        $this->assertStringContainsString("\x1b[2m", $out0); // lineStyle applied

        // Different model with cursor on item 1 (tests currentStyle)
        $m1 = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle("\x1b[2m")
            ->withCurrentStyle("\x1b[7m"); // reverse video for current item

        $out1 = $m1->view();
        $this->assertIsString($out1);
        $this->assertStringContainsString('item 1', $out1);
        $this->assertStringContainsString("\x1b[7m", $out1); // currentStyle applied
    }

    /**
     * find() works correctly after filter operations.
     */
    public function testFindAfterFilter(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('apricot'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        $filtered = $m->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));
        $this->assertSame(0, $filtered->find(new StringItem('apple')));
        $this->assertSame(1, $filtered->find(new StringItem('apricot')));
        $this->assertSame(-1, $filtered->find(new StringItem('banana')));
    }

    /**
     * resetPreviousFrame() forces full frame on next render (byte-equality
     * with a fresh instance; the old length heuristic was retired in the
     * 2026-11-03 fix wave).
     */
    public function testResetPreviousFrameForcesFullFrame(): void
    {
        $build = static fn(int $cursor): Model => Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCursor($cursor);

        $m = $build(0);
        $frame1 = $m->view();

        // Move cursor → delta over the two marker-touched rows
        $m2 = $m->withCursor(1);
        $frame2 = $m2->view();
        $this->assertDeltaWritesRows($frame2, 2);
        $this->assertDeltaRebuildsFullFrame($frame1, $frame2, $build(1));

        // Reset and render again → full frame, byte-identical to a fresh paint
        $m2->resetPreviousFrame();
        $frame3 = $m2->view();
        $this->assertNotSame($frame2, $frame3);
        $this->assertSame($build(1)->view(), $frame3);
    }

    /**
     * Immutability: all mutation operations return new instances,
     * leaving the original model unchanged.
     */
    public function testAllMutationsReturnNewInstances(): void
    {
        $original = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('item 0'));

        // Every mutating operation returns a new instance
        $modified = $original
            ->withItem(new StringItem('item 1'))
            ->withCursor(1)
            ->withViewport(60, 10)
            ->withCursorOffset(3)
            ->withWrap(5)
            ->withLineStyle("\x1b[2m")
            ->withCurrentStyle("\x1b[1m");

        // Original is unchanged
        $this->assertSame(1, $original->length());
        $this->assertSame(0, $original->cursorIndex());
        $this->assertSame(40, $original->width());
        $this->assertSame(5, $original->cursorOffset());

        // Modified has all changes
        $this->assertSame(2, $modified->length());
        $this->assertSame(1, $modified->cursorIndex());
        $this->assertSame(60, $modified->width());
        $this->assertSame(3, $modified->cursorOffset());
    }

    /**
     * Filter does not affect original model when using withoutFilter().
     */
    public function testFilterDoesNotAffectOriginalModel(): void
    {
        $original = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        $filtered = $original->withFilterFn(fn($v) => (string) $v !== 'banana');
        $restored = $filtered->withoutFilter();

        // Original is completely unchanged
        $this->assertSame(3, $original->length());
        $this->assertSame(0, $original->cursorIndex());
        $this->assertNull($original->filterFn());
        $this->assertNull($original->filterState());

        // Restored is same as original
        $this->assertSame($original->length(), $restored->length());
        $this->assertNull($restored->filterFn());
    }

    /**
     * Security (sugar-cellbuffer #1362 defense-in-depth): a raw item value carrying
     * bare C0 control bytes (BEL 0x07, NUL 0x00) and DEL 0x7F must be scrubbed
     * before it reaches the terminal wire, while the legitimate SGR ESC injected
     * by the current/line style must survive. Covers BOTH view() sinks — the
     * first-frame full output and the row-granular delta (sugar-treelist-018:
     * the old docblock promised a bufferFromOutput()/DiffEncoder second frame,
     * dead API from the abandoned cell-projection era; an identical-state
     * second view() returns '' and the delta legs passed vacuously). The
     * delta sink is now exercised the way the renderer actually emits it: a
     * cursor move re-emits the scrubbed row inside a NON-empty delta.
     */
    public function testControlBytesStrippedButStyleSgrPreserved(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem("safe\x07value\x00mid\x7fend"))
            ->withItem(new StringItem('second item'))
            ->withCurrentStyle("\x1b[1m"); // bold — injects a legit SGR ESC

        // First frame: full output path (`return $fullOutput`).
        $first = $m->view();

        // C0/DEL bytes must be gone from the wire.
        $this->assertStringNotContainsString("\x07", $first, 'BEL leaked');
        $this->assertStringNotContainsString("\x00", $first, 'NUL leaked');
        $this->assertStringNotContainsString("\x7f", $first, 'DEL leaked');
        // Printable content around the stripped bytes survives.
        $this->assertStringContainsString('safevaluemidend', $first);
        // The legitimate SGR ESC from withCurrentStyle() must be preserved.
        $this->assertStringContainsString("\x1b[1m", $first, 'style SGR was stripped');

        // Delta sink: move the cursor so the scrubbed row is RE-EMITTED in the
        // second frame's change set (sugar-cellbuffer #1362 rode the delta just
        // as happily as the full frame). Anti-vacuity: an empty delta here
        // would mean the pin never exercised the changed-row path.
        $second = $m->withCursor(1)->view();
        $this->assertNotSame('', $second, 'cursor move must re-emit the restyled rows through the delta sink');
        $this->assertStringNotContainsString("\x07", $second, 'BEL leaked (delta)');
        $this->assertStringNotContainsString("\x00", $second, 'NUL leaked (delta)');
        $this->assertStringNotContainsString("\x7f", $second, 'DEL leaked (delta)');
        $this->assertStringContainsString("\x1b[1m", $second, 'style SGR was stripped (delta)');
    }

    /**
     * Security (zone-marker forgery, fleet marked-frames cure): the candy-mouse
     * zone sentinels U+E000/U+E001 delimit clickable regions in composed frames.
     * Sanitize::untrusted() passes the whole BMP private-use arena through BY
     * CONTRACT, so a forged sentinel pair inside an item value used to survive
     * to the wire — letting list data open a mouse zone it does not own, or
     * splice one out of the real zone layout. renderItem() now runs
     * Sanitize::untrustedForMarkedFrames(), which additionally sweeps exactly
     * the two sentinel code points. The printable payload around them stays
     * (the value renders), but it can no longer FORM a zone on either view()
     * sink — first frame or delta frame.
     */
    public function testForgedZoneSentinelsCannotSurviveEitherFrameSink(): void
    {
        $sentinelOpen = "\u{e000}";
        $sentinelClose = "\u{e001}";

        $m = Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem("before{$sentinelOpen}zone:evil{$sentinelClose}after"))
            ->withItem(new StringItem("second item"))
            ->withCurrentStyle("\x1b[7m");

        $first = $m->view();
        $this->assertStringNotContainsString($sentinelOpen, $first, 'open sentinel leaked (full frame)');
        $this->assertStringNotContainsString($sentinelClose, $first, 'close sentinel leaked (full frame)');
        $this->assertStringContainsString('beforezone:evilafter', $first, 'printable payload must survive');

        // Delta sink: move the cursor so the forged row is RE-EMITTED in the
        // second frame's change set — the sentinels must still be absent, and
        // the delta must actually carry the row (anti-vacuity: an empty string
        // here would mean the pin never exercised the changed-row path).
        $moved = $m->withCursor(1);
        $second = $moved->view();
        $this->assertNotSame('', $second, 'cursor move must re-emit the restyled row through the delta sink');
        $this->assertStringNotContainsString($sentinelOpen, $second, 'sentinel on delta sink');
        $this->assertStringNotContainsString($sentinelClose, $second, 'sentinel on delta sink');
    }

    public function testFilteredToEmptyFrameIsTheHeightTallErrorSinkBytePin(): void
    {
        // sugar-treelist-043 / sugar-treelist-003 leg: the error sink doubles
        // as the empty-state frame. A filter matching nothing renders the
        // localized no-items message as row 1 of a viewport-height frame
        // whose remaining rows are absolute CUP + erase-line clears. Full
        // byte pin so both the message text and the clearing scheme freeze.
        $frame = Model::new()
            ->withViewport(20, 3)
            ->withItem(new StringItem('alpha'))
            ->withFilterFn(static fn (\Stringable $item): bool => false)
            ->view();

        $this->assertSame(
            "\x1b[1;1HThe list has no items to display.\x1b[K\n"
            . "\x1b[2;1H\x1b[K\n"
            . "\x1b[3;1H\x1b[K\n",
            $frame,
        );
    }
}
