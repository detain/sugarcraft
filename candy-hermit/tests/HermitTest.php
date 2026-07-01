<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\{Hermit, FilteredItem, Item, HelpBar, StatusBar};
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Fuzzy\{FuzzyMatcher, MatchResult};
use PHPUnit\Framework\TestCase;

final class HermitTest extends TestCase
{
    /**
     * @return list<Item>
     */
    private function items(): array
    {
        return [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
            new FilteredItem(4, 'date'),
            new FilteredItem(5, 'elderberry'),
        ];
    }

    private function makeHermit(): Hermit
    {
        return Hermit::new($this->items());
    }

    public function testNew(): void
    {
        $h = Hermit::new(['a', 'b']);
        $this->assertSame(2, $h->allCount());
        $this->assertFalse($h->isShown());
    }

    public function testShowHide(): void
    {
        $h = $this->makeHermit();
        $this->assertFalse($h->isShown());

        $h = $h->show();
        $this->assertTrue($h->isShown());

        $h = $h->hide();
        $this->assertFalse($h->isShown());
    }

    public function testTypeFilters(): void
    {
        $h = $this->makeHermit()->show();

        $h = $h->type('a');  // apple, banana, date
        $this->assertSame(3, $h->itemCount());
        $this->assertSame('apple', $h->selected()->value());
    }

    public function testBackspace(): void
    {
        $h = $this->makeHermit()->show()->type('ban');

        $this->assertSame(1, $h->itemCount()); // banana
        $h = $h->backspace();                  // ba
        $this->assertSame(1, $h->itemCount()); // banana still
        $h = $h->backspace()->backspace()->backspace();  // ''
        $this->assertSame(5, $h->itemCount());
    }

    public function testBackspaceCursorNeverNegative(): void
    {
        // Set a filter that excludes everything, then type and backspace
        // to an empty filtered set — cursor must floor at 0, not -1.
        $h = $this->makeHermit()
            ->setFilterFn(static fn(Item $i): bool => false)
            ->show()
            ->type('a'); // all filtered out

        $this->assertSame(0, $h->itemCount());

        $h = $h->backspace(); // empty filter → empty list, cursor floor at 0

        $this->assertSame(0, $h->cursor(), 'cursor must be 0, not -1, on empty filtered list');
    }

    public function testClearFilter(): void
    {
        $h = $this->makeHermit()->show()->type('b');
        $this->assertSame(1, $h->itemCount());

        $h = $h->clear();
        $this->assertSame('', $h->filterText());
        $this->assertSame(5, $h->itemCount());
    }

    public function testCursorNavigation(): void
    {
        $h = $this->makeHermit()->show();

        $this->assertSame(0, $h->cursor());
        $h = $h->cursorDown();
        $this->assertSame(1, $h->cursor());
        $h = $h->cursorDown(2);
        $this->assertSame(3, $h->cursor());
        $h = $h->cursorUp();
        $this->assertSame(2, $h->cursor());
        $h = $h->cursorTop();
        $this->assertSame(0, $h->cursor());
        $h = $h->cursorBottom();
        $this->assertSame(4, $h->cursor());
    }

    public function testCursorClamp(): void
    {
        $h = $this->makeHermit()->show();
        $h = $h->cursorUp();  // below 0 → clamped
        $this->assertSame(0, $h->cursor());

        $h = $h->cursorBottom();
        $h = $h->cursorDown(100);  // beyond end → clamped
        $this->assertSame(4, $h->cursor());
    }

    public function testSelectedItem(): void
    {
        $h = $this->makeHermit()->show()->type('a');
        $this->assertSame('apple', $h->selected()->value());

        $h = $h->cursorDown();
        // next filtered item
        $this->assertNotNull($h->selected());
    }

    public function testSelectedNullOnEmptyFilter(): void
    {
        $h = Hermit::new([])->show();
        $this->assertNull($h->selected());
    }

    public function testViewWhenHidden(): void
    {
        $bg = "background\ncontent";
        $h = $this->makeHermit();
        $this->assertSame($bg, $h->View($bg));
    }

    public function testViewWhenShown(): void
    {
        $bg = "background\ncontent";
        $h = $this->makeHermit()->show();
        $result = $h->View($bg);

        $this->assertIsString($result);
        // When shown, the prompt appears
        $this->assertStringContainsString('> ', $result);
    }

    public function testFluentSetters(): void
    {
        $h = $this->makeHermit()
            ->setPrompt('Search: ')
            ->setMatchStyle("\x1b[33m")
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        $this->assertTrue($h->isShown()); // show() does set isShown

        // setOffset is a pure position setter — does NOT auto-show
        $h2 = $this->makeHermit()->setOffset(5, 3);
        $this->assertFalse($h2->isShown(), 'setOffset alone must not show the overlay');
    }

    public function testWithItemsReturnsNewInstance(): void
    {
        $a = $this->makeHermit();
        $b = $a->withItems([
            new FilteredItem(1, 'x'),
            new FilteredItem(2, 'y'),
            new FilteredItem(3, 'z'),
        ]);

        $this->assertSame(5, $a->allCount());
        $this->assertSame(3, $b->allCount());
    }

    public function testCustomItemFormatter(): void
    {
        $h = Hermit::new([
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
        ])->show()
            ->setItemFormatter(fn($item, $sel) => "[$sel] $item");

        // Hidden view result — but custom formatter is applied in View()
        $bg = str_repeat("....................\n", 5);
        $result = $h->View($bg);

        $this->assertIsString($result);
    }

    public function testImmutability(): void
    {
        $a = $this->makeHermit()->show()->type('a');
        $b = $a->cursorDown();

        // a is unchanged
        $this->assertSame(0, $a->cursor());
        // b is different
        $this->assertSame(1, $b->cursor());
        // a filter unchanged
        $this->assertSame('a', $a->filterText());
        $this->assertSame('a', $b->filterText());
    }

    public function testSetFilterFn(): void
    {
        $h = $this->makeHermit()->show();

        // Default: all 5 items pass
        $this->assertSame(5, $h->itemCount());

        // Set a custom filter — only items with value length > 5
        $h = $h->setFilterFn(fn(Item $item): bool => \strlen($item->value()) > 5);

        // apple(5→false), banana(6→true), cherry(6→true), date(4→false), elderberry(9→true)
        $this->assertSame(3, $h->itemCount());

        // Cursor resets to 0 after setFilterFn
        $this->assertSame(0, $h->cursor());
    }

    public function testBorderStyleComposition(): void
    {
        $border = Border::rounded();
        $style = Style::new()->fg('#ffffff')->on('#0000ff');

        $h = $this->makeHermit()
            ->withBorder($border)
            ->withStyle($style);

        $this->assertSame($border, $h->border());
        $this->assertSame($style, $h->style());

        // Immutability: original unchanged
        $h2 = $h->withBorder(Border::block());
        $this->assertSame($border, $h->border());
        $this->assertNotSame($h2->border(), $h->border());
    }

    public function testHelpBarAndStatusBar(): void
    {
        $helpBar = new HelpBar(['↑↓' => 'navigate', 'Enter' => 'select']);
        $statusBar = new StatusBar('5 items');

        $h = $this->makeHermit()
            ->withHelpBar($helpBar)
            ->withStatusBar($statusBar);

        $this->assertSame($helpBar, $h->helpBar());
        $this->assertSame($statusBar, $h->statusBar());

        // Test rendering
        $this->assertSame('↑↓: navigate │ Enter: select', $helpBar->render());
        $this->assertSame('5 items', $statusBar->render());

        // Immutability
        $h2 = $h->withHelpBar(new HelpBar(['Esc' => 'quit']));
        $this->assertNotSame($h->helpBar(), $h2->helpBar());
        $this->assertSame($h->statusBar(), $h2->statusBar());
    }

    public function testHighlightMatchesCjk(): void
    {
        $h = Hermit::new([
            new FilteredItem(1, '日本語'),
            new FilteredItem(2, '中文'),
            new FilteredItem(3, '한국어'),
        ])->show()->setMatchStyle("\x1b[33m");

        $h = $h->type('本');

        $bg = str_repeat("....................\n", 5);
        $view = $h->View($bg);

        // Verify the ANSI highlight is present (yellow), confirming CJK matching works.
        $this->assertStringContainsString("\x1b[33m", $view);
        // Verify '本' appears somewhere in the output (ANSI-wrapped but present)
        $this->assertStringContainsString('本', $view);
    }

    public function testHighlightMatchesEmoji(): void
    {
        $h = Hermit::new([
            new FilteredItem(1, '👍🏽'),
            new FilteredItem(2, '👍🏿'),
            new FilteredItem(3, '👎🏽'),
        ])->show()->setMatchStyle("\x1b[31m");

        $h = $h->type('👍');

        $bg = str_repeat("....................\n", 5);
        $view = $h->View($bg);

        $this->assertStringContainsString("\x1b[31m", $view);
    }

    public function testHighlightMatchesExactSGRBytes(): void
    {
        // 'banana' filtered by 'an' yields the highlighted fragment:
        // "\x1b[33man\x1b[0m" embedded in the item line (yellow highlight).
        // Use explicit windowWidth to avoid computeWidth() path and ensure
        // no truncateAnsi truncation of the highlighted string.
        $h = Hermit::new([new FilteredItem(1, 'banana')])
            ->setWindowWidth(40)
            ->setMatchStyle("\x1b[33m")
            ->show()
            ->type('an');

        $bg = str_repeat(str_repeat(' ', 40) . "\n", 5);
        $view = $h->View($bg);

        // Assert the exact SGR placement: opening code, matched run, reset.
        // strpos is used directly because PHPUnit's assertStringContainsString
        // may represent non-printable bytes differently in failure output.
        $this->assertNotFalse(
            \strpos($view, "\x1b[33man\x1b[0m"),
            'highlighted substring with SGR wrap should appear in View output',
        );
    }

    public function testSigwinchOnResizeCallback(): void
    {
        $receivedCols = -1;
        $receivedRows = -1;

        $h = $this->makeHermit()->withOnResize(
            static function (int $cols, int $rows) use (&$receivedCols, &$receivedRows): void {
                $receivedCols = $cols;
                $receivedRows = $rows;
            }
        );

        $this->assertNotNull($h->onResize());

        // Simulate invoking the callback directly (as SignalForwarder would)
        $cb = $h->onResize();
        $cb(120, 40);

        $this->assertSame(120, $receivedCols);
        $this->assertSame(40, $receivedRows);

        // attachSigwinch returns false when no callback is set
        $hNoCb = $this->makeHermit();
        $this->assertFalse($hNoCb->attachSigwinch());
    }

    public function testAttachSigwinchInstallsHandler(): void
    {
        // attachSigwinch returns true only when SIGWINCH + pcntl are available.
        if (!\function_exists('pcntl_signal') || !\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH or pcntl not available');
        }

        $h = $this->makeHermit()->withOnResize(
            static function (int $cols, int $rows): void {
                // no-op callback for testing attachSigwinch install
            },
        );

        $result = $h->attachSigwinch();

        $this->assertTrue($result, 'attachSigwinch should return true when callback set and signals available');
    }

    public function testScrollingViewportKeepsCursorVisible(): void
    {
        // Build 20 items in a windowHeight=5 (so 3 visible rows for items).
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = new FilteredItem($i + 1, "item{$i}");
        }
        $h = Hermit::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        // cursorBottom() moves to the last item (index 19).
        $h = $h->cursorBottom();

        // The viewport should have scrolled so item[19] is visible.
        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $result = $h->View($bg);

        // The last item's text appears in output; first item does not (viewport scrolled).
        $this->assertStringContainsString('item19', $result, 'last item should be visible in scrolled viewport');
        $this->assertStringNotContainsString('item0', $result, 'first item should not be visible when scrolled to bottom');
    }

    public function testScrollingViewportFitsInWindowCase(): void
    {
        // When all items fit in the window, item[0] should still render at top.
        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];
        $h = Hermit::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $result = $h->View($bg);

        // First item should be visible at the top when items fit in window.
        $this->assertStringContainsString('apple', $result, 'first item should be visible at top when fits in window');
    }

    public function testHelpBarAndStatusBarRenderInView(): void
    {
        // Use 2 items so there's room for bars
        // Background needs to be tall enough for bars (windowHeight=5 + 2 bars = 7 lines)
        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
        ];
        $helpBar = new HelpBar(['Esc' => 'close']);
        $statusBar = new StatusBar('3 of 12');

        $h = Hermit::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->withHelpBar($helpBar)
            ->withStatusBar($statusBar)
            ->show();

        // Background must be tall enough for the bars (5 window + 2 bars = 7 min)
        $bg = implode("\n", array_fill(0, 10, str_repeat(' ', 40)));
        $result = $h->View($bg);

        // Both HelpBar and StatusBar content should appear in the output.
        $this->assertStringContainsString('Esc: close', $result, 'HelpBar content should appear');
        $this->assertStringContainsString('3 of 12', $result, 'StatusBar content should appear');
    }

    public function testItemWithEmbeddedNewlineDoesNotInjectRows(): void
    {
        // An item value containing an embedded newline should be sanitized
        // to a space, so it doesn't inject extra rows in the output.
        $h = Hermit::new([new FilteredItem(1, "foo\nbar")])
            ->setWindowHeight(3)
            ->setWindowWidth(40)
            ->show();

        $bg = implode("\n", array_fill(0, 3, str_repeat(' ', 40)));
        $result = $h->View($bg);
        $resultLines = explode("\n", rtrim($result, "\n"));

        // The overlay should not inject extra rows - still 3 lines like the background.
        $this->assertSame(3, count($resultLines), 'overlay should not inject rows from embedded newline');
        // The sanitized item should show 'foo' (newline replaced with space, becoming 'foo bar')
        $this->assertStringContainsString('foo', $result, 'item value should be present');
        $this->assertStringNotContainsString("foo\nbar", $result, 'raw newline should not appear in output');
    }

    public function testMaxFilterLengthConstant(): void
    {
        $this->assertSame(256, Hermit::MAX_FILTER_LENGTH);
    }

    public function testTypeRejectsInputAtMaxFilterLength(): void
    {
        // Fill filter to exactly the max length.
        $chars = [];
        for ($i = 0; $i < Hermit::MAX_FILTER_LENGTH; $i++) {
            $chars[] = 'a';
        }
        $h = $this->makeHermit()->show();
        foreach ($chars as $char) {
            $h = $h->type($char);
        }

        // Cursor should still be at 0 (filter is full).
        $this->assertSame(Hermit::MAX_FILTER_LENGTH, \strlen($h->filterText()));

        // Additional type() calls must be silently ignored.
        $h2 = $h->type('b');
        $this->assertSame(Hermit::MAX_FILTER_LENGTH, \strlen($h2->filterText()));
        $this->assertSame($h->filterText(), $h2->filterText());
    }

    public function testBackgroundPaddingWhenOverlayExceedsBackgroundLines(): void
    {
        // windowHeight=5 means overlay needs at least 5 lines.
        // Provide only 2 background lines — Hermit must pad the background.
        $h = $this->makeHermit()
            ->setWindowHeight(5)
            ->setWindowWidth(20)
            ->show();

        $bg = "line1\nline2"; // only 2 lines
        $result = $h->View($bg);

        // The result should have enough lines to render the overlay without
        // silently dropping content (the padding code ensures this).
        $this->assertIsString($result);
        // Verify the prompt appears (Hermit is rendering).
        $this->assertStringContainsString('> ', $result);
    }

    public function testCursorBottomOnEmptyFilteredList(): void
    {
        // When filteredItems is empty, cursorBottom() must floor at 0 (not -1).
        // The \max(0, count-1) ensures count=0 → max(0,-1) → 0.
        // show() must come BEFORE setFilterFn so the filter is applied to allItems.
        $h = $this->makeHermit()
            ->show()
            ->setFilterFn(static fn(Item $i): bool => false);

        $this->assertSame(0, $h->itemCount());
        $h = $h->cursorBottom();
        $this->assertSame(0, $h->cursor(), 'cursorBottom on empty list must floor at 0');
        $this->assertNull($h->selected());
    }

    public function testSelectedReturnsNullWhenCursorOutOfBounds(): void
    {
        // When filtered list is empty, cursor is 0 but items[0] doesn't exist → null.
        // show() must come BEFORE setFilterFn so the filter is applied to allItems.
        $h = $this->makeHermit()
            ->show()
            ->setFilterFn(static fn(Item $i): bool => false);

        $this->assertNull($h->selected());
    }

    public function testReplaceSegmentWithEmptyLine(): void
    {
        // When background line is empty (lineLen=0), replaceSegment returns
        // the replacement string unchanged (early exit path).
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('replaceSegment');
        $method->setAccessible(true);

        $result = $method->invoke($h, '', 0, 10, 'hello');
        $this->assertSame('hello', $result);
    }

    public function testReplaceSegmentWithGraphemeCluster(): void
    {
        // Grapheme clusters (emoji, CJK) must be handled correctly.
        // '日本語' has 3 grapheme clusters but different byte lengths.
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('replaceSegment');
        $method->setAccessible(true);

        // Replace at x=0, width=2 from a CJK string.
        $result = $method->invoke($h, '日本語', 0, 2, 'XX');
        // 'XX' + '本' (remaining graphemes after consuming 2)
        $this->assertStringStartsWith('XX', $result);
    }

    public function testCompositeOverWithShortBackground(): void
    {
        // compositeOver with background shorter than overlay should not crash;
        // lines beyond the background are skipped (destY >= count(bgLines) check).
        $h = $this->makeHermit()
            ->setWindowHeight(5)
            ->setWindowWidth(10)
            ->show();

        // Only 1 line in background, overlay needs 5+.
        $bg = "short";
        $result = $h->View($bg);

        $this->assertIsString($result);
        // Prompt should still appear in output.
        $this->assertStringContainsString('> ', $result);
    }

    public function testCachedComputedWidthInvalidatedOnType(): void
    {
        // With windowWidth=0 (auto), calling type() must invalidate the cached
        // width so the next View() recomputes rather than reusing a stale value.
        $h = $this->makeHermit()
            ->setWindowWidth(0) // auto
            ->show();

        // First render — populates the cache.
        $bg1 = str_repeat(str_repeat(' ', 30) . "\n", 5);
        $h->View($bg1);

        $reflection = new \ReflectionClass($h);
        $cached = $reflection->getProperty('cachedComputedWidth');
        $cached->setAccessible(true);
        $firstCache = $cached->getValue($h);
        $this->assertNotNull($firstCache, 'cache should be populated after first render');

        // type() changes filter → cache must be cleared.
        $h = $h->type('a');
        $this->assertNull($cached->getValue($h), 'cache must be invalidated after type()');
    }

    public function testHighlightFuzzyWithNullResultFallsBackToPrintableText(): void
    {
        // When ranker->match() returns null, highlightFuzzy should return the
        // plain printable text without crashing.
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('highlightFuzzy');
        $method->setAccessible(true);

        $mockRanker = $this->createMock(FuzzyMatcher::class);
        $mockRanker->method('match')->willReturn(null);

        $result = $method->invoke($h, $mockRanker, '● banana', 'x');
        $this->assertIsString($result);
        // Falls back to printable text: the marker prefix is stripped, leaving 'banana'.
        $this->assertStringContainsString('banana', $result);
        // No ANSI reset codes since we're in the null-result fallback path.
        $this->assertStringNotContainsString("\x1b[33m", $result);
    }

    public function testHighlightFuzzyWithEmptyResultFallsBackToPrintableText(): void
    {
        // score=0 and empty indices means isMatched()=false and isEmpty()=true.
        // highlightFuzzy should return the plain printable text.
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('highlightFuzzy');
        $method->setAccessible(true);

        $emptyResult = new MatchResult('x', 'banana', 0, []);
        $mockRanker = $this->createMock(FuzzyMatcher::class);
        $mockRanker->method('match')->willReturn($emptyResult);

        $result = $method->invoke($h, $mockRanker, '● banana', 'x');
        $this->assertIsString($result);
        $this->assertStringContainsString('banana', $result);
    }

    public function testPrintableTextReturnsPlainTextUnchanged(): void
    {
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('printableText');
        $method->setAccessible(true);

        // Plain ASCII text passes through unchanged.
        $result = $method->invoke($h, 'hello world');
        $this->assertSame('hello world', $result);
    }

    public function testPrintableTextStripsAnsiEscapeSequences(): void
    {
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('printableText');
        $method->setAccessible(true);

        // SGR color code should be stripped, leaving only the text.
        $colored = "\x1b[33mWarning\x1b[0m";
        $result = $method->invoke($h, $colored);
        $this->assertSame('Warning', $result);
    }

    public function testPrintableTextStripsComplexAnsiSequences(): void
    {
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('printableText');
        $method->setAccessible(true);

        // Bold + italic + red + underscore (a complex multi-sequence ANSI string).
        $complex = "\x1b[1;3;31;4mAlert\x1b[0m";
        $result = $method->invoke($h, $complex);
        $this->assertSame('Alert', $result);
    }

    public function testPrintableTextStripsCursorMovementSequences(): void
    {
        $h = $this->makeHermit()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('printableText');
        $method->setAccessible(true);

        // CSI cursor movement sequences should be stripped.
        $withCursor = "\x1b[10C\x1b[5Dtext\x1b[0m";
        $result = $method->invoke($h, $withCursor);
        $this->assertSame('text', $result);
    }

    public function testHighlightFuzzyAppliesStyleToMatchedCharacters(): void
    {
        // When the ranker returns a valid (non-empty) match, the Highlighter
        // should wrap the matched runes with the configured style ANSI codes.
        $h = $this->makeHermit()->show()->setMatchStyle("\x1b[33m"); // yellow

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('highlightFuzzy');
        $method->setAccessible(true);

        // 'an' matches 'banana' at indices 1,2 → highlight should wrap 'an'.
        $matchResult = new MatchResult('an', 'banana', 2, [1, 2]);
        $mockRanker = $this->createMock(FuzzyMatcher::class);
        $mockRanker->method('match')->willReturn($matchResult);

        $result = $method->invoke($h, $mockRanker, 'banana', 'an');

        // The matched substring 'an' should be wrapped in the yellow style.
        $this->assertStringContainsString("\x1b[33man\x1b[0m", $result);
    }

    public function testHighlightFuzzyStyleWrapsMatchedRunesWithAnsiReset(): void
    {
        // Verify the style code appears BEFORE the matched text and ANSI reset
        // appears AFTER, forming a proper SGR sequence.
        $h = $this->makeHermit()->show()->setMatchStyle("\x1b[32m"); // green

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('highlightFuzzy');
        $method->setAccessible(true);

        $matchResult = new MatchResult('x', 'hex', 1, [1]);
        $mockRanker = $this->createMock(FuzzyMatcher::class);
        $mockRanker->method('match')->willReturn($matchResult);

        $result = $method->invoke($h, $mockRanker, 'hex', 'x');

        // Verify the green code opens, the matched character appears, then reset closes.
        $this->assertStringContainsString("\x1b[32m", $result);
        $this->assertStringContainsString("\x1b[0m", $result);
        $this->assertStringContainsString('x', $result);
    }
}
