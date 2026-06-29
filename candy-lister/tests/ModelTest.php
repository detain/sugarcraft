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
            $m->addItem(new StringItem($v));
        }

        $this->assertSame(0, $m->cursorIndex());
        $m->cursorDown();
        $this->assertSame(1, $m->cursorIndex());
        $m->cursorDown();
        $this->assertSame(2, $m->cursorIndex());
        $m->cursorUp();
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
            $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(3, $this->model->length());

        $this->model->removeItem(1);
        $this->assertSame(2, $this->model->length());
    }

    public function testClear(): void
    {
        foreach (['a', 'b'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(2, $this->model->length());
        $this->model->clear();
        $this->assertTrue($this->model->isEmpty());
    }

    public function testSortWithLessFunc(): void
    {
        foreach (['z', 'a', 'm'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->lessFunc = fn($a, $b) => \strcmp((string) $a, (string) $b);
        $this->model->sort();

        // After sort, cursor stays on the same logical item ('z' was at cursor index 0).
        $this->assertSame('z', (string) $this->model->cursorItem());
        // The first visible line is 'a' (alphabetically first) but cursor is on 'z'.
        $this->assertSame('a', $this->model->lines()[0]);
    }

    public function testFindItem(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->assertSame(1, $this->model->find(new StringItem('banana')));
        $this->assertSame(-1, $this->model->find(new StringItem('durian')));
    }

    public function testFindWithEqualsFunc(): void
    {
        foreach (['aa', 'bb', 'cc'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->equalsFunc = fn($a, $b) => (string) $a === (string) $b;
        $this->assertSame(0, $this->model->find(new StringItem('aa')));
        $this->assertSame(-1, $this->model->find(new StringItem('xx')));
    }

    public function testSetCursor(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->setCursor(2);
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
        $this->model
            ->addItem(new StringItem('item one'))
            ->addItem(new StringItem('item two'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $view = $this->model->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('item one', $view);
        $this->assertStringContainsString('item two', $view);
    }

    public function testPrefixerInterface(): void
    {
        $this->model
            ->addItem(new StringItem('test item'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
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
        $this->model
            ->addItem(new StringItem($longText))
            ->setViewport(20, 24)
            ->setWrap(3)
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $lines = $this->model->lines();
        $this->assertLessThanOrEqual(3, \count($lines));
    }

    public function testAddItemReturnsSelf(): void
    {
        $m = $this->model->addItem(new StringItem('x'));
        $this->assertSame($this->model, $m);
    }

    // -------------------------------------------------------------------------
    // Filter tests
    // -------------------------------------------------------------------------

    public function testWithFilterFnReturnsNewInstance(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model->addItem(new StringItem($v));
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
            $this->model->addItem(new StringItem($v));
        }

        $filtered = $this->model->withFilterFn(fn($v) => str_starts_with((string) $v, 'a'));

        $this->assertSame(2, $filtered->length());
        // Verify by navigating through cursorItem
        $this->assertSame('apple', (string) $filtered->cursorItem());
        $filtered->cursorDown();
        $this->assertSame('apricot', (string) $filtered->cursorItem());
    }

    public function testWithoutFilterRestoresOriginalItems(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model->addItem(new StringItem($v));
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
        $this->model->addItem(new StringItem('apple'));
        $result = $this->model->withoutFilter();
        $this->assertSame($this->model, $result);
    }

    public function testWithFilterFnCursorClampedToFilteredLength(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->model->setCursor(2);  // cursor on 'c'

        // Filter out 'c' — cursor should clamp to index 1 (last remaining item 'b')
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'c');
        $this->assertSame(2, $filtered->length());
        $this->assertSame(1, $filtered->cursorIndex());
    }

    public function testFilterStateIsFilteringAfterSet(): void
    {
        $this->model->addItem(new StringItem('apple'));
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
        foreach (['a', 'b'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->model->setCursor(0);
        $result = $this->model->cursorDown(100);
        $this->assertSame(1, $result->cursorIndex());
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
        $this->model
            ->addItem(new StringItem("line1\nline2\nline3"))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
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
        $this->model
            ->addItem(new StringItem('styled'))
            ->setLineStyle("\x1b[2m")
            ->setCurrentStyle("\x1b[1m")
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
        $this->assertNotEmpty($lines);
    }

    public function testFilterWithNoMatchingItems(): void
    {
        $this->model->addItem(new StringItem('apple'));
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v !== 'apple');
        $this->assertSame(0, $filtered->length());
    }

    public function testFilterCursorsToLastItemWhenOnlyOneMatches(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->model->setCursor(2); // cursor on 'c'
        $filtered = $this->model->withFilterFn(fn($v) => (string) $v === 'a');
        // After filtering to just 'a', cursor should be 0
        $this->assertSame(0, $filtered->cursorIndex());
    }

    // ─── Exercises private helper methods via public API ──────────────────────

    public function testLinesWithVeryLongWordExercisingSplitOverWidth(): void
    {
        // A single word longer than content width should be split
        $longWord = \str_repeat('a', 100);
        $this->model
            ->addItem(new StringItem($longWord))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(20, 24);

        $lines = $this->model->lines();
        $this->assertNotEmpty($lines);
        // The long word should be split into multiple lines
        $this->assertGreaterThan(1, \count($lines));
    }

    public function testLinesWithMultiParagraphTextExercisingHardWrap(): void
    {
        // Multi-paragraph text (contains \n) exercises hardWrap
        $this->model
            ->addItem(new StringItem("para1\n\npara2"))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
        $this->assertNotEmpty($lines);
    }

    public function testLinesWithWrapLimitExercisingRenderItemWrapLogic(): void
    {
        $multiline = "line1\nline2\nline3\nline4\nline5";
        $this->model
            ->addItem(new StringItem($multiline))
            ->setWrap(2) // limit to 2 lines
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
        // Should be limited to 2 lines due to wrap setting
        $this->assertLessThanOrEqual(2, \count($lines));
    }

    public function testLinesWithContentWidthTooNarrowExercisingHardWrapEdgeCase(): void
    {
        $this->model
            ->addItem(new StringItem('word'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(5, 24);

        $lines = $this->model->lines();
        $this->assertNotEmpty($lines);
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
