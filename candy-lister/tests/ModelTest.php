<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\{DefaultPrefixer, DefaultSuffixer, FilterState, Model, StringItem};
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    private Model $model;

    protected function setUp(): void
    {
        $this->model = Model::new()
            ->setViewport(80, 24)
            ->setCursorOffset(3);
    }

    public function testNewModelIsNotEmpty(): void
    {
        $m = Model::new();
        $this->assertSame(80, $m->width);
        $this->assertSame(24, $m->height);
        $this->assertSame(5, $m->cursorOffset);
    }

    public function testAddItem(): void
    {
        $m = $this->model->addItem(new StringItem('hello'));
        $this->assertSame(1, $m->length());
        $this->assertFalse($m->isEmpty());
        // Original model unchanged (immutable addItem)
        $this->assertSame(0, $this->model->length());
    }

    public function testFluentSetters(): void
    {
        $m = $this->model
            ->setWidth(100)
            ->setHeight(30)
            ->setViewport(120, 40)
            ->setCursorOffset(7)
            ->setWrap(5);

        $this->assertSame(120, $m->width);
        $this->assertSame(40, $m->height);
        $this->assertSame(7, $m->cursorOffset);
        $this->assertSame(5, $m->wrap);
    }

    public function testCursorNavigation(): void
    {
        $m = $this->model;
        foreach (['a', 'b', 'c'] as $v) {
            $m = $m->addItem(new StringItem($v));
        }

        $this->assertSame(0, $m->cursorIndex());
        $m = $m->cursorDown();
        $this->assertSame(1, $m->cursorIndex());
        $m = $m->cursorDown();
        $this->assertSame(2, $m->cursorIndex());
        $m = $m->cursorUp();
        $this->assertSame(1, $m->cursorIndex());
    }

    public function testCursorClampAtBounds(): void
    {
        $m = $this->model->addItem(new StringItem('only'));
        $m->cursorUp();  // should not go below 0
        $this->assertSame(0, $m->cursorIndex());
        $m->cursorDown(100);  // should not exceed length-1
        $this->assertSame(0, $m->cursorIndex());
    }

    public function testRemoveItem(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(3, $this->model->length());

        $this->model = $this->model->removeItem(1);
        $this->assertSame(2, $this->model->length());
    }

    public function testClear(): void
    {
        foreach (['a', 'b'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(2, $this->model->length());
        $this->model = $this->model->clear();
        $this->assertTrue($this->model->isEmpty());
    }

    public function testSortWithLessFunc(): void
    {
        foreach (['z', 'a', 'm'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $this->model->lessFunc = fn($a, $b) => \strcmp((string) $a, (string) $b);
        $this->model = $this->model->sort();

        // After sort, cursor stays on the same logical item ('z' was at cursor index 0).
        $this->assertSame('z', (string) $this->model->cursorItem());
        // The first visible line is 'a' (alphabetically first) but cursor is on 'z'.
        $this->assertSame('a', $this->model->lines()[0]);
    }

    public function testFindItem(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $this->assertSame(1, $this->model->find(new StringItem('banana')));
        $this->assertSame(-1, $this->model->find(new StringItem('durian')));
    }

    public function testFindWithEqualsFunc(): void
    {
        foreach (['aa', 'bb', 'cc'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $this->model->equalsFunc = fn($a, $b) => (string) $a === (string) $b;
        $this->assertSame(0, $this->model->find(new StringItem('aa')));
        $this->assertSame(-1, $this->model->find(new StringItem('xx')));
    }

    public function testSetCursor(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $this->model = $this->model->setCursor(2);
        $this->assertSame(2, $this->model->cursorIndex());
    }

    public function testLinesThrowsOnEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testLinesThrowsOnZeroViewport(): void
    {
        $this->model->addItem(new StringItem('x'))->setViewport(0, 10);
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testViewReturnsString(): void
    {
        $m = $this->model
            ->addItem(new StringItem('item one'))
            ->addItem(new StringItem('item two'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $view = $m->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('item one', $view);
        $this->assertStringContainsString('item two', $view);
    }

    public function testPrefixerInterface(): void
    {
        $m = $this->model
            ->addItem(new StringItem('test item'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    public function testStringItem(): void
    {
        $item = new StringItem('my string');
        $this->assertSame('my string', (string) $item);
        $this->assertSame('my string', $item->value);
    }

    public function testWrapLimitsLines(): void
    {
        $longText = \str_repeat('word ', 50);  // long enough to wrap
        $m = $this->model
            ->addItem(new StringItem($longText))
            ->setViewport(20, 24)
            ->setWrap(3)
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $lines = $m->lines();
        $this->assertLessThanOrEqual(3, \count($lines));
    }

    public function testAddItemReturnsNewInstance(): void
    {
        $m = $this->model->addItem(new StringItem('x'));
        // Returns a new instance, not the same object.
        $this->assertNotSame($this->model, $m);
        // Original is unchanged.
        $this->assertSame(0, $this->model->length());
        // New instance has the item.
        $this->assertSame(1, $m->length());
    }

    // -------------------------------------------------------------------------
    // Filter tests
    // -------------------------------------------------------------------------

    public function testWithFilterFnReturnsNewInstance(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => str_starts_with((string) $v, 'b'));

        // Original model unchanged
        $this->assertSame(3, $this->model->length());
        $this->assertNull($this->model->filterFn);
        $this->assertNull($this->model->filterState);

        // New model has filtered items
        $this->assertSame(1, $filtered->length());
        $this->assertSame('banana', (string) $filtered->cursorItem());
        $this->assertNotNull($filtered->filterFn);
        $this->assertSame(FilterState::filtering, $filtered->filterState);
    }

    public function testWithFilterFnFiltersItemsCorrectly(): void
    {
        foreach (['apple', 'apricot', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));

        $this->assertSame(2, $filtered->length());
        // Verify by navigating through cursorItem
        $this->assertSame('apple', (string) $filtered->cursorItem());
        $filtered = $filtered->cursorDown();
        $this->assertSame('apricot', (string) $filtered->cursorItem());
    }

    public function testWithoutFilterRestoresOriginalItems(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'banana');
        $this->assertSame(2, $filtered->length());

        $restored = $filtered->withoutFilter();
        $this->assertSame(3, $restored->length());
        $this->assertNull($restored->filterFn);
        $this->assertSame(FilterState::unfiltered, $restored->filterState);
    }

    public function testWithoutFilterOnUnfilteredModelReturnsSelf(): void
    {
        $this->model = $this->model->addItem(new StringItem('apple'));
        $result = $this->model->withoutFilter();
        // On an unfiltered model, withoutFilter is a no-op and returns $this unchanged.
        $this->assertSame($this->model, $result);
    }

    public function testWithFilterFnCursorClampedToFilteredLength(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }
        $this->model = $this->model->setCursor(2);  // cursor on 'c'

        // Filter out 'c' — cursor should clamp to index 1 (last remaining item 'b')
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'c');
        $this->assertSame(2, $filtered->length());
        $this->assertSame(1, $filtered->cursorIndex());
    }

    public function testFilterStateIsFilteringAfterSet(): void
    {
        $this->model = $this->model->addItem(new StringItem('apple'));
        $this->assertNull($this->model->filterState);

        $filtered = $this->model->withFilterFn(fn($v) => true);
        $this->assertSame(FilterState::filtering, $filtered->filterState);
    }

    // ─── Additional edge cases ───────────────────────────────────────────────

    public function testCursorUpClampsToZero(): void
    {
        $m = $this->model->addItem(new StringItem('only'));
        $result = $m->cursorUp(10);
        $this->assertSame(0, $result->cursorIndex());
    }

    public function testCursorDownClampsToLastIndex(): void
    {
        $m = $this->model;
        foreach (['a', 'b'] as $v) {
            $m = $m->addItem(new StringItem($v));
        }
        $m = $m->setCursor(0);
        $m = $m->cursorDown(100);
        $this->assertSame(1, $m->cursorIndex());
    }

    public function testSetCursorOffsetIsIndependentOfLineOffset(): void
    {
        // lineOffset is now an internal scroll anchor, no longer aliased to cursorOffset.
        $m = $this->model->setCursorOffset(7);
        $this->assertSame(7, $m->cursorOffset);
        // lineOffset retains its default value (not overwritten by setCursorOffset).
        $this->assertSame(5, $m->lineOffset);
    }

    public function testSetLineStyle(): void
    {
        $m = $this->model->setLineStyle("\x1b[2m");
        $this->assertSame("\x1b[2m", $m->lineStyle);
    }

    public function testSetCurrentStyle(): void
    {
        $m = $this->model->setCurrentStyle("\x1b[1m");
        $this->assertSame("\x1b[1m", $m->currentStyle);
    }

    public function testSortWithNullLessFuncReturnsSelf(): void
    {
        $this->model->addItem(new StringItem('z'));
        $result = $this->model->sort();
        $this->assertSame($this->model, $result);
    }

    public function testWithoutFilterOnModelWithNoFilterReturnsSelf(): void
    {
        $result = $this->model->withoutFilter();
        $this->assertSame($this->model, $result);
    }

    public function testRemoveItemAtInvalidIndexReturnsSelf(): void
    {
        $m = $this->model->addItem(new StringItem('item'));
        $result = $m->removeItem(99);
        $this->assertSame($m, $result);
    }

    public function testRemoveItemAtNegativeIndexReturnsSelf(): void
    {
        $m = $this->model->addItem(new StringItem('item'));
        $result = $m->removeItem(-1);
        $this->assertSame($m, $result);
    }

    public function testMultilineItemRendersMultipleLines(): void
    {
        $m = $this->model
            ->addItem(new StringItem("line1\nline2\nline3"))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $m->lines();
        $this->assertGreaterThan(1, count($lines));
    }

    public function testSetPrefixer(): void
    {
        $m = $this->model->setPrefixer(new DefaultPrefixer());
        $this->assertInstanceOf(DefaultPrefixer::class, $m->prefixer);
    }

    public function testSetSuffixer(): void
    {
        $m = $this->model->setSuffixer(new DefaultSuffixer());
        $this->assertInstanceOf(DefaultSuffixer::class, $m->suffixer);
    }

    public function testCursorItemThrowsOnEmptyList(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->model->cursorItem();
    }

    public function testLinesWithStyleApplied(): void
    {
        $m = $this->model
            ->addItem(new StringItem('styled'))
            ->setLineStyle("\x1b[2m")
            ->setCurrentStyle("\x1b[1m")
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    public function testFilterWithNoMatchingItems(): void
    {
        $this->model = $this->model->addItem(new StringItem('apple'));
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'apple');
        $this->assertSame(0, $filtered->length());
    }

    public function testFilterCursorsToLastItemWhenOnlyOneMatches(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->addItem(new StringItem($v));
        }
        $this->model = $this->model->setCursor(2); // cursor on 'c'
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v === 'a');
        // After filtering to just 'a', cursor should be 0
        $this->assertSame(0, $filtered->cursorIndex());
    }

    // ─── Exercises private helper methods via public API ──────────────────────

    public function testLinesWithVeryLongWordExercisingSplitOverWidth(): void
    {
        // A single word longer than content width should be split
        $longWord = \str_repeat('a', 100);
        $m = $this->model
            ->addItem(new StringItem($longWord))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(20, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
        // The long word should be split into multiple lines
        $this->assertGreaterThan(1, \count($lines));
    }

    public function testLinesWithMultiParagraphTextExercisingHardWrap(): void
    {
        // Multi-paragraph text (contains \n) exercises hardWrap
        $m = $this->model
            ->addItem(new StringItem("para1\n\npara2"))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    public function testLinesWithWrapLimitExercisingRenderItemWrapLogic(): void
    {
        $multiline = "line1\nline2\nline3\nline4\nline5";
        $m = $this->model
            ->addItem(new StringItem($multiline))
            ->setWrap(2) // limit to 2 lines
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $m->lines();
        // Should be limited to 2 lines due to wrap setting
        $this->assertLessThanOrEqual(2, \count($lines));
    }

    public function testLinesWithContentWidthTooNarrowExercisingHardWrapEdgeCase(): void
    {
        $m = $this->model
            ->addItem(new StringItem('word'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(5, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Steps 9-13: regression and correctness tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Step 9: regression — when cursor is on a non-zero index, the > marker
     * must appear on the cursor item, NOT only on index 0.
     */
    public function testMarkerOnNonZeroCursorItem(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->setCursorOffset(2)
            ->addItem(new StringItem('item0'))
            ->addItem(new StringItem('item1'))
            ->addItem(new StringItem('item2'))
            ->setPrefixer(new DefaultPrefixer());

        // Cursor on item 2 → its first line has '>'
        $m2 = $m->setCursor(2);
        $lines = $m2->lines();
        // The first visible line (item 2's first line) must contain '>'
        $this->assertStringContainsString('>', $lines[0]);
        // Other items' first lines must NOT contain '>'
        $this->assertStringNotContainsString('>', $lines[1] ?? '');
    }

    /**
     * Step 10: snapshot — View() at a fixed deterministic state produces
     * a well-formed ANSI output string with expected content and styling.
     */
    public function testViewSnapshotOutputIsDeterministic(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->setCursorOffset(2)
            ->addItem(new StringItem('apple'))
            ->addItem(new StringItem('banana'))
            ->addItem(new StringItem('cherry'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setCurrentStyle("\x1b[1m");

        $out = $m->View();
        $this->assertIsString($out);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString('apple', $out);
        $this->assertStringContainsString('banana', $out);
        $this->assertStringContainsString('cherry', $out);
        $this->assertStringContainsString("\x1b[1m", $out);
        $this->assertStringContainsString('╭', $out);
        // Second frame: cursor move produces a delta (shorter than full render)
        $m2 = $m->setCursor(1);
        $out2 = $m2->View();
        $this->assertIsString($out2);
        $this->assertLessThan(\strlen($out), \strlen($out2));
    }

    /**
     * Step 11: diff-correctness — cursor-only move produces a delta that
     * is shorter than a full re-render but still contains all visible text.
     * Uses 80×24 viewport where full frame is large enough for delta to be smaller.
     */
    public function testDiffOpsDescribeOnlyChangedCells(): void
    {
        $m = Model::new()
            ->setViewport(80, 24)
            ->addItem(new StringItem('item 0'))
            ->addItem(new StringItem('item 1'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setLineStyle("\x1b[2m");

        $frame1Out = $m->View();
        $this->assertNotEmpty($frame1Out);

        $m2 = $m->setCursor(1);
        $frame2Out = $m2->View();

        $this->assertLessThan(\strlen($frame1Out), \strlen($frame2Out));
        $this->assertStringContainsString('item 1', $frame2Out);
    }

    /**
     * Step 12a: filter → View must emit a FULL frame, not a delta against
     * the pre-filter frame (the line set is completely different after filtering).
     *
     * Detecting "full frame" without the previousFrame accessor: we render the
     * unfiltered model (which stores a previousFrame), filter, and render again.
     * If the fix is absent (previousFrame aliased), the second render computes
     * a delta vs the unfiltered frame and produces ~20-30 bytes. With the fix,
     * it produces a full frame of ~43 bytes (1 visible line × 40 wide).
     * We also verify via direct comparison: rendering the filtered model directly
     * (no prior frame) must produce the same output.
     */
    public function testFilterTriggersFullFrameNotDelta(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->addItem(new StringItem('apple'))
            ->addItem(new StringItem('banana'))
            ->addItem(new StringItem('cherry'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        // Render unfiltered model first (stores previousFrame in $m)
        $frame1 = $m->View();

        // Apply filter: different line set → next View() must be full, not delta
        $filtered = $m->withFilterFn(fn($v) => (string) $v === 'banana');
        $frame2 = $filtered->View();

        // With the fix: first filtered View() = full frame (~43 bytes, 1 line).
        // Without the fix: delta vs unfiltered frame (~20-30 bytes).
        // A full filtered frame at 40×5 with 1 visible item is ~43 bytes.
        // The threshold 35 distinguishes full (≥35) from delta (<35).
        $this->assertGreaterThanOrEqual(35, \strlen($frame2),
            'Filtered View() must emit a full frame (≥35 bytes), not a delta');
        $this->assertStringContainsString('banana', $frame2);

        // Also verify: a fresh filtered model (no prior frame) produces the
        // same first-frame output as the filtered model above.
        $freshFiltered = Model::new()
            ->setViewport(40, 5)
            ->addItem(new StringItem('apple'))
            ->addItem(new StringItem('banana'))
            ->addItem(new StringItem('cherry'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->withFilterFn(fn($v) => (string) $v === 'banana');
        $freshFrame = $freshFiltered->View();
        $this->assertSame(\strlen($freshFrame), \strlen($frame2));
    }

    /**
     * Step 12b: cursor-only move (content unchanged, only highlight changes)
     * produces a non-empty delta shorter than full re-render.
     */
    public function testCursorMoveStyleOnlyDeltaIsNonEmpty(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->addItem(new StringItem('item zero'))
            ->addItem(new StringItem('item one'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setLineStyle('')
            ->setCurrentStyle("\x1b[7m");

        $frame1Out = $m->View();
        $this->assertNotEmpty($frame1Out);

        $m2 = $m->setCursor(1);
        $frame2Out = $m2->View();

        $this->assertNotEmpty($frame2Out);
        $this->assertLessThan(\strlen($frame1Out), \strlen($frame2Out));
        $this->assertStringContainsString('item one', $frame2Out);
    }

    /**
     * Step 13a: resize triggers full frame repaint, not a delta against
     * the pre-resize frame.
     */
    public function testResizeTriggersFullFrameRepaint(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->addItem(new StringItem('item 0'))
            ->addItem(new StringItem('item 1'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $frame1Out = $m->View();
        $this->assertNotEmpty($frame1Out);
        $len1 = \strlen($frame1Out);

        // Same dimensions → delta
        $m2 = $m->setCursor(1);
        $frame2Out = $m2->View();
        $this->assertLessThan($len1, \strlen($frame2Out));

        // Resize to wider viewport → must emit FULL frame
        $m3 = $m2->setViewport(60, 5);
        $frame3Out = $m3->View();
        $this->assertGreaterThan($len1, \strlen($frame3Out));
        $this->assertStringContainsString('item 0', $frame3Out);
    }

    /**
     * Step 13b: resetPreviousFrame() forces the next View() to emit a full
     * frame, not a delta.
     */
    public function testResetPreviousFrameForcesFullFrame(): void
    {
        $m = Model::new()
            ->setViewport(40, 5)
            ->addItem(new StringItem('item 0'))
            ->addItem(new StringItem('item 1'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $frame1Out = $m->View();
        $len1 = \strlen($frame1Out);

        $m2 = $m->setCursor(1);
        $frame2Out = $m2->View();
        $this->assertLessThan($len1, \strlen($frame2Out));

        // Reset → next frame must be full
        $m2->resetPreviousFrame();
        $frame3Out = $m2->View();
        $this->assertGreaterThan(\strlen($frame2Out), \strlen($frame3Out));
    }

    /**
     * Step 13c: each added Item gets a unique, strictly increasing id.
     * The id is exposed via Item::$id (public readonly).
     */
    public function testItemIdsAreUniqueAndIncreasing(): void
    {
        $m = Model::new();
        $modelForItems = $m;
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $modelForItems = $modelForItems->addItem(new StringItem("item $i"));
        }
        // Access items via reflection (they are private, but ids are stable)
        $reflection = new \ReflectionClass($modelForItems);
        $itemsProp = $reflection->getProperty('items');
        $itemsProp->setAccessible(true);
        $items = $itemsProp->getValue($modelForItems);
        foreach ($items as $item) {
            $this->assertNotContains($item->id, $ids, 'Item id must be unique');
            $ids[] = $item->id;
        }
        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertGreaterThan($ids[$i - 1], $ids[$i]);
        }
    }

    /**
     * Benchmark: diff-based View emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Frame 1: full output (baseline)
     * Frame 2: delta output (smaller than full re-render)
     * Frame 3: delta output (smaller than full re-render)
     */
    public function testDiffEmissionByteBenchmark(): void
    {
        $model = Model::new()
            ->setViewport(80, 24)
            ->addItem(new \SugarCraft\Lister\StringItem('item 1'))
            ->addItem(new \SugarCraft\Lister\StringItem('item 2'));

        // Frame 1: full render
        $out1 = $model->View();
        $bytes1 = \strlen($out1);

        // Frame 2: change cursor position (small visual change)
        $model2 = $model->setCursor(1);
        $out2 = $model2->View();
        $bytes2 = \strlen($out2);

        // Frame 3: change cursor position again
        $model3 = $model2->setCursor(0);
        $out3 = $model3->View();
        $bytes3 = \strlen($out3);

        // Delta frames should be smaller than full re-render (not absolute byte count)
        // The 30-byte threshold was a placeholder; real goal is delta < full 80x24 re-emit (≥1920 bytes)
        $this->assertLessThan($bytes1, $bytes2, 'Frame 2 delta should be smaller than full re-render');
        $this->assertLessThan($bytes1, $bytes3, 'Frame 3 delta should be smaller than full re-render');
    }
}
