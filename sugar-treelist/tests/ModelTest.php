<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\TreeList\{DefaultPrefixer, DefaultSuffixer, FilterState, Lang, Model, Prefixer, ScoringProfile, StringItem, Suffixer};
use SugarCraft\TreeList\Tests\Support\RowDeltaAssertions;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    use RowDeltaAssertions;

    private Model $model;

    protected function setUp(): void
    {
        $this->model = Model::new()
            ->withViewport(80, 24)
            ->withCursorOffset(3);
    }

    public function testNewModelIsNotEmpty(): void
    {
        $m = Model::new();
        $this->assertSame(80, $m->width());
        $this->assertSame(24, $m->height());
        $this->assertSame(5, $m->cursorOffset());
    }

    public function testAddItem(): void
    {
        $m = $this->model->withItem(new StringItem('hello'));
        $this->assertSame(1, $m->length());
        $this->assertFalse($m->isEmpty());
        // Original model unchanged (immutable withItem)
        $this->assertSame(0, $this->model->length());
    }

    public function testFluentSetters(): void
    {
        $m = $this->model
            ->withWidth(100)
            ->withHeight(30)
            ->withViewport(120, 40)
            ->withCursorOffset(7)
            ->withWrap(5);

        $this->assertSame(120, $m->width());
        $this->assertSame(40, $m->height());
        $this->assertSame(7, $m->cursorOffset());
        $this->assertSame(5, $m->wrap());
    }

    public function testCursorNavigation(): void
    {
        $m = $this->model;
        foreach (['a', 'b', 'c'] as $v) {
            $m = $m->withItem(new StringItem($v));
        }

        $this->assertSame(0, $m->cursorIndex());
        $m = $m->withCursorDown();
        $this->assertSame(1, $m->cursorIndex());
        $m = $m->withCursorDown();
        $this->assertSame(2, $m->cursorIndex());
        $m = $m->withCursorUp();
        $this->assertSame(1, $m->cursorIndex());
    }

    public function testCursorClampAtBounds(): void
    {
        $m = $this->model->withItem(new StringItem('only'));
        $m->withCursorUp();  // should not go below 0
        $this->assertSame(0, $m->cursorIndex());
        $m->withCursorDown(100);  // should not exceed length-1
        $this->assertSame(0, $m->cursorIndex());
    }

    public function testRemoveItem(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }
        $this->assertSame(3, $this->model->length());

        $this->model = $this->model->removeItem(1);
        $this->assertSame(2, $this->model->length());
    }

    public function testClear(): void
    {
        foreach (['a', 'b'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }
        $this->assertSame(2, $this->model->length());
        $this->model = $this->model->clear();
        $this->assertTrue($this->model->isEmpty());
    }

    public function testSortWithLessFunc(): void
    {
        foreach (['z', 'a', 'm'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $this->model = $this->model
            ->withLessFunc(fn($a, $b) => \strcmp((string) $a, (string) $b))
            ->sort();

        // After sort, cursor stays on the same logical item ('z' was at cursor index 0).
        $this->assertSame('z', (string) $this->model->cursorItem());
        // The first visible line is 'a' (alphabetically first) but cursor is on 'z'.
        $this->assertSame('a', $this->model->lines()[0]);
    }

    public function testSortOnEmptyListStaysQuiet(): void
    {
        // Sorting an empty list must be a loud-free no-op: reading the (absent)
        // cursor item's id has to tolerate the gap, not warn about it.
        $model = $this->model->withLessFunc(fn($a, $b) => \strcmp((string) $a, (string) $b));
        $sorted = $model->sort();

        $this->assertTrue($sorted->isEmpty());
        $this->assertSame(0, $sorted->cursorIndex());
    }

    public function testFindItem(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $this->assertSame(1, $this->model->find(new StringItem('banana')));
        $this->assertSame(-1, $this->model->find(new StringItem('durian')));
    }

    /**
     * sugar-treelist-032: tryFind() completes the try* absence idiom — null
     * instead of the -1 sentinel, same search walk, and the misconfiguration
     * throws still name the door actually called.
     */
    public function testTryFindCompletesTheAbsenceIdiom(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $this->assertSame(1, $this->model->tryFind(new StringItem('banana')));
        $this->assertNull($this->model->tryFind('durian'), 'plain-string needle allowed');
        // Pairing law: find()==-1 exactly when tryFind()==null.
        foreach (['apple', 'durian'] as $needle) {
            $this->assertSame($this->model->find($needle) === -1, $this->model->tryFind($needle) === null);
        }

        $broken = Model::new()->withItem('x')->withEqualsFunc(static fn ($a, $b) => null);
        try {
            $broken->tryFind('x');
            $this->fail('null equalsFunc verdict accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Model::tryFind()', $e->getMessage());
        }
    }

    public function testFindWithEqualsFunc(): void
    {
        foreach (['aa', 'bb', 'cc'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $this->model = $this->model->withEqualsFunc(fn($a, $b) => (string) $a === (string) $b);
        $this->assertSame(0, $this->model->find(new StringItem('aa')));
        $this->assertSame(-1, $this->model->find(new StringItem('xx')));
    }

    public function testSetCursor(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $this->model = $this->model->withCursor(2);
        $this->assertSame(2, $this->model->cursorIndex());
    }

    public function testLinesThrowsOnEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testLinesThrowsOnZeroViewport(): void
    {
        $this->model->withItem(new StringItem('x'))->withViewport(0, 10);
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testViewReturnsString(): void
    {
        $m = $this->model
            ->withItem(new StringItem('item one'))
            ->withItem(new StringItem('item two'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        $view = $m->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('item one', $view);
        $this->assertStringContainsString('item two', $view);
    }

    public function testPrefixerInterface(): void
    {
        $m = $this->model
            ->withItem(new StringItem('test item'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

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
            ->withItem(new StringItem($longText))
            ->withViewport(20, 24)
            ->withWrap(3)
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        $lines = $m->lines();
        $this->assertLessThanOrEqual(3, \count($lines));
    }

    public function testAddItemReturnsNewInstance(): void
    {
        $m = $this->model->withItem(new StringItem('x'));
        // Returns a new instance, not the same object.
        $this->assertNotSame($this->model, $m);
        // Original is unchanged.
        $this->assertSame(0, $this->model->length());
        // New instance has the item.
        $this->assertSame(1, $m->length());
    }

    public function testAddItemMintsIdsOnTheCloneNeverTheReceiver(): void
    {
        $counter = new \ReflectionProperty(Model::class, 'idCounter');
        $items = new \ReflectionProperty(Model::class, 'items');

        // Mixed withItem/withItems chain on one model: every id in the list
        // stays unique and the stream keeps counting from the fork point.
        $m1 = $this->model->withItem(new StringItem('A'));
        $m2 = $m1->withItems(new StringItem('B'), new StringItem('C'));
        $m3 = $m2->withItem(new StringItem('D'));
        $ids = \array_map(
            static fn($item): int => $item->id,
            $items->getValue($m3)
        );
        $this->assertSame([0, 1, 2, 3], $ids);

        // Adversarial sibling fork (m2): two withItem calls on the SAME base.
        // The pre-fix `$id = $this->idCounter++` read the receiver's counter
        // and left it silently advanced; the base must emerge untouched.
        $base = $this->model->withItems(new StringItem('S1'), new StringItem('S2'));
        $before = $counter->getValue($base);
        $x = $base->withItem(new StringItem('X'));
        $y = $base->withItem(new StringItem('Y'));
        $this->assertSame($before, $counter->getValue($base), 'withItem mutated the receiver counter');
        // Each fork's own stream is well-formed: base ids 0,1 plus one new id.
        foreach ([$x, $y] as $fork) {
            $forkIds = \array_map(
                static fn($item): int => $item->id,
                $items->getValue($fork)
            );
            $this->assertSame([0, 1, 2], $forkIds);
        }
        // Ids are per-instance identity (class docblock): siblings may
        // re-mint the same id; no supported API merges forks, so a single
        // model never sees a duplicate.
        $this->assertNotSame($x, $y);
    }

    // -------------------------------------------------------------------------
    // Filter tests
    // -------------------------------------------------------------------------

    public function testWithFilterFnReturnsNewInstance(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => str_starts_with((string) $v, 'b'));

        // Original model unchanged
        $this->assertSame(3, $this->model->length());
        $this->assertNull($this->model->filterFn());
        $this->assertNull($this->model->filterState());

        // New model has filtered items
        $this->assertSame(1, $filtered->length());
        $this->assertSame('banana', (string) $filtered->cursorItem());
        $this->assertNotNull($filtered->filterFn());
        $this->assertSame(FilterState::filtering, $filtered->filterState());
    }

    public function testWithFilterFnFiltersItemsCorrectly(): void
    {
        foreach (['apple', 'apricot', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));

        $this->assertSame(2, $filtered->length());
        // Verify by navigating through cursorItem
        $this->assertSame('apple', (string) $filtered->cursorItem());
        $filtered = $filtered->withCursorDown();
        $this->assertSame('apricot', (string) $filtered->cursorItem());
    }

    public function testWithoutFilterRestoresOriginalItems(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'banana');
        $this->assertSame(2, $filtered->length());

        $restored = $filtered->withoutFilter();
        $this->assertSame(3, $restored->length());
        $this->assertNull($restored->filterFn());
        $this->assertSame(FilterState::unfiltered, $restored->filterState());
    }

    public function testWithoutFilterOnUnfilteredModelReturnsSelf(): void
    {
        $this->model = $this->model->withItem(new StringItem('apple'));
        $result = $this->model->withoutFilter();
        // On an unfiltered model, withoutFilter is a no-op and returns $this unchanged.
        $this->assertSame($this->model, $result);
    }

    public function testWithFilterFnCursorClampedToFilteredLength(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }
        $this->model = $this->model->withCursor(2);  // cursor on 'c'

        // Filter out 'c' — cursor should clamp to index 1 (last remaining item 'b')
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'c');
        $this->assertSame(2, $filtered->length());
        $this->assertSame(1, $filtered->cursorIndex());
    }

    public function testFilterStateIsFilteringAfterSet(): void
    {
        $this->model = $this->model->withItem(new StringItem('apple'));
        $this->assertNull($this->model->filterState());

        $filtered = $this->model->withFilterFn(fn() => true);
        $this->assertSame(FilterState::filtering, $filtered->filterState());
    }

    // ─── Additional edge cases ───────────────────────────────────────────────

    public function testCursorUpClampsToZero(): void
    {
        $m = $this->model->withItem(new StringItem('only'));
        $result = $m->withCursorUp(10);
        $this->assertSame(0, $result->cursorIndex());
    }

    public function testCursorDownClampsToLastIndex(): void
    {
        $m = $this->model;
        foreach (['a', 'b'] as $v) {
            $m = $m->withItem(new StringItem($v));
        }
        $m = $m->withCursor(0);
        $m = $m->withCursorDown(100);
        $this->assertSame(1, $m->cursorIndex());
    }

    public function testSetCursorOffsetIsIndependentOfLineOffset(): void
    {
        // lineOffset is now an internal scroll anchor, no longer aliased to cursorOffset.
        $m = $this->model->withCursorOffset(7);
        $this->assertSame(7, $m->cursorOffset());
        // lineOffset retains its default value (not overwritten by withCursorOffset).
        $this->assertSame(5, $m->lineOffset());
    }

    public function testSetLineStyle(): void
    {
        $m = $this->model->withLineStyle("\x1b[2m");
        $this->assertSame("\x1b[2m", $m->lineStyle());
    }

    public function testSetCurrentStyle(): void
    {
        $m = $this->model->withCurrentStyle("\x1b[1m");
        $this->assertSame("\x1b[1m", $m->currentStyle());
    }

    // --- Style escape-injection guard (SEC) --------------------------------

    public function testSetLineStyleAcceptsBareSgrCodes(): void
    {
        $m = $this->model
            ->withItem(new StringItem('cur'))
            ->withItem(new StringItem('row'))
            ->withLineStyle('1;31');
        $this->assertSame('1;31', $m->lineStyle());
        // lineStyle applies to non-current rows; the bare codes must render as a
        // well-formed SGR sequence (proving a legit "1;31" passes the guard).
        $this->assertStringContainsString("\x1b[1;31m", implode("\n", $m->lines()));
    }

    public function testSetLineStyleRejectsEscapeInjection(): void
    {
        // OSC title-set payload smuggled inside a "style" string.
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withLineStyle("\x1b]0;pwned\x07");
    }

    public function testSetCurrentStyleRejectsEscapeInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withCurrentStyle("1;31m\x1b]0;pwned");
    }

    public function testStylePropertiesAreNoLongerPubliclyWritable(): void
    {
        // Audit M7 fix wave: the fluent API is the only write surface. A plain
        // property write must now be an Error, not a silent guard bypass.
        $ref = new \ReflectionClass(Model::class);
        foreach (['width', 'height', 'cursorOffset', 'lineOffset', 'wrap',
                  'lessFunc', 'equalsFunc', 'prefixer', 'suffixer',
                  'lineStyle', 'currentStyle', 'filterFn', 'filterState'] as $field) {
            $prop = $ref->getProperty($field);
            $this->assertFalse($prop->isPublic(), "\${$field} must not be public");
        }

        $m = $this->model->withItem(new StringItem('row'));
        $this->expectException(\Error::class);
        $m->width = 1_000_000; // PHP 8 raises Error on inaccessible property write
    }

    public function testApplyStyleRejectsInjectionEvenViaReflectionWrite(): void
    {
        // Defense in depth (the original M7 hazard): even a worst-case write
        // straight into the private field must not splice a raw escape — the
        // render-time sgrCodes() guard re-validates at the splice point.
        $m = $this->model->withItem(new StringItem('row'));
        $prop = new \ReflectionProperty(Model::class, 'currentStyle');
        $prop->setValue($m, "\x1b]0;pwned\x07");
        $this->expectException(\InvalidArgumentException::class);
        $m->lines();
    }

    // --- Viewport dimension bounds (SEC) -----------------------------------

    public function testSetWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withWidth(-1);
    }

    public function testSetHeightRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withHeight(-1);
    }

    public function testSetWidthRejectsAbsurdlyLargeValue(): void
    {
        // A memory-exhaustion DoS: width×height cells would be allocated.
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withWidth(Model::MAX_DIMENSION + 1);
    }

    public function testSetViewportRejectsNegativeHeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withViewport(80, -5);
    }

    public function testSetWidthAllowsZeroAndUpperBound(): void
    {
        // Zero stays valid at the setter (lines() rejects a zero viewport at
        // render time); the upper bound itself is accepted.
        $this->assertSame(0, $this->model->withWidth(0)->width());
        $this->assertSame(Model::MAX_DIMENSION, $this->model->withWidth(Model::MAX_DIMENSION)->width());
    }

    public function testSortWithNullLessFuncReturnsSelf(): void
    {
        $this->model->withItem(new StringItem('z'));
        $result = $this->model->sort();
        $this->assertSame($this->model, $result);
    }

    public function testWithoutFilterOnModelWithNoFilterReturnsSelf(): void
    {
        $result = $this->model->withoutFilter();
        $this->assertSame($this->model, $result);
    }

    public function testRemoveItemAtInvalidIndexReturnsNewInstance(): void
    {
        $m = $this->model->withItem(new StringItem('item'));
        $result = $m->removeItem(99);
        // Returns a new instance (no-op clone) for referential transparency
        $this->assertNotSame($m, $result);
        // Content unchanged since index was invalid
        $this->assertSame(1, $result->length());
        $this->assertSame(1, $m->length());
    }

    public function testRemoveItemAtNegativeIndexReturnsNewInstance(): void
    {
        $m = $this->model->withItem(new StringItem('item'));
        $result = $m->removeItem(-1);
        // Returns a new instance (no-op clone) for referential transparency
        $this->assertNotSame($m, $result);
        // Content unchanged since index was invalid
        $this->assertSame(1, $result->length());
        $this->assertSame(1, $m->length());
    }

    public function testMultilineItemRendersMultipleLines(): void
    {
        $m = $this->model
            ->withItem(new StringItem("line1\nline2\nline3"))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

        $lines = $m->lines();
        $this->assertGreaterThan(1, count($lines));
    }

    public function testSetPrefixer(): void
    {
        $m = $this->model->withPrefixer(new DefaultPrefixer());
        $this->assertInstanceOf(DefaultPrefixer::class, $m->prefixer());
    }

    public function testSetSuffixer(): void
    {
        $m = $this->model->withSuffixer(new DefaultSuffixer());
        $this->assertInstanceOf(DefaultSuffixer::class, $m->suffixer());
    }

    public function testCursorItemThrowsOnEmptyList(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->model->cursorItem();
    }

    public function testLinesWithStyleApplied(): void
    {
        $m = $this->model
            ->withItem(new StringItem('styled'))
            ->withLineStyle("\x1b[2m")
            ->withCurrentStyle("\x1b[1m")
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    public function testFilterWithNoMatchingItems(): void
    {
        $this->model = $this->model->withItem(new StringItem('apple'));
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'apple');
        $this->assertSame(0, $filtered->length());
    }

    public function testFilterCursorsToLastItemWhenOnlyOneMatches(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model = $this->model->withItem(new StringItem($v));
        }
        $this->model = $this->model->withCursor(2); // cursor on 'c'
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
            ->withItem(new StringItem($longWord))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(20, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
        // The long word should be split into multiple lines
        $this->assertGreaterThan(1, \count($lines));
    }

    /**
     * Audit M5 regression pin: chunking counted GRAPHEMES while the fit test
     * measured CELLS — a CJK word of display width 16 passed unsplitted
     * through a 12-cell viewport. Splitting is now cell-budgeted.
     */
    public function testWideCharWordSplitsOnDisplayWidthNotGraphemeCount(): void
    {
        // 8 CJK ideographs = 16 display cells, viewport width 12, no prefixer.
        $m = Model::new()
            ->withViewport(12, 5)
            ->withItem(new StringItem('日本語日本語日'));

        $this->assertSame(['日本語日本語', '日'], $m->lines());
    }

    public function testLinesWithMultiParagraphTextExercisingHardWrap(): void
    {
        // Multi-paragraph text (contains \n) exercises hardWrap
        $m = $this->model
            ->withItem(new StringItem("para1\n\npara2"))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
    }

    public function testLinesWithWrapLimitExercisingRenderItemWrapLogic(): void
    {
        $multiline = "line1\nline2\nline3\nline4\nline5";
        $m = $this->model
            ->withItem(new StringItem($multiline))
            ->withWrap(2) // limit to 2 lines
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

        $lines = $m->lines();
        // Exact geometry (2026-11-03 strengthened pin — the old count-only
        // assertion accepted any <=2 rows, including zero): wrap=2 cuts the
        // five logical lines to two rendered rows; the first row carries the
        // box head, number column and cursor marker, the continuation row
        // carries the wrap glyph and no marker.
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('╭ 1 > line1', $lines[0]); // treelist-17: 1-based ordinal (was '╭ 0 ')
        $this->assertStringStartsWith('│     line2', $lines[1]);
    }

    public function testLinesWithContentWidthTooNarrowRefusesInsteadOfBlanks(): void
    {
        // sugar-treelist-008 disclosed flip: this geometry pinned a SILENT
        // single blank row (the old assertSame([''], $lines) — gutters ate
        // every column while the cursor navigated happily). Gutter overrun is
        // now a loud localized refusal, mirroring the zero-viewport contract.
        $m = $this->model
            ->withItem(new StringItem('word'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(5, 24);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('list.no_content_width'));
        $m->lines();
    }

    // -------------------------------------------------------------------------
    // Scroll-end frame geometry (viewport tail-collapse fix wave, 2026-11-03)
    // -------------------------------------------------------------------------

    /**
     * Invariant sweep: for every combination of height 1..10, cursor 0..6 and
     * cursorOffset {0, 2, 5, 100} over seven single-line items, lines() must
     * (a) never exceed the viewport height, (b) always contain the cursor
     * item's row, and (c) fill the frame whenever the list has enough rows.
     *
     * The old algorithm violated (b) for every cursor >= height at the list
     * tail and collapsed the end-of-list window to a single row; these
     * combinations reproduce that space exhaustively.
     */
    public function testScrollEndGeometrySweep(): void
    {
        $items = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ([1, 2, 3, 5, 10] as $height) {
            foreach ([0, 2, 5, 100] as $offset) {
                foreach (range(0, count($items) - 1) as $cursor) {
                    $m = Model::new()
                        ->withViewport(40, $height)
                        ->withCursorOffset($offset)
                        ->withItemsFromArray($items)
                        ->withCursor($cursor);
                    $lines = $m->lines();
                    $this->assertLessThanOrEqual($height, count($lines),
                        "frame overflow at h={$height} offset={$offset} cursor={$cursor}");
                    $this->assertContains($items[$cursor], $lines,
                        "cursor row missing at h={$height} offset={$offset} cursor={$cursor}");
                    $expected = min($height, count($items));
                    $this->assertCount($expected, $lines,
                        "frame not full at h={$height} offset={$offset} cursor={$cursor}");
                    $this->assertSame(count($lines), count(array_unique($lines)),
                        "duplicate row at h={$height} offset={$offset} cursor={$cursor}");
                    // Window is a contiguous slice of the list.
                    $indexes = array_map(fn(string $l): int => array_search($l, $items, true), $lines);
                    for ($i = 1; $i < count($indexes); $i++) {
                        $this->assertSame($indexes[$i - 1] + 1, $indexes[$i],
                            "non-contiguous window at h={$height} offset={$offset} cursor={$cursor}");
                    }
                }
            }
        }
    }

    /**
     * Multi-line items make the window LINE-based, not item-based: each item
     * occupies several rows and the cursor row (its item's first row) must
     * always be inside a full frame, including at the list tail.
     */
    public function testScrollEndGeometryWithMultiLineItems(): void
    {
        $m = Model::new()
            ->withViewport(20, 4)
            ->withCursorOffset(1)
            ->withItemsFromArray(["a1\na2", "b1\nb2", "c1\nc2"]);

        $lines = $m->lines();
        $this->assertCount(4, $lines);
        $this->assertSame(['a1', 'a2', 'b1', 'b2'], $lines);

        $tail = $m->withCursor(2);
        $tailLines = $tail->lines();
        $this->assertCount(4, $tailLines, 'tail frame must be full, not collapsed');
        $this->assertContains('c1', $tailLines, 'cursor item first row must be visible');
        // Scroll-end clamp: last four lines of [a1,a2,b1,b2,c1,c2] is [b1,b2,c1,c2].
        $this->assertSame(['b1', 'b2', 'c1', 'c2'], $tailLines);
    }

    /**
     * The stock-default shape from the report (ten items, no knob tweaks):
     * jumping the cursor to the end must show the whole short list with the
     * cursor row present — never the single-row frame the old code emitted.
     */
    public function testStockDefaultsToEndOfListShowsCursorAndContext(): void
    {
        $m = Model::new();
        foreach (range(0, 9) as $i) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $lines = $m->withCursorToEnd()->lines();

        $this->assertCount(10, $lines, 'ten short items fit the stock 24-row viewport');
        $this->assertContains('item 9', $lines, 'cursor row must be on screen');
        $this->assertSame('item 0', $lines[0]);
        $this->assertSame('item 9', $lines[9]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Steps 9-13: regression and correctness tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Step 9: regression — when cursor is on a non-zero index, the > marker
     * must appear on the cursor item, NOT only on index 0.
     *
     * 2026-11-03 RE-PIN (scroll-end geometry fix): with viewport 40x5 and
     * cursorOffset 2 the window now shows all three items (the old algorithm
     * collapsed the tail); asserted structurally — the marker rides the row
     * carrying the cursor item, and only that row — instead of a row index.
     */
    public function testMarkerOnNonZeroCursorItem(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withCursorOffset(2)
            ->withItem(new StringItem('item0'))
            ->withItem(new StringItem('item1'))
            ->withItem(new StringItem('item2'))
            ->withPrefixer(new DefaultPrefixer());

        // Cursor on item 2 → its first line has '>'
        $m2 = $m->withCursor(2);
        $lines = $m2->lines();
        $markerRows = array_filter($lines, fn(string $l): bool => str_contains($l, '>'));
        $this->assertCount(1, $markerRows, 'exactly one row may carry the cursor marker');
        $this->assertStringContainsString('item2', (string) reset($markerRows),
            'the marker row must be the cursor item\'s row');
        foreach ($lines as $line) {
            if (str_contains($line, 'item2')) {
                $this->assertStringContainsString('>', $line);
            } else {
                $this->assertStringNotContainsString('>', $line);
            }
        }
    }

    /**
     * Step 10: snapshot — View() at a fixed deterministic state produces
     * a well-formed ANSI output string with expected content and styling.
     */
    public function testViewSnapshotOutputIsDeterministic(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withCursorOffset(2)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCurrentStyle("\x1b[1m");

        $out = $m->view();
        $this->assertIsString($out);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString('apple', $out);
        $this->assertStringContainsString('banana', $out);
        $this->assertStringContainsString('cherry', $out);
        $this->assertStringContainsString("\x1b[1m", $out);
        $this->assertStringContainsString('╭', $out);
        // Second frame: moving the cursor rewrites exactly the two rows the
        // highlight touches, and replaying that delta over the first frame
        // rebuilds what a fresh instance paints. (2026-11-03 fix wave: this
        // pin used to assert "delta shorter than full", a size heuristic that
        // stayed green while the cell projection shipped SGR bytes as glyph
        // cells.)
        $m2 = $m->withCursor(1);
        $out2 = $m2->view();
        $this->assertIsString($out2);
        $this->assertDeltaWritesRows($out2, 2);
        $this->assertStringNotContainsString('cherry', $out2);
        $this->assertSame($this->snapshotFixture(1)->view(), $this->applyRowDelta($out, $out2));
    }

    /** Fresh 40×5/offset-2 fixture with the cursor on $cursor (memo-less). */
    private function snapshotFixture(int $cursor = 0): Model
    {
        return Model::new()
            ->withViewport(40, 5)
            ->withCursorOffset(2)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCurrentStyle("\x1b[1m")
            ->withCursor($cursor);
    }

    /**
     * Step 11: diff-correctness — a cursor-only move rewrites exactly the
     * rows whose bytes changed. The old pin asserted "delta shorter than
     * full"; with the row-granular delta the load-bearing property is
     * structural: unchanged rows are absent from the stream and the changed
     * rows replay to a byte-identical frame (2026-11-03 fix wave).
     */
    public function testDiffOpsDescribeOnlyChangedCells(): void
    {
        $build = static fn(int $cursor): Model => Model::new()
            ->withViewport(80, 24)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withItem(new StringItem('item 2'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle("\x1b[2m")
            ->withCursor($cursor);

        $m = $build(0);
        $frame1Out = $m->view();
        $this->assertNotEmpty($frame1Out);

        $m2 = $m->withCursor(1);
        $frame2Out = $m2->view();

        // Rows 0 and 1 swap faint/plain+marker; row 2 stays faint-unchanged.
        $this->assertDeltaWritesRows($frame2Out, 2);
        $this->assertStringNotContainsString('item 2', $frame2Out);
        $this->assertStringContainsString('item 1', $frame2Out);
        $this->assertDeltaRebuildsFullFrame($frame1Out, $frame2Out, $build(1));
    }

    /**
     * Step 12a: filter → View must emit a FULL frame, not a delta against
     * the pre-filter frame (the line set is completely different after filtering).
     *
     * Applying a filter clears the row memo on the new lineage, so the next
     * render paints every row — and must be byte-identical to a fresh
     * instance that never had a memo at all. (2026-11-03 fix wave: replaced
     * the old ≥35-byte "is it full" size heuristic with that byte-equality.)
     */
    public function testFilterTriggersFullFrameNotDelta(): void
    {
        $build = static fn(): Model => Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('banana'))
            ->withItem(new StringItem('cherry'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        // Render unfiltered model first (stores the row memo in $m)
        $m = $build();
        $m->view();

        // Apply filter: different line set → next View() must be full, not delta
        $filtered = $m->withFilterFn(fn($v) => (string) $v === 'banana');
        $frame2 = $filtered->view();

        $this->assertStringContainsString('banana', $frame2);

        // A fresh filtered model (no prior frame) produces byte-identical
        // first-frame output — proof this is a full paint, not a delta.
        $freshFrame = $build()->withFilterFn(fn($v) => (string) $v === 'banana')->view();
        $this->assertSame($freshFrame, $frame2);
    }

    /**
     * Step 12b: cursor-only move (content unchanged, only highlight changes)
     * produces a non-empty delta that rewrites exactly the two rows whose
     * bytes differ — and nothing else. The old pin tolerated "item" and
     * "one" scattered through escape noise; the row-granular delta writes
     * each changed row as one contiguous run (2026-11-03 fix wave).
     */
    public function testCursorMoveStyleOnlyDeltaIsNonEmpty(): void
    {
        $build = static fn(int $cursor): Model => Model::new()
            ->withViewport(40, 5)
            ->withItem(new StringItem('item zero'))
            ->withItem(new StringItem('item one'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle('')
            ->withCurrentStyle("\x1b[7m")
            ->withCursor($cursor);

        $m = $build(0);
        $frame1Out = $m->view();
        $this->assertNotEmpty($frame1Out);

        $m2 = $m->withCursor(1);
        $frame2Out = $m2->view();

        $this->assertNotEmpty($frame2Out);
        $this->assertDeltaWritesRows($frame2Out, 2);
        $this->assertStringContainsString('item one', $frame2Out);
        $this->assertDeltaRebuildsFullFrame($frame1Out, $frame2Out, $build(1));
    }

    /**
     * Step 13a: resize triggers full frame repaint, not a delta against
     * the pre-resize frame. (Byte-equality with a fresh instance at the new
     * width is the structural proof; the old pin only compared lengths.)
     */
    public function testResizeTriggersFullFrameRepaint(): void
    {
        $build = static fn(int $width, int $cursor): Model => Model::new()
            ->withViewport($width, 5)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withCursor($cursor);

        $m = $build(40, 0);
        $frame1Out = $m->view();
        $this->assertNotEmpty($frame1Out);

        // Same dimensions → delta over the two marker-touched rows.
        $m2 = $m->withCursor(1);
        $frame2Out = $m2->view();
        $this->assertDeltaWritesRows($frame2Out, 2);
        $this->assertDeltaRebuildsFullFrame($frame1Out, $frame2Out, $build(40, 1));

        // Resize to wider viewport → must emit the FULL frame at the new width.
        $m3 = $m2->withViewport(60, 5);
        $frame3Out = $m3->view();
        $this->assertSame($build(60, 1)->view(), $frame3Out);
        $this->assertStringNotContainsString("\x1b[", $frame3Out); // plain rows: full paint has no CUP preamble
    }

    /**
     * Step 13b: resetPreviousFrame() forces the next View() to emit a full
     * frame, not a delta.
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
        $frame1Out = $m->view();

        $m2 = $m->withCursor(1);
        $frame2Out = $m2->view();
        $this->assertDeltaWritesRows($frame2Out, 2);

        // Reset → next frame must be the full paint, byte-identical to a
        // fresh instance holding the same state.
        $m2->resetPreviousFrame();
        $frame3Out = $m2->view();
        $this->assertNotSame($frame2Out, $frame3Out);
        $this->assertSame($build(1)->view(), $frame3Out);
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
            $modelForItems = $modelForItems->withItem(new StringItem("item $i"));
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
            ->withViewport(80, 24)
            ->withItem(new \SugarCraft\TreeList\StringItem('item 1'))
            ->withItem(new \SugarCraft\TreeList\StringItem('item 2'));

        // Frame 1: full render
        $out1 = $model->view();
        $bytes1 = \strlen($out1);

        // Frame 2: change cursor position (small visual change)
        $model2 = $model->withCursor(1);
        $out2 = $model2->view();
        $bytes2 = \strlen($out2);

        // Frame 3: change cursor position again
        $model3 = $model2->withCursor(0);
        $out3 = $model3->view();
        $bytes3 = \strlen($out3);

        // Delta frames should be smaller than full re-render (not absolute byte count)
        // The 30-byte threshold was a placeholder; real goal is delta < full 80x24 re-emit (≥1920 bytes)
        $this->assertLessThan($bytes1, $bytes2, 'Frame 2 delta should be smaller than full re-render');
        $this->assertLessThan($bytes1, $bytes3, 'Frame 3 delta should be smaller than full re-render');
    }

    // ─── Untested public methods ─────────────────────────────────────────────

    public function testSetLineOffset(): void
    {
        $m = $this->model->withLineOffset(10);
        $this->assertSame(10, $m->lineOffset());
    }

    public function testAddItems(): void
    {
        $m = $this->model->withItems(
            new StringItem('a'),
            new StringItem('b'),
            new StringItem('c'),
        );
        $this->assertSame(3, $m->length());
        $this->assertSame('a', (string) $m->cursorItem());
    }

    public function testAddItemsFromArray(): void
    {
        // Strings should be auto-wrapped in StringItem
        $m = $this->model->withItemsFromArray(['apple', 'banana', 'cherry']);
        $this->assertSame(3, $m->length());
        $this->assertSame('apple', (string) $m->cursorItem());
    }

    public function testAddItemsFromArrayWithStringableObjects(): void
    {
        // Stringable objects should pass through directly
        $m = $this->model->withItemsFromArray([new StringItem('x'), new StringItem('y')]);
        $this->assertSame(2, $m->length());
    }

    public function testCursorPageUp(): void
    {
        // Use small height to make page movement more obvious
        $m = Model::new()->withViewport(80, 3);
        for ($i = 0; $i < 10; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $m = $m->withCursor(5);
        $this->assertSame(5, $m->cursorIndex());

        // Page up by 1: 5 - (3 * 1) = 2
        $m2 = $m->withCursorPageUp();
        $this->assertSame(2, $m2->cursorIndex());

        // Page up by 2: 2 - (3 * 2) = -4 → clamped to 0
        $m3 = $m2->withCursorPageUp(2);
        $this->assertSame(0, $m3->cursorIndex());
    }

    public function testCursorPageDown(): void
    {
        // Use small height to make page movement more obvious
        $m = Model::new()->withViewport(80, 3);
        for ($i = 0; $i < 10; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $m = $m->withCursor(1);
        $this->assertSame(1, $m->cursorIndex());

        // Page down by 1: 1 + 3 = 4
        $m2 = $m->withCursorPageDown();
        $this->assertSame(4, $m2->cursorIndex());

        // Page down again: 4 + 3 = 7
        $m3 = $m2->withCursorPageDown();
        $this->assertSame(7, $m3->cursorIndex());
    }

    public function testCursorPageDownClampsToEnd(): void
    {
        $m = $this->model;
        for ($i = 0; $i < 5; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $m = $m->withCursor(0)->withCursorPageDown(100);
        $this->assertSame(4, $m->cursorIndex()); // Last index
    }

    /**
     * sugar-treelist-024: pageSpan() uses the same max(1, height) factor its
     * own overflow guards measure against — a height-0 viewport (legal before
     * any resize; only the render doors refuse to paint it) pages one item
     * per page unit and saturates like the other movers, instead of silently
     * freezing PageUp/PageDown while Up/Down still walk the list.
     */
    public function testPageUpDownOnZeroHeightViewportSaturatesLikeOtherMovers(): void
    {
        $m = Model::new()
            ->withViewport(80, 0)
            ->withItemsFromArray(['a', 'b', 'c', 'd'])
            ->withCursor(2);

        $this->assertSame(1, $m->withCursorPageUp()->cursorIndex());
        $this->assertSame(0, $m->withCursorPageUp(5)->cursorIndex());
        $this->assertSame(3, $m->withCursorPageDown()->cursorIndex());
        $this->assertSame(3, $m->withCursorPageDown(5)->cursorIndex());
    }

    public function testCursorToStart(): void
    {
        $m = $this->model;
        for ($i = 0; $i < 3; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $m = $m->withCursor(2);
        $this->assertSame(2, $m->cursorIndex());

        $m2 = $m->withCursorToStart();
        $this->assertSame(0, $m2->cursorIndex());
    }

    public function testCursorToEnd(): void
    {
        $m = $this->model;
        for ($i = 0; $i < 3; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        // withCursorToEnd goes to the last item
        $m2 = $m->withCursorToEnd();
        $this->assertSame(2, $m2->cursorIndex());
    }

    public function testItemAt(): void
    {
        $m = $this->model;
        for ($i = 0; $i < 3; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }

        $this->assertSame('item 0', (string) $m->itemAt(0));
        $this->assertSame('item 1', (string) $m->itemAt(1));
        $this->assertSame('item 2', (string) $m->itemAt(2));
    }

    public function testItemAtThrowsOutOfBounds(): void
    {
        $m = $this->model->withItem(new StringItem('only one'));
        $this->expectException(\OutOfBoundsException::class);
        $m->itemAt(5);
    }

    public function testItemAtThrowsOnNegativeIndex(): void
    {
        $m = $this->model->withItem(new StringItem('only one'));
        $this->expectException(\OutOfBoundsException::class);
        $m->itemAt(-1);
    }

    public function testTryItemAt(): void
    {
        $m = $this->model;
        for ($i = 0; $i < 3; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }

        $this->assertSame('item 0', (string) $m->tryItemAt(0));
        $this->assertSame('item 1', (string) $m->tryItemAt(1));
        $this->assertNull($m->tryItemAt(99));
        $this->assertNull($m->tryItemAt(-1));
    }

    public function testGetItemIds(): void
    {
        $m = Model::new();
        for ($i = 0; $i < 3; $i++) {
            $m = $m->withItem(new StringItem("item $i"));
        }

        $ids = $m->itemIds();
        $this->assertCount(3, $ids);
        $this->assertNotContains($ids[0], \array_slice($ids, 1));
        // IDs should be strictly increasing
        $this->assertGreaterThan($ids[0], $ids[1]);
        $this->assertGreaterThan($ids[1], $ids[2]);
    }

    public function testEachLineReturnsGenerator(): void
    {
        $m = $this->model
            ->withItem(new StringItem('apple'))
            ->withItem(new StringItem('banana'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(80, 24);

        $stream = $m->eachLine();
        $this->assertInstanceOf(\Generator::class, $stream);

        $lines = [];
        foreach ($stream as $line) {
            $lines[] = $line;
        }
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('apple', $lines[0]);
        // sugar-treelist-029: the deprecated stream name is a one-way alias
        // — identical output until REMOVED-IN 2.0.0.
        $this->assertSame($lines, \iterator_to_array($m->linesStream()));
    }

    // ─── Edge cases for partial coverage ────────────────────────────────────

    public function testViewCatchesExceptionAndReturnsErrorMessage(): void
    {
        // View() catches exceptions from lines() and returns the error message as a string.
        // This tests the catch block in View() lines 680-683.
        $m = Model::new()
            ->withViewport(0, 0) // Will trigger RuntimeException in lines()
            ->withItem(new StringItem('x'));

        $out = $m->view();
        // Should return the error message string, not throw
        $this->assertIsString($out);
        $this->assertNotEmpty($out);
        // The error message should contain the viewport error text
        $this->assertStringContainsString('viewport', \strtolower($out));
    }

    public function testApplyStyleWithEmptyStyleReturnsOriginal(): void
    {
        // When style is empty, applyStyle should return the original string
        // (default lineStyle is already ''; withLineStyle('') proves the setter
        // path reaches the same early return).
        $m = $this->model->withItem(new StringItem('test'))->withLineStyle('');

        $lines = $m->lines();
        $this->assertNotEmpty($lines);
        $this->assertSame('test', $lines[0]);
    }

    /**
     * Scroll-end contract at the bottom of the list.
     *
     * 2026-11-03 RE-PIN (named disclosure, viewport tail-collapse fix): this
     * test previously pinned BROKEN geometry — viewport 40x5, offset 2, cursor
     * on 'item 4' of six single-line items rendered only 3 rows (items 3-5)
     * and the assertion froze that collapse as correct. Under the corrected
     * scroll-end clamp the window grows UPWARD when the list runs out below,
     * so the frame stays full: items 1-5 once each, item 0 out of the window.
     * The anti-duplication intent of the earlier fix wave is re-pinned below
     * and stays load-bearing.
     */
    public function testLinesViewportFollowBottomGap(): void
    {
        $m = Model::new()
            ->withViewport(40, 5)
            ->withCursorOffset(2)
            ->withItem(new StringItem('item 0'))
            ->withItem(new StringItem('item 1'))
            ->withItem(new StringItem('item 2'))
            ->withItem(new StringItem('item 3'))
            ->withItem(new StringItem('item 4'))
            ->withItem(new StringItem('item 5'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        // Cursor near the bottom: the frame keeps the cursor visible AND stays
        // full by backfilling from above — it never strands the list tail.
        $m2 = $m->withCursor(4);
        $lines = $m2->lines();
        $this->assertCount(5, $lines, 'frame must stay full at the list tail');
        $joined = \implode("\n", $lines);
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->assertSame(1, \substr_count($joined, "item $i"),
                "item $i must appear exactly once in the backfilled window");
        }
        $this->assertStringNotContainsString('item 0', $joined);
        $this->assertLessThan(\strpos($joined, 'item 5'), \strpos($joined, 'item 4'),
            'items keep ascending order in the backfilled window');
        $this->assertLessThan(\strpos($joined, 'item 4'), \strpos($joined, 'item 3'));
    }

    /**
     * Regression pin — exact repro of the reported end-of-list corruption:
     * 6 single-line items, viewport 40x10, cursorOffset 3, cursor at index 4
     * once produced ["D","E","F","F"] (F duplicated at every end-of-list
     * scroll). No prefixer/suffixer so lines are the bare item values.
     *
     * 2026-11-03 RE-PIN (named disclosure, viewport tail-collapse fix): the
     * intermediate remedy clipped the frame to ["D","E","F"], which hid the
     * rest of the list although the viewport had room for all ten rows. The
     * corrected scroll-end clamp shows the whole six-item list exactly once;
     * the no-duplication contract this pin exists for is asserted directly.
     */
    public function testViewportFollowEndOfListDoesNotDuplicateTail(): void
    {
        $m = Model::new()
            ->withViewport(40, 10)
            ->withCursorOffset(3)
            ->withItemsFromArray(['A', 'B', 'C', 'D', 'E', 'F'])
            ->withCursor(4);

        $lines = $m->lines();
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $lines);
        $this->assertSame(count($lines), count(array_unique($lines)),
            'no item may be re-emitted at the end-of-list scroll');
    }

    /**
     * Audit M2 regression pin: re-applying a filter (filter-as-you-type) must
     * not clobber the pristine originalItems — withoutFilter() restores ALL 5.
     */
    public function testFilterReapplicationKeepsPristineOriginals(): void
    {
        $m = Model::new()->withItemsFromArray(['apple', 'banana', 'apricot', 'cherry', 'avocado']);
        $first = $m->withFilterFn(fn(\Stringable $v) => \str_starts_with((string) $v, 'a'));
        $second = $first->withFilterFn(fn(\Stringable $v) => \str_starts_with((string) $v, 'ap'));

        $this->assertSame(3, $first->length());
        $this->assertSame(2, $second->length());

        $restored = $second->withoutFilter();
        $this->assertSame(5, $restored->length(), 'two-stage filter must still restore all originals');
        $this->assertSame([0, 1, 2, 3, 4], $restored->itemIds());
        $this->assertSame(FilterState::unfiltered, $restored->filterState());
    }

    /**
     * Audit M8 hardening pin: individually valid axes must not multiply into a
     * memory-exhaustion viewport.
     */
    public function testViewportAreaGuard(): void
    {
        // Both axes ≤ MAX_DIMENSION but the product is astronomic → rejected.
        $this->expectException(\InvalidArgumentException::class);
        Model::new()->withViewport(Model::MAX_DIMENSION, Model::MAX_DIMENSION);
    }

    public function testViewportAreaBoundaryAcceptsFourMillionCells(): void
    {
        // Exactly at the cap: accepted. Individually-large-but-realistic
        // default-height viewport (100000×24 = 2.4M) also stays legal, keeping
        // the existing MAX_DIMENSION upper-bound pin green.
        $m = Model::new()->withViewport(2000, 2000);
        $this->assertSame(2000, $m->width());
        $this->assertSame(2000, $m->height());
        $this->assertSame(Model::MAX_DIMENSION, Model::new()->withWidth(Model::MAX_DIMENSION)->width());
    }

    public function testSetHeightRejectsAreaOverflowAgainstCurrentWidth(): void
    {
        $m = Model::new()->withViewport(1900, 10);
        $this->expectException(\InvalidArgumentException::class);
        $m->withHeight(3000); // 1900×3000 = 5.7M > 4M
    }

    // -------------------------------------------------------------------------
    // Geometry-knob validation (2026-11-03: negatives were accept-then-misbehave)
    // -------------------------------------------------------------------------

    public function testSetCursorOffsetRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withCursorOffset(-1);
    }

    public function testSetLineOffsetRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withLineOffset(-1);
    }

    public function testSetWrapRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->withWrap(-1);
    }

    public function testSetCursorOffsetAcceptsZero(): void
    {
        $this->assertSame(0, $this->model->withCursorOffset(0)->cursorOffset());
    }

    // -------------------------------------------------------------------------
    // Filter is a live view: mutations never silently lost (2026-11-03)
    // -------------------------------------------------------------------------

    public function testAddingUnderFilterIsProjectedThroughThePredicate(): void
    {
        $m = $this->model->withItemsFromArray(['apple', 'banana', 'cherry']);
        $m = $m->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));
        $this->assertSame(1, $m->length());

        // A matching add lands in the projection…
        $m = $m->withItem(new StringItem('apricot'));
        $this->assertSame(2, $m->length());

        // …a non-matching add is NOT shown (old behavior: predicate bypassed),
        // yet it is NOT lost: widening the predicate reveals it.
        $m = $m->withItem(new StringItem('DROPME'));
        $this->assertSame(2, $m->length());
        $widened = $m->withFilterFn(fn($v) => !str_starts_with((string) $v, 'b'));
        $this->assertSame(4, $widened->length());
        $restored = $m->withoutFilter();
        $this->assertSame(['apple', 'banana', 'cherry', 'apricot', 'DROPME'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($restored)
        ));
    }

    public function testClearUnderFilterDoesNotResurrectItems(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b', 'c'])->withFilterFn(
            fn($v) => (string) $v !== 'b'
        );
        $this->assertSame(2, $m->length());

        $cleared = $m->clear();
        $this->assertTrue($cleared->isEmpty());
        $this->assertTrue($cleared->withoutFilter()->isEmpty());
    }

    public function testRemoveUnderFilterRemovesFromThePristineList(): void
    {
        $m = $this->model->withItemsFromArray(['apple', 'banana', 'cherry'])->withFilterFn(
            fn($v) => (string) $v !== 'cherry'
        );
        $this->assertSame(['apple', 'banana'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($m)
        ));

        $m = $m->removeItem(0); // removes the VISIBLE 'apple'
        $this->assertSame(['banana'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($m)
        ));

        // Lifting the filter must NOT restore the removed item — only the
        // filtered-away 'cherry' comes back.
        $restored = $m->withoutFilter();
        $this->assertSame(['banana', 'cherry'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($restored)
        ));
    }

    public function testSortUnderFilterSurvivesLiftingTheFilter(): void
    {
        $m = $this->model
            ->withLessFunc(fn($a, $b) => strcmp((string) $a, (string) $b))
            ->withItemsFromArray(['z', 'a', 'm'])
            ->withFilterFn(fn($v) => (string) $v !== 'm');
        $this->assertSame(['z', 'a'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($m)
        ));

        $sorted = $m->sort();
        $this->assertSame(['a', 'z'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($sorted)
        ));

        // withoutFilter() used to restore the UNSORTED pristine copy; the sort
        // now lands on the authoritative list.
        $restored = $sorted->withoutFilter();
        $this->assertSame(['a', 'm', 'z'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($restored)
        ));
    }

    public function testBatchAddsUnderFilterAreProjected(): void
    {
        $m = $this->model->withItemsFromArray(['keep'])->withFilterFn(
            fn($v) => str_starts_with((string) $v, 'keep')
        );
        $m = $m->withItems(new StringItem('keep2'), new StringItem('drop'));
        $this->assertSame(['keep', 'keep2'], array_map(
            static fn(\Stringable $v): string => (string) $v,
            $this->valuesOf($m)
        ));
        $this->assertSame(3, $m->withoutFilter()->length());
    }

    // -------------------------------------------------------------------------
    // Cursor identity across removals (2026-11-03: was index-clamping)
    // -------------------------------------------------------------------------

    public function testCursorFollowsItsItemWhenARowAboveIsRemoved(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b', 'c', 'd'])->withCursor(2); // on 'c'
        $m = $m->removeItem(1);                                                    // remove 'b'

        // Old behavior: cursor stayed at index 2 and silently named 'd'.
        $this->assertSame(1, $m->cursorIndex());
        $this->assertSame('c', (string) $m->cursorItem());
    }

    public function testCursorStaysInPlaceWhenItsOwnItemIsRemoved(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b', 'c', 'd'])->withCursor(1); // on 'b'
        $m = $m->removeItem(1);                                                    // remove 'b'

        // Same position, now holding the following item (listbox convention).
        $this->assertSame(1, $m->cursorIndex());
        $this->assertSame('c', (string) $m->cursorItem());
    }

    public function testCursorClampsWhenLastItemIsRemovedFromUnderIt(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b'])->withCursor(1); // on 'b' (last)
        $m = $m->removeItem(1);

        $this->assertSame(0, $m->cursorIndex());
        $this->assertSame('a', (string) $m->cursorItem());
    }

    public function testCursorSurvivesRemovalBelowIt(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b', 'c'])->withCursor(0); // on 'a'
        $m = $m->removeItem(2);

        $this->assertSame(0, $m->cursorIndex());
        $this->assertSame('a', (string) $m->cursorItem());
    }

    // -------------------------------------------------------------------------
    // Saturating cursor arithmetic (2026-11-03: PHP_INT_MAX overflowed to float
    // and threw a TypeError under strict_types)
    // -------------------------------------------------------------------------

    public function testCursorMovesSaturateInsteadOfOverflowing(): void
    {
        $m = $this->model->withItemsFromArray(['a', 'b', 'c', 'd']);

        $this->assertSame(3, $m->withCursor(0)->withCursorDown(\PHP_INT_MAX)->cursorIndex());
        $this->assertSame(0, $m->withCursor(3)->withCursorUp(\PHP_INT_MAX)->cursorIndex());
        $this->assertSame(0, $m->withCursor(2)->withCursorDown(\PHP_INT_MIN)->cursorIndex());
        $this->assertSame(3, $m->withCursor(2)->withCursorUp(\PHP_INT_MIN)->cursorIndex());

        $tall = $m->withViewport(80, 24);
        $this->assertSame(3, $tall->withCursor(0)->withCursorPageDown(\PHP_INT_MAX)->cursorIndex());
        $this->assertSame(0, $tall->withCursor(3)->withCursorPageUp(\PHP_INT_MAX)->cursorIndex());
    }

    public function testTryCursorItemIsNullOnEmptyList(): void
    {
        $this->assertNull($this->model->tryCursorItem());

        $m = $this->model->withItem(new StringItem('solo'));
        $this->assertSame('solo', (string) $m->tryCursorItem());
    }

    // -------------------------------------------------------------------------
    // Whitespace-faithful wrapping (2026-11-03: the old /\s+/ splitter ate
    // indentation and interior runs; empty paragraph lines vanished entirely)
    // -------------------------------------------------------------------------

    public function testIndentationAndInteriorRunsSurviveWrapping(): void
    {
        $m = $this->model->withItem(new StringItem("    child-a   second\n  grandchild-b"));

        $this->assertSame(['    child-a   second', '  grandchild-b'], $m->lines());
    }

    public function testEmptyParagraphLinesRenderAsEmptyRows(): void
    {
        $m = $this->model->withItem(new StringItem("para1\n\npara2"));

        $this->assertSame(['para1', '', 'para2'], $m->lines());
    }

    public function testTabsBecomeSpacesAndRowsStayWithinBudget(): void
    {
        $m = $this->model->withViewport(20, 10)->withItem(new StringItem("x\ty"));

        $this->assertSame(['x y'], $m->lines());
    }

    public function testCarriageReturnsNeverReachRenderedRows(): void
    {
        // CR neutralization was accidental under the old splitter (it was a
        // /\s+/ delimiter); sweepForFrame() folds CRs at the frame choke now.
        // The old claim that the sanitize sweep STRIPPED CR was false — bare
        // CR survives untrusted() by design — so this pins the fold itself.
        $m = $this->model->withViewport(20, 10)->withItem(new StringItem("a\r\nb"));

        foreach ($m->lines() as $row) {
            $this->assertStringNotContainsString("\r", $row);
        }
        $this->assertCount(2, $m->lines());
    }

    public function testMidContentCarriageReturnSplitsTheItem(): void
    {
        // A lone CR (no following LF) must become a line break: the content
        // SPLITS into two rows, it never splices into "visibleHIDDEN" and no
        // raw CR rides the frame to overwrite earlier columns.
        $m = $this->model->withViewport(20, 10)->withItem(new StringItem("visible\rHIDDEN"));

        $this->assertSame(['visible', 'HIDDEN'], $m->lines());
        $this->assertStringNotContainsString("\r", $m->view());
    }

    public function testCarriageLineFeedPairFoldsToOneLineBreak(): void
    {
        // CRLF must yield ONE break, not two rows plus an empty — this leg
        // discriminates a reversed fold order (["\r"] first would leave a
        // dangling "\n" and split "head\r\ntail" into three rows).
        $m = $this->model->withViewport(20, 10)->withItem(new StringItem("head\r\ntail"));

        $this->assertSame(['head', 'tail'], $m->lines());
    }

    public function testErrorFrameCarriesNoRawCarriageReturn(): void
    {
        // The catch-return in view() is frame content too: an exception
        // message with CRs must not emit a cursor-return overwrite either.
        $hostile = new class implements Suffixer {
            public function initSuffixer(
                \Stringable $value,
                int $currentIndex,
                int $cursorIndex,
                int $lineOffset,
                int $width,
                int $height,
            ): int {
                return 0;
            }

            public function suffix(int $currentLine, int $totalLines): string
            {
                throw new \RuntimeException("layout\rbroke\r\nnow");
            }
        };

        $frame = $this->model
            ->withViewport(20, 10)
            ->withItem(new StringItem('anything'))
            ->withSuffixer($hostile)
            ->view();

        $this->assertStringNotContainsString("\r", $frame);
        $this->assertStringContainsString('layout', $frame);
        $this->assertStringContainsString('broke', $frame);

        // treelist-03: a caught frame is HEIGHT TALL. The thrown message
        // "layout\rbroke\r\nnow" sweeps into three message rows (CR folds to
        // LF — content SPLITS), and the frame addresses all 10 viewport rows
        // through the delta path's CUP+EL scheme so the 24-row frame it
        // replaces is rewritten or erased row by row; the trailing "\n" of
        // the last row makes the explode see 11 cells.
        $frameRows = \explode("\n", $frame);
        $this->assertCount(11, $frameRows, 'a caught frame is exactly height rows plus the trailing terminator');
        $this->assertSame("\x1b[1;1Hlayout\x1b[K", $frameRows[0], 'message row 1 rides the absolute CUP/erase-line addressing');
        $this->assertSame("\x1b[2;1Hbroke\x1b[K", $frameRows[1], 'CR-folded message row 2');
        $this->assertSame("\x1b[3;1Hnow\x1b[K", $frameRows[2], 'CR-folded message row 3');
        foreach (\range(3, 9) as $i) {
            $this->assertSame("\x1b[" . ($i + 1) . ";1H\x1b[K", $frameRows[$i], "viewport row " . ($i + 1) . ' is an erase-only cleared row');
        }
        $this->assertSame('', $frameRows[10], 'the frame terminates with a trailing "\n"');
    }

    public function testCaughtFrameLogsTheRawMessageThroughThePsr3Arm(): void
    {
        // view()'s public LoggerInterface arm (sugar-treelist-019) was
        // zero-tested. A recording PSR-3 double pins: exactly one WARNING,
        // and the context carries the RAW exception message — the frame is
        // swept, but the log channel must not lose the original bytes.
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $hostile = new class implements Suffixer {
            public function initSuffixer(
                \Stringable $value,
                int $currentIndex,
                int $cursorIndex,
                int $lineOffset,
                int $width,
                int $height,
            ): int {
                return 0;
            }

            public function suffix(int $currentLine, int $totalLines): string
            {
                throw new \RuntimeException("boom\raw");
            }
        };

        $frame = $this->model
            ->withViewport(20, 4)
            ->withItem(new StringItem('anything'))
            ->withSuffixer($hostile)
            ->view($logger);

        $this->assertCount(1, $logger->records, 'a caught frame warns exactly once');
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame(
            ['message' => "boom\raw"],
            $logger->records[0]['context'],
            'the logger context carries the raw, un-swept exception message',
        );
        $this->assertStringNotContainsString("\r", $frame, 'the raw message never rides the frame — only the log does');
        $this->assertStringContainsString('boom', $frame);

        // The arm is opt-in: rendering a healthy frame with a logger attached
        // records nothing.
        $logger->records = [];
        $this->model->withViewport(20, 4)->withItem(new StringItem('fine'))->view($logger);
        $this->assertSame([], $logger->records, 'a successful frame never touches the logger');
    }

    public function testShrinkToEmptyLeavesNoGhostRowsOverA24RowPaint(): void
    {
        // The sugar-treelist-003 reproducer: a 24-row list painted, then
        // cleared. Every viewport row of the caught empty frame must carry
        // its own CUP address + erase-line, so no row of the old list can
        // ghost through (the pre-fix one-line return erased zero of them).
        $m = $this->model->withViewport(20, 24);
        for ($i = 1; $i <= 24; $i++) {
            $m = $m->withItem(new StringItem("row $i"));
        }
        $painted = $m->view();
        $this->assertCount(25, \explode("\n", $painted), 'the healthy frame is 24 rows plus the terminator');

        $emptyFrame = $m->clear()->view();
        $rows = \explode("\n", $emptyFrame);
        $this->assertCount(25, $rows, 'the caught frame keeps the viewport-height invariant');
        for ($row = 1; $row <= 24; $row++) {
            $this->assertStringStartsWith("\x1b[$row;1H", $rows[$row - 1], "viewport row $row is addressed — it cannot ghost");
            $this->assertStringEndsWith("\x1b[K", $rows[$row - 1], "viewport row $row erases its stale tail");
        }
        $this->assertStringContainsString('no items', $rows[0], 'the message owns the first row');
        foreach (\range(1, 23) as $i) {
            $this->assertSame("\x1b[" . ($i + 1) . ";1H\x1b[K", $rows[$i], 'only erase-only cleared rows stand below the message');
        }
        $this->assertSame('', $rows[24], 'the frame terminates with a trailing "\n"');
    }

    public function testGutterRefusalPaintsTheHeightTallErrorFrame(): void
    {
        // The refusal rides the caught-frame path (sugar-treelist-003
        // machinery): view() never throws, so at width 7 — where shipped
        // defaults leave contentWidth 0 — the frame is the localized
        // sentence rewritten across the full viewport height, never blanks.
        $m = $this->model
            ->withItem(new StringItem('main.php'))
            ->withItem(new StringItem('lib.php'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(7, 3);

        $frame = $m->view();
        $rows  = \explode("\n", $frame);
        $this->assertCount(4, $rows, 'the caught frame keeps the viewport-height invariant');
        $this->assertSame(
            "\x1b[1;1H" . Lang::t('list.no_content_width') . "\x1b[K",
            $rows[0],
            'row 1 carries the localized refusal sentence',
        );
        $this->assertSame("\x1b[2;1H\x1b[K", $rows[1]);
        $this->assertSame("\x1b[3;1H\x1b[K", $rows[2]);
    }

    public function testEachLinePropagatesTheGutterRefusal(): void
    {
        // eachLine() shares lines()'s eager door: the refusal is raised on
        // first consumption, exactly like lines().
        $m = $this->model
            ->withItem(new StringItem('main.php'))
            ->withItem(new StringItem('lib.php'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(7, 3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('list.no_content_width'));
        \iterator_to_array($m->eachLine());
    }

    /**
     * sugar-treelist-057: the scroll-end backfill continues the upward walk
     * on the SAME call's render memo — the stop-mid-item boundary line was
     * laid out twice per frame (walk + backfill). The counter item's
     * __toString runs exactly once for a fully reused render; dropping the
     * ??= memo doubles it.
     */
    public function testBackfillReusesTheWalksRenderedLines(): void
    {
        $counter = new class implements \Stringable {
            public int $casts = 0;
            public function __toString(): string
            {
                $this->casts++;
                return "head\nfoot";
            }
        };

        // Geometry that forces walk+backfill overlap: one 2-line item above
        // a 1-line cursor at the list end, height 3, topGap 1 (the default
        // cursorOffset 5 would let the walk consume the whole item and the
        // backfill never runs) — the walk takes only "foot", the backfill
        // later needs "head" from the SAME item index.
        $m = Model::new()->withItem($counter)->withItem(new StringItem('cur'))
            ->withViewport(20, 3)->withCursorOffset(1)->withCursor(1);

        $lines = $m->lines();
        $this->assertSame(['head', 'foot', 'cur'], $lines);
        $this->assertSame(1, $counter->casts, 'one renderItem per index per lines() call');
    }

    /**
     * sugar-treelist-059: a computed Stringable needle pays its cast once
     * per find()/tryFind(), not once per item compared. Re-casting inside
     * the loop makes the worst case O(n) __toString calls.
     */
    public function testComputedNeedleIsCastOncePerSearch(): void
    {
        $needle = new class implements \Stringable {
            public int $casts = 0;
            public function __toString(): string
            {
                $this->casts++;
                return 'c';
            }
        };

        $m = $this->model->withItems('a', 'b', 'c', 'd', 'e');

        $this->assertSame(2, $m->find($needle));
        $this->assertSame(1, $needle->casts, 'find() hoists the needle cast');

        $this->assertSame(2, $m->tryFind($needle));
        $this->assertSame(2, $needle->casts, 'tryFind() shares the hoisted door');
    }

    /**
     * sugar-treelist-060: view() on an empty list builds the height-filled
     * prose frame directly — no throw/catch machinery on a normal
     * filter-as-you-type state — while lines() KEEPS throwing on the same
     * state: the array door promises content, the frame door promises a
     * frame. Byte contract is the t03-era golden above.
     */
    public function testEmptyViewAndThrowingLinesRemainPairedDoors(): void
    {
        $m = $this->model->withViewport(20, 3)->withItem(new StringItem('solo'));
        $m->view(); // seed the delta memo so the empty arm must drop it, as the caught arm did

        $empty = $m->clear();
        $rows = \explode("\n", $empty->view());
        $this->assertCount(4, $rows, 'the direct empty arm keeps the viewport-height invariant');
        $this->assertSame(
            "\x1b[1;1H" . Lang::t('list.no_items') . "\x1b[K",
            $rows[0],
            'row 1 carries the localized sentence, byte-identical to the caught-frame golden',
        );

        // Refilling after an empty frame must repaint in full (memo dropped
        // by the empty arm too), exactly like the pre-060 caught path.
        $refill = $empty->withItem(new StringItem('back'));
        $this->assertSame("back\n", $refill->view(), 'the memo drop forces a full repaint — bare line bytes, no CUP addressing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('list.no_items'));
        $empty->lines();
    }

    public function testSingleContentColumnAtTheBoundaryStillRenders(): void
    {
        // Boundary geometry: width 8 with shipped gutters leaves exactly one
        // content column. That is NOT a refusal — content survives, one cell
        // per row, never a blank.
        $m = $this->model
            ->withItem(new StringItem('ab'))
            ->withItem(new StringItem('cd'))
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withViewport(8, 24);

        $lines = $m->lines();
        // treelist-17: numbers are 1-based ordinals by default (was 0/1).
        $this->assertSame(['╭ 1 > a<', '│     b', '╰ 2   c', '│     d'], $lines);
        foreach ($lines as $row) {
            $this->assertNotSame('', $row);
            $this->assertLessThanOrEqual(8, Width::string($row));
        }
    }

    /**
     * sugar-treelist-039 (strict arm): a single grapheme wider than the whole
     * content column cannot be split. At contentWidth 1 a 2-cell character
     * used to render an over-wide row (pre-clamp era), then the t06 clamp
     * traded that for a silently EMPTY row — data loss. The shipped contract
     * now substitutes a 1-cell '?' marker: the row fits, and the geometry is
     * never bought by erasing caller data.
     */
    public function testUnsplittableWideGraphemeIsSubstitutedNotErasedAtWidthOne(): void
    {
        $m = Model::new()->withWidth(1)->withHeight(4)->withItem('中');
        $lines = $m->lines();

        $this->assertSame(['?'], $lines, 'substituted, never silently empty');
        $this->assertSame(1, Width::string($lines[0]), 'the row honors the 1-cell budget');

        // A genuinely empty item is NOT substituted — the marker is for
        // eaten content only.
        $this->assertSame([''], Model::new()->withWidth(1)->withHeight(4)->withItem('')->lines());
    }

    public function testGutterlessNarrowViewportIsNotARefusal(): void
    {
        // The refusal is about the GUTTERS eating the frame, not about narrow
        // viewports: with no prefixer/suffixer attached, a one-cell viewport
        // still renders its content.
        $lines = $this->model
            ->withItem(new StringItem('x'))
            ->withViewport(1, 3)
            ->lines();
        $this->assertSame(['x'], $lines);
    }

    public function testHardWrapMirrorRefusesAZeroWidthBudgetDirectly(): void
    {
        // Defense-in-depth mirror pin (sugar-treelist-008): renderItem guards
        // the geometry upstream, so hardWrap's own refusal is only reachable
        // through this private door — reached directly it must refuse too,
        // never return the historical [''] blank.
        $method = new \ReflectionMethod($this->model, 'hardWrap');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('list.no_content_width'));
        $method->invoke($this->model, 'text', 0);
    }

    public function testDocumentedPrefixerSnippetHoldsTheViewport(): void
    {
        // sugar-treelist-009/038 acceptance: the README 'Custom Prefixer'
        // snippet, run verbatim at width 20 with a 20-cell item, keeps every
        // row inside the viewport — the reservation is derived through the
        // Width oracle instead of hand-typed (the old 'return 0' example put
        // 22-cell rows through a 20-wide pane).
        $prefixer = new class implements Prefixer {
            public function initPrefixer(
                \Stringable $value, int $currentIndex, int $cursorIndex,
                int $lineOffset, int $width, int $height, int $totalItems
            ): int {
                return Width::string('• ');
            }

            public function prefix(int $currentLine, int $totalLines): string
            {
                return $currentLine === 0 ? '• ' : '  ';
            }
        };
        $lines = $this->model
            ->withItem(new StringItem(\str_repeat('x', 20)))
            ->withPrefixer($prefixer)
            ->withViewport(20, 24)
            ->lines();
        $this->assertNotEmpty($lines);
        foreach ($lines as $row) {
            $this->assertLessThanOrEqual(20, Width::string($row), 'no row overruns the documented geometry');
        }
    }

    // -------------------------------------------------------------------------
    // Nullable callback returns — one stated policy (sugar-treelist-011/025):
    // the slot is optional, but an installed callback must return its
    // declared type; a null verdict is refused by name, never coerced or
    // laundered into a distant TypeError.
    // -------------------------------------------------------------------------

    public function testLessFuncReturningNullIsRefusedByNameNotATypeError(): void
    {
        // The finding's reproducer: setLessFunc(fn(...): ?int => null)->sort()
        // used to die inside usort with 'Return value must be of type int,
        // null returned' — far from the misconfigured setter.
        $m = $this->model
            ->withItem(new StringItem('b'))
            ->withItem(new StringItem('a'))
            ->withLessFunc(static fn ($x, $y): ?int => null);
        try {
            $m->sort();
            $this->fail('a null comparison verdict must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('lessFunc', $e->getMessage());
        }
    }

    public function testEqualsFuncReturningNullIsRefusedByName(): void
    {
        // Null no longer reads as a silent 'not equal' in find()'s if-context.
        $m = $this->model
            ->withItem(new StringItem('needle'))
            ->withEqualsFunc(static fn ($x, $y): ?bool => null);
        try {
            $m->find(new StringItem('needle'));
            $this->fail('a null equality verdict must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('equalsFunc', $e->getMessage());
        }
    }

    public function testFilterFnReturningNullIsRefusedNotSilentlyDropped(): void
    {
        // Disclosed flip: the old (bool) coercion made a null verdict silently
        // exclude the item; the setter's own contract ('returns bool') is now
        // enforced at the call site.
        $m = $this->model->withItem(new StringItem('anything'));
        try {
            $m->withFilterFn(static fn ($x): ?bool => null);
            $this->fail('a null filter verdict must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('filterFn', $e->getMessage());
        }
    }

    public function testHealthyCallbackVerdictsAreUnaffectedByThePolicy(): void
    {
        // The guards refuse null only: int verdicts still order, bool false
        // still means 'not equal', bool truthiness still filters.
        $sorted = $this->model
            ->withItem(new StringItem('b'))
            ->withItem(new StringItem('a'))
            ->withLessFunc(static fn ($x, $y): int => (string) $x <=> (string) $y)
            ->sort();
        $this->assertSame('a', (string) $sorted->itemAt(0));

        $m = $this->model->withItem(new StringItem('x'));
        $this->assertSame(-1, $m->withEqualsFunc(static fn ($x, $y): ?bool => false)->find(new StringItem('x')));

        $kept = $m->withFilterFn(static fn ($v): ?bool => (string) $v === 'x');
        $this->assertSame(1, $kept->length());
    }

    public function testPrefixerAndSuffixerReturnsAreSweptIntoTheFrame(): void
    {
        // Extension points splice INSIDE an already-wrapped row: a hostile
        // prefix()/suffix() return must not smuggle ANSI/OSC/BEL, a bare CR,
        // or a row-splitting LF into the frame — the Model sweeps both to
        // plain inline text (PB-TREELIST-M10).
        $prefixer = new class implements Prefixer {
            public function initPrefixer(
                \Stringable $value,
                int $currentIndex,
                int $cursorIndex,
                int $lineOffset,
                int $width,
                int $height,
                int $totalItems,
            ): int {
                return 0;
            }

            public function prefix(int $currentLine, int $totalLines): string
            {
                return "\x1b]0;pwn\x07evil\r";
            }
        };
        $suffixer = new class implements Suffixer {
            public function initSuffixer(
                \Stringable $value,
                int $currentIndex,
                int $cursorIndex,
                int $lineOffset,
                int $width,
                int $height,
            ): int {
                return 0;
            }

            public function suffix(int $currentLine, int $totalLines): string
            {
                return "\x1b[31mred\x07tail\r\nbreak";
            }
        };

        $m = $this->model
            ->withViewport(20, 10)
            ->withItem(new StringItem('alpha'))
            ->withPrefixer($prefixer)
            ->withSuffixer($suffixer);

        $lines = $m->lines();
        $this->assertCount(1, $lines, 'a dropped break must never fragment the row');
        $this->assertStringContainsString('alpha', $lines[0]);
        $this->assertStringContainsString('evil', $lines[0]);
        $this->assertStringContainsString('red', $lines[0]);

        // Capture ONCE before the sweep assertions: the first view() renders
        // the full frame and seeds the row-delta memo, so any LATER view()
        // returns a delta (empty when nothing changed) — scanning it per byte
        // would score against the empty string (r2 MINOR-1 vacuity).
        $frame = $m->view();

        // A full frame legitimately ENDS rows with a bare "\n" (the join plus
        // trailing terminator documented in the README delta section — hosts
        // ride ONLCR), so the sweep guarantee is per ROW: explode on the
        // legitimate terminators, then no forbidden byte may survive inside
        // any frame row.
        $frameRows = \explode("\n", $frame);
        foreach (["\x1b", "\x07", "\r", "\n"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $lines[0]);
            foreach ($frameRows as $row) {
                $this->assertStringNotContainsString($forbidden, $row);
            }
        }
        // Shape legs stand in for the "\n" byte where a per-row content scan
        // is vacuous by construction: the frame is exactly its rows plus
        // terminators (one smuggled break would add a fragment), and the
        // trailing-terminator convention is what makes the explode honest.
        $this->assertCount(2, $frameRows, 'extension returns must never fragment a frame row');
        $this->assertSame('', $frameRows[1], 'a full frame terminates with a trailing "\n"');
    }

    public function testOverrunningGuttersNeverPushARowPastTheViewport(): void
    {
        // sugar-treelist-006 contract: whatever the gutters declare, the
        // emitted row fits the viewport. Both liars reserve 0 cells; the
        // prefixer emits 30 against a 20-wide viewport, the suffixer 6.
        // Fall order is suffix, prefix, content — so the VALUE survives and
        // the lying gutters degrade instead of corrupting sibling panes.
        $liarPrefixer = new class implements Prefixer {
            public function initPrefixer(
                \Stringable $value, int $currentIndex, int $cursorIndex,
                int $lineOffset, int $width, int $height, int $totalItems,
            ): int {
                return 0;
            }

            public function prefix(int $currentLine, int $totalLines): string
            {
                return \str_repeat('P', 30);
            }
        };
        $liarSuffixer = new class implements Suffixer {
            public function initSuffixer(
                \Stringable $value, int $currentIndex, int $cursorIndex,
                int $lineOffset, int $width, int $height,
            ): int {
                return 0;
            }

            public function suffix(int $currentLine, int $totalLines): string
            {
                return $currentLine === 0 ? 'SUFFIX' : '';
            }
        };
        $m = Model::new()->withViewport(20, 4)
            ->withItem(new StringItem('some content here'))
            ->withPrefixer($liarPrefixer)
            ->withSuffixer($liarSuffixer);

        $rows = $m->withCursor(0)->lines();
        $this->assertNotSame([], $rows);
        foreach ($rows as $i => $row) {
            $this->assertLessThanOrEqual(
                20,
                Width::string($row),
                "row {$i} escaped the viewport under an overrunning gutter: {$row}",
            );
        }
        // Exact leg: suffix falls (6), prefix pays the rest (27 → 3 cells),
        // the content stands untouched.
        $this->assertSame('PPPsome content here', $rows[0]);
    }

    public function testEmptySuffixStillChargesTheWrapReservation(): void
    {
        // The documented quirk, pinned (sugar-treelist-006): the reserved
        // width is subtracted from the content budget on EVERY line — even
        // lines whose suffix() returns '' — so the item wraps at the narrow
        // budget and the empty lines carry no padding.
        $silentSuffixer = new class implements Suffixer {
            public function initSuffixer(
                \Stringable $value, int $currentIndex, int $cursorIndex,
                int $lineOffset, int $width, int $height,
            ): int {
                return 10;
            }

            public function suffix(int $currentLine, int $totalLines): string
            {
                return '';
            }
        };
        $m = Model::new()->withViewport(20, 4)
            ->withItem(new StringItem(str_repeat('x', 40)))
            ->withSuffixer($silentSuffixer);

        $rows = $m->withCursor(0)->lines();
        $this->assertSame(str_repeat('x', 10), $rows[0], 'wrap paid the 10-cell reservation');
        foreach ($rows as $i => $row) {
            $this->assertSame(rtrim($row, ' '), $row, "row {$i} padded toward an absent suffix");
        }
    }

    public function testNarrowingFilterReAnchorsTheCursorByIdentity(): void
    {
        // QA-01 reproducer: aa, ab, ba, bb with the cursor on ba (index 2);
        // a filter dropping every a* item projects [ba, bb]. Position
        // clamping used to hand the selection to bb — a silent teleport to an
        // unrelated item in the search-as-you-type flow. Identity re-anchor:
        // the cursor now LANDS on ba, at its new index.
        $m = Model::new()->withViewport(20, 4)
            ->withItems(
                new StringItem('aa'), new StringItem('ab'),
                new StringItem('ba'), new StringItem('bb'),
            )
            ->withCursor(2);
        $this->assertSame('ba', (string) $m->cursorItem());

        $filtered = $m->withFilterFn(
            static fn(\Stringable $v): bool => !\str_starts_with((string) $v, 'a'),
        );
        $this->assertSame(2, $filtered->length());
        $this->assertSame(0, $filtered->cursorIndex(), 'cursor must ride ba to its new index');
        $this->assertSame('ba', (string) $filtered->cursorItem());

        // Lifting the filter restores the full list — and the selection
        // returns to ba, not to whatever occupies the projection index.
        $restored = $filtered->withoutFilter();
        $this->assertSame(4, $restored->length());
        $this->assertSame(2, $restored->cursorIndex());
        $this->assertSame('ba', (string) $restored->cursorItem());
    }

    public function testFilterClampsByPositionOnlyWhenTheSelectedItemIsGone(): void
    {
        // Fallback leg of the same law: the cursor sits on ab (index 1) and
        // the predicate drops ab itself — the position clamp then applies and
        // the cursor lands at index 1 of [ba, bb].
        $m = Model::new()->withViewport(20, 4)
            ->withItems(
                new StringItem('aa'), new StringItem('ab'),
                new StringItem('ba'), new StringItem('bb'),
            )
            ->withCursor(1);

        $filtered = $m->withFilterFn(
            static fn(\Stringable $v): bool => !\str_starts_with((string) $v, 'a'),
        );
        $this->assertSame(1, $filtered->cursorIndex());
        $this->assertSame('bb', (string) $filtered->cursorItem());

        // And when the selected item is the ONLY survivor past the cursor's
        // old row, identity still wins over the raw clamp.
        $kept = $m->withFilterFn(
            static fn(\Stringable $v): bool => (string) $v === 'ab',
        );
        $this->assertSame(0, $kept->cursorIndex());
        $this->assertSame('ab', (string) $kept->cursorItem());
    }

    public function testRowBudgetCapsLayoutWorkForPathologicalValues(): void
    {
        // 200k logical lines, viewport height 5, wrap 0: the frame shows at
        // most the budget; layout must stop there (the old code wrapped the
        // whole value first — measured 1.17s + 41MB per frame).
        $huge = new StringItem(implode("\n", array_fill(0, 200_000, 'filler line')));
        $m = $this->model->withViewport(20, 5)->withItem($huge)->withItem(new StringItem('tail'));

        $started = \microtime(true);
        $lines = $m->withCursor(0)->lines();
        $elapsed = \microtime(true) - $started;

        $this->assertLessThanOrEqual(5, count($lines));
        $this->assertLessThan(2.0, $elapsed, 'wrap ignored the row budget');

        // Same for the explicit wrap knob: the huge item is truncated to its
        // wrap rows and the next item still gets the remaining window. The
        // 2.4 MB value also trips the byte cap, so its LAST wrap row is the
        // loud truncation marker (treelist-004) — content bytes were dropped,
        // and the frame says so instead of silently showing a prefix.
        $wrapped = $m->withWrap(2)->lines();
        $this->assertSame(['filler line', '… (item truncated)', 'tail'], $wrapped);
    }

    public function testByteCapCutsASingleHugeParagraphWithALoudMarker(): void
    {
        // A 400 KB single-line value in a 3-row window: the raw scan window
        // is sized to the budget (3 rows × 21 + 1 = 64 bytes), so the tail
        // outside it is announced — a prefix alone would be a silent lie.
        $huge = new StringItem(str_repeat('x', 400_000));
        $m = $this->model->withViewport(20, 3)->withItem($huge);

        $lines = $m->withCursor(0)->lines();
        $this->assertCount(3, $lines);
        $this->assertSame(str_repeat('x', 20), $lines[0]);
        $this->assertSame(str_repeat('x', 20), $lines[1]);
        $this->assertSame('… (item truncated)', $lines[2]);
    }

    public function testWindowCutNeverSplitsACodepointAtItsSeam(): void
    {
        // The window here ends one byte into the trailing 2-byte codepoint
        // (42 a's + the 43rd byte = é's lead); splitting it would hand the /u
        // tokenizer in hardWrap() a malformed chunk and silently drop the row
        // (sugar-treelist-012's failure shape). The cut must back off.
        $value = str_repeat('a', 42) . 'é';
        $m = $this->model->withViewport(20, 2)->withItem(new StringItem($value));

        $lines = $m->withCursor(0)->lines();
        $this->assertSame(str_repeat('a', 20), $lines[0], 'clean rows up to the cut');
        $this->assertSame('… (item truncated)', $lines[1], 'budget row shows the cut');
    }

    public function testScanWindowSizesToTheBudgetNotJustTheCeiling(): void
    {
        // 40 bytes fits the 43-byte budget window of a 2×20 viewport: pure
        // content, no marker. 44 bytes does not: the marker appears even
        // though the value is far under MAX_ITEM_RENDER_BYTES.
        $fits = $this->model->withViewport(20, 2)
            ->withItem(new StringItem(str_repeat('b', 40)));
        $lines = $fits->withCursor(0)->lines();
        $this->assertSame(str_repeat('b', 20), $lines[0]);
        $this->assertSame(str_repeat('b', 20), $lines[1]);

        $over = $this->model->withViewport(20, 2)
            ->withItem(new StringItem(str_repeat('b', 44)));
        $lines = $over->withCursor(0)->lines();
        $this->assertSame(str_repeat('b', 20), $lines[0]);
        $this->assertSame('… (item truncated)', $lines[1]);
    }

    public function testCarriedRowWidthsWrapWideViewportsIdentically(): void
    {
        // 1500 words in a width-5000 panel: the token loop now sums carried
        // part widths instead of re-measuring the growing concat per token
        // (the quadratic that cost 220 ms). Wrap edges must be EXACTLY the
        // old measurements: 1000 words fit (5*1000-1 = 4999 cells), the
        // 1001st word flushes the row.
        $words = \array_fill(0, 1500, 'word');
        $m = Model::new()->withViewport(5000, 2)
            ->withItem(new StringItem(\implode(' ', $words)));

        $lines = $m->withCursor(0)->lines();
        $this->assertSame(\implode(' ', \array_fill(0, 1000, 'word')), $lines[0]);
        $this->assertSame(\implode(' ', \array_fill(0, 500, 'word')), $lines[1]);
    }

    /**
     * treelist-002: a custom gutter whose variants are wider than the stock
     * single-cell separators must still keep every emitted row inside the
     * viewport — the reservation now covers max over firstSep/separator/
     * lastSep/separatorWrap and max(currentMarker, emptyMarker), so the
     * content budget shrinks to pay for the widest thing prefix() can emit.
     */
    public function testMultiCellGutterVariantsNeverOverflowViewport(): void
    {
        $prefixer = new DefaultPrefixer();
        $prefixer->firstSep      = '╭──'; // 3 cells
        $prefixer->separator     = '├─';  // 2 cells
        $prefixer->lastSep       = '╰───';// 4 cells — the widest join
        $prefixer->separatorWrap = '│─';  // 2 cells
        $prefixer->currentMarker = '▶';   // Wide-stable single cell
        $prefixer->emptyMarker   = '·─';  // 2 cells — the widest marker column

        $model = $this->model
            ->withViewport(30, 6)
            ->withPrefixer($prefixer)
            ->withItemsFromArray([
                str_repeat('x', 60),
                str_repeat('y', 60),
                str_repeat('z', 60),
            ]);

        $rows = $model->lines();
        $this->assertNotEmpty($rows);
        foreach ($rows as $i => $row) {
            $this->assertLessThanOrEqual(
                30,
                \SugarCraft\Core\Util\Width::string($row),
                "row {$i} overflowed the 30-cell viewport with a 4-cell gutter variant: {$row}"
            );
        }
    }

    // -- item intake contract (sugar-treelist-022/036) ---------------------

    public function testPlainStringsAreFirstClassItemAtEveryDoor(): void
    {
        // The DX-03 cure: addItem('file.php') used to fatal; now every door
        // takes strings directly, no StringItem boilerplate.
        $m = Model::new()->withItem('file.php');
        $this->assertSame(1, $m->length());
        $this->assertSame('file.php', (string) $m->itemAt(0));
        $this->assertSame(0, $m->find('file.php'));

        $m = $m->withItems('second.php', new StringItem('third.php'));
        $this->assertSame(
            ['file.php', 'second.php', 'third.php'],
            \array_map('strval', $this->valuesOf($m)),
        );

        $m = $m->withItemsFromArray(['fourth.php', new StringItem('fifth.php')]);
        $this->assertSame(5, $m->length());
        $this->assertSame('fourth.php', (string) $m->itemAt(3));
        $this->assertSame(-1, $m->find('missing.php'));
    }

    public function testStringIntakeAndStringItemIntakeRenderByteIdentically(): void
    {
        // One normalization path, two spellings — the widened door must not
        // introduce a second representation with its own rendering quirks.
        $widened = Model::new()->withWidth(20)->withHeight(2)->withItem('same value');
        $wrapped = Model::new()->withWidth(20)->withHeight(2)->withItem(new StringItem('same value'));

        $this->assertSame($wrapped->lines(), $widened->lines());
        $this->assertSame($wrapped->itemIds(), $widened->itemIds());
    }

    public function testScalarJunkIsRefusedByEveryIntakeDoor(): void
    {
        $doors = [
            'Model::withItem()' => static fn (mixed $v): Model => Model::new()->withItem($v),
            'Model::withItems()' => static fn (mixed $v): Model => Model::new()->withItems($v),
            'Model::withItemsFromArray()' => static fn (mixed $v): Model => Model::new()->withItemsFromArray([$v]),
            'Model::find()' => static fn (mixed $v): int => Model::new()->find($v),
            'Model::tryFind()' => static fn (mixed $v): ?int => Model::new()->tryFind($v),
        ];
        foreach ($doors as $door => $call) {
            foreach ([null, true, 1, 2.5, [], new \stdClass()] as $junk) {
                try {
                    $call($junk);
                    $this->fail("{$door} accepted " . \get_debug_type($junk));
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($door, $e->getMessage());
                    $this->assertStringContainsString(\get_debug_type($junk), $e->getMessage());
                }
            }
        }
    }

    public function testArrayDoorRefusesTheOffendingElementByPosition(): void
    {
        // The exact 036 regression shape: ['ok',null,1,true,2.5] used to land
        // as ['ok','','1','1','2.5'] — blank rows that looked like library
        // corruption. Now the first bad element aborts the whole call by name.
        try {
            Model::new()->withItemsFromArray(['ok', null, 1, true, 2.5]);
            $this->fail('the lax cast door is supposed to be dead');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Model::withItemsFromArray()', $e->getMessage());
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('null', $e->getMessage());
        }
        // String keys get quoted positions (an assoc `$rows` names the row).
        try {
            Model::new()->withItemsFromArray(['users' => ['name' => 1.0]]);
            $this->fail('nested arrays must not slip through the door');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("element 'users'", $e->getMessage());
            $this->assertStringContainsString('array', $e->getMessage());
        }
    }

    public function testJunkRefusalLeavesTheReceiverAndForksUnharmed(): void
    {
        // Fail-fast at the door: the rejected call must not mint ids or push
        // half-normalized items onto the pristine list.
        $base = Model::new()->withItemsFromArray(['a', 'b']);
        try {
            $base->withItemsFromArray(['c', false]);
            $this->fail('bool element must be refused');
        } catch (\InvalidArgumentException) {
            // expected
        }
        $this->assertSame(['a', 'b'], \array_map('strval', $this->valuesOf($base)));

        $fork = $base->withItem('later');
        $this->assertSame(3, $fork->length());
        $this->assertSame(['a', 'b', 'later'], \array_map('strval', $this->valuesOf($fork)));
    }

    // -- happy-path ergonomics: Model::tree() (sugar-treelist-021/030/050) --

    public function testTreeInstallsTheDefaultPairWhileNewStaysPure(): void
    {
        $t = Model::tree();
        $this->assertInstanceOf(DefaultPrefixer::class, $t->prefixer());
        $this->assertInstanceOf(DefaultSuffixer::class, $t->suffixer());

        $plain = Model::new();
        $this->assertNull($plain->prefixer());
        $this->assertNull($plain->suffixer());

        // tree() must equal the explicit boilerplate it replaces, byte for byte.
        $explicit = Model::new()
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());
        $this->assertSame($explicit->width(), $t->width());
        $this->assertSame($explicit->height(), $t->height());
        $this->assertSame(
            $explicit->withItemsFromArray(['a', 'b'])->lines(),
            $t->withItemsFromArray(['a', 'b'])->lines(),
        );
    }

    public function testWithTreeDefaultsKeepsInFlightConfigurationAndStaysOverridable(): void
    {
        $base = Model::new()->withViewport(40, 10)->withItem('x');
        $t = $base->withTreeDefaults();
        $this->assertSame(40, $t->width());
        $this->assertSame(10, $t->height());
        $this->assertSame(['x'], \array_map('strval', $this->valuesOf($t)));
        $this->assertInstanceOf(DefaultSuffixer::class, $t->suffixer());
        // base untouched (immutability) — still an undecorated list
        $this->assertNull($base->prefixer());

        $override = $t->withPrefixer(new class implements Prefixer {
            public function initPrefixer(
                \Stringable $value, int $currentIndex, int $cursorIndex,
                int $lineOffset, int $width, int $height, int $totalItems
            ): int { return 0; }
            public function prefix(int $currentLine, int $totalLines): string { return ''; }
        });
        $this->assertNotInstanceOf(DefaultPrefixer::class, $override->prefixer());
    }

    public function testDocumentedQuickStartCarriesAVisibleCursorCue(): void
    {
        // The class docblock/README quick start is the first surface readers
        // copy — it must show the cursor (DOC-A11Y-050), which only a marker
        // or a style can do, so the example now goes through Model::tree().
        $model = Model::tree()
            ->withViewport(80, 24)
            ->withItemsFromArray(['apple', 'banana', 'cherry']);
        $frame = $model->view();

        $this->assertStringContainsString('╭ 1 > apple', $frame); // treelist-17: 1-based ordinal
        $this->assertStringContainsString('banana', $frame);
        $this->assertStringContainsString('<', $frame); // suffix closes the row
    }

    // -- malformed UTF-8 repair at the render choke (sugar-treelist-012) ----

    /** @return list<string> */
    private function decoratedLines(string $value, int $height = 6, int $wrap = 6): array
    {
        return Model::new()
            ->withItems(new StringItem($value))
            ->withWidth(20)
            ->withHeight($height)
            ->withWrap($wrap)
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->lines();
    }

    public function testOverlongMalformedWordRendersRepairedCellsNotASilentEmptyRow(): void
    {
        // 40 stray 0xC3 lead bytes glued to a 20-char tail used to collapse
        // splitOverWidth() to one silently EMPTY row (grapheme_strlen()
        // returns NULL on invalid UTF-8, so the chunk loop never ran). The
        // repair turns each invalid subsequence into one '?' cell and the
        // tail survives on its own rows.
        $long = \str_repeat("\xC3", 40) . 'tail-content-here-xx';
        $lines = $this->decoratedLines($long);

        $this->assertSame([
            '╭ 1 > ?????????????<', // treelist-17: 1-based ordinal
            '│     ?????????????',
            '│     ?????????????',
            '│     ?tail-content',
            '│     -here-xx',
        ], $lines);

        $joined = \implode("\n", $lines);
        $this->assertStringNotContainsString("\xC3", $joined);
        $this->assertTrue(
            \mb_check_encoding($joined, 'UTF-8'),
            'the frame sink never carries invalid UTF-8 out of lines()',
        );
    }

    public function testShortAndOverlongMalformedValuesTakeTheSameRepairPath(): void
    {
        // The acceptance shape: the identical invalid byte must surface as
        // the identical '?' replacement on the fits-in-one-row path (the
        // short value used to emit its raw bytes verbatim) and on the
        // splitOverWidth() path — one repair, not two divergent failures.
        $short = $this->decoratedLines("a\xFFz", height: 1, wrap: 1);
        $long  = $this->decoratedLines("a\xFFz" . \str_repeat('q', 30));

        $this->assertSame(['╭ 1 > a?z          <'], $short); // treelist-17: 1-based ordinal
        $this->assertSame('╭ 1 > a?zqqqqqqqqqq<', $long[0]);
    }

    public function testWellFormedUtf8PassesTheRepairByteForByte(): void
    {
        // mb_convert_encoding must be a no-op on valid input — a repair that
        // churned well-formed text would be a far worse bug than the one it
        // cures. € (3-byte) and 😀 (4-byte) prove both lead lengths survive.
        $lines = $this->decoratedLines('Price: €5 😀 end');

        $this->assertSame([
            '╭ 1 > Price: €5 😀 <', // treelist-17: 1-based ordinal
            '│     end',
        ], $lines);
    }

    public function testEachLineCarriesTheRepairedFrameIdentically(): void
    {
        $value = str_repeat("\xC3", 40) . 'tail-content-here-xx';
        $model = Model::new()
            ->withItems(new StringItem($value))
            ->withWidth(20)
            ->withHeight(6)
            ->withWrap(6)
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer());

        $streamed = [];
        foreach ($model->eachLine() as $line) {
            $streamed[] = $line;
        }
        $this->assertSame($model->lines(), $streamed);
    }

    /**
     * Fuzzy filter door (sugar-treelist-033): withFuzzyFilter wires the
     * capture, keeps full-query coverage, and re-projects from the pristine
     * list on every call — the filter-as-you-type contract.
     */
    public function testWithFuzzyFilterReprojectsFromThePristineListPerCall(): void
    {
        $model = Model::tree()->withWidth(40)->withHeight(6)->withItemsFromArray([
            'apple', 'apricot', 'banana', 'blueberry',
        ]);

        $ap = $model->withFuzzyFilter('ap');
        $this->assertSame(['apple', 'apricot'], \array_map(strval(...), $this->valuesOf($ap)));
        $this->assertSame(FilterState::filtering, $ap->filterState());

        // Type further: only apricot keeps a-p-i-c in order.
        $apic = $ap->withFuzzyFilter('apic');
        $this->assertSame(['apricot'], \array_map(strval(...), $this->valuesOf($apic)));

        // Backspace must NOT be trapped inside the previous projection:
        // 'ap' again reveals both original 'ap' items, not apricot only.
        $back = $apic->withFuzzyFilter('ap');
        $this->assertSame(['apple', 'apricot'], \array_map(strval(...), $this->valuesOf($back)));
    }

    public function testWithFuzzyFilterKeepsOnlyFullQueryCoverage(): void
    {
        $model = Model::tree()->withWidth(40)->withHeight(6)->withItemsFromArray([
            'git status --short',
            'git commit -m x',
            'docker status',
            'node-server route',
        ]);

        // g,s,t in order lands only on 'git status --short' — plain local
        // alignment would keep every item that shares one stray letter.
        $hits = $model->withFuzzyFilter('gst');
        $this->assertSame(['git status --short'], \array_map(strval(...), $this->valuesOf($hits)));
    }

    public function testWithFuzzyFilterEmptyQueryClearsTheFilter(): void
    {
        $model = Model::tree()->withWidth(40)->withHeight(6)
            ->withItemsFromArray(['apple', 'banana'])
            ->withFuzzyFilter('app');
        $this->assertSame(1, $model->length());

        $cleared = $model->withFuzzyFilter('');
        $this->assertSame(2, $cleared->length());
        $this->assertSame(FilterState::unfiltered, $cleared->filterState());
        $this->assertNull($cleared->filterFn());
    }

    public function testWithoutFuzzyFilterRestoresTheListAndReAnchorsTheCursor(): void
    {
        $model = Model::tree()->withWidth(40)->withHeight(6)
            ->withItemsFromArray(['apple', 'apricot', 'banana', 'blueberry'])
            ->withCursor(1);
        $this->assertSame('apricot', (string) $model->cursorItem());

        $hits = $model->withFuzzyFilter('ap');
        $this->assertSame(2, $hits->length());

        // 'apricot' survived the projection, so the restore keeps the
        // selection on it by identity — not on whatever index 1 now holds.
        $restored = $hits->withoutFuzzyFilter();
        $this->assertSame(4, $restored->length());
        $this->assertSame('apricot', (string) $restored->cursorItem());
        $this->assertSame(1, $restored->cursorIndex());
    }

    public function testWithFuzzyFilterMembershipIsTheCoverageRuleUnderAnyProfile(): void
    {
        // Weights shape the reported scores of FuzzyMatch::match(); the door's
        // membership is full-query coverage either way — a covered alignment
        // is floored at 1, so even an extreme profile cannot veto a real hit,
        // and no profile can smuggle an uncovered candidate past the rule.
        $model = Model::tree()->withWidth(40)->withHeight(6)->withItemsFromArray([
            'git status --short',
            'node-server route',
        ]);
        $extreme = new ScoringProfile(matchScore: 1, mismatchPenalty: -1, gapOpen: -1, gapExtend: -100, adjacentBonus: 0);

        $canonical = $model->withFuzzyFilter('gst');
        $retuned   = $model->withFuzzyFilter('gst', $extreme);
        $this->assertSame(['git status --short'], \array_map(strval(...), $this->valuesOf($canonical)));
        $this->assertSame(['git status --short'], \array_map(strval(...), $this->valuesOf($retuned)));
    }

    /**
     * @return list<\Stringable>
     */
    private function valuesOf(Model $m): array
    {
        $values = [];
        for ($i = 0; $i < $m->length(); $i++) {
            $values[] = $m->itemAt($i);
        }
        return $values;
    }

    /**
     * sugar-treelist-017 disclosure: the filter machine is exactly the two
     * shipped cases — the upstream third FilterApplied state has nowhere to
     * live here (predicate installation and projection application are the
     * same clone transition). If a third case ever returns, the README
     * "deliberately two cases" paragraph and the enum docblock have to be
     * re-written with it, so the shape is pinned, not prose-trusted.
     */
    public function testFilterStateMachineIsExactlyTheTwoDisclosedCases(): void
    {
        $this->assertSame(
            [FilterState::unfiltered, FilterState::filtering],
            FilterState::cases()
        );
    }
}
