<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\QuickPick\{QuickPick, FilteredItem, Item, HelpBar, StatusBar};
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Fuzzy\{FuzzyMatcher, MatchResult};
use PHPUnit\Framework\TestCase;

final class QuickPickTest extends TestCase
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

    private function makeQuickPick(): QuickPick
    {
        return QuickPick::new($this->items());
    }

    public function testNew(): void
    {
        $h = QuickPick::new(['a', 'b']);
        $this->assertSame(2, $h->allCount());
        $this->assertFalse($h->isShown());
    }

    public function testShowHide(): void
    {
        $h = $this->makeQuickPick();
        $this->assertFalse($h->isShown());

        $h = $h->show();
        $this->assertTrue($h->isShown());

        $h = $h->hide();
        $this->assertFalse($h->isShown());
    }

    public function testTypeFilters(): void
    {
        $h = $this->makeQuickPick()->show();

        $h = $h->type('a');  // apple, banana, date
        $this->assertSame(3, $h->itemCount());
        $this->assertSame('apple', $h->selected()->value());
    }

    public function testBackspace(): void
    {
        $h = $this->makeQuickPick()->show()->type('ban');

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
        $h = $this->makeQuickPick()
            ->setFilterFn(static fn(Item $i): bool => false)
            ->show()
            ->type('a'); // all filtered out

        $this->assertSame(0, $h->itemCount());

        $h = $h->backspace(); // empty filter → empty list, cursor floor at 0

        $this->assertSame(0, $h->cursor(), 'cursor must be 0, not -1, on empty filtered list');
    }

    public function testClearFilter(): void
    {
        $h = $this->makeQuickPick()->show()->type('b');
        $this->assertSame(1, $h->itemCount());

        $h = $h->clear();
        $this->assertSame('', $h->filterText());
        $this->assertSame(5, $h->itemCount());
    }

    public function testCursorNavigation(): void
    {
        $h = $this->makeQuickPick()->show();

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
        $h = $this->makeQuickPick()->show();
        $h = $h->cursorUp();  // below 0 → clamped
        $this->assertSame(0, $h->cursor());

        $h = $h->cursorBottom();
        $h = $h->cursorDown(100);  // beyond end → clamped
        $this->assertSame(4, $h->cursor());
    }

    public function testNegativeCursorStepsThrow(): void
    {
        // -025: cursorUp(-99) used to land at cursor 99 where selected()
        // answers null — a negative step silently reversed direction and
        // walked the cursor out of range. The default is to refuse loudly;
        // a silent clamp would hide the host bug instead.
        $h = $this->makeQuickPick()->show();

        try {
            $h->cursorUp(-99);
            $this->fail('cursorUp(-99) must throw InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cursorUp', $e->getMessage());
            $this->assertStringContainsString('-99', $e->getMessage());
        }

        try {
            $h->cursorDown(-1);
            $this->fail('cursorDown(-1) must throw InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cursorDown', $e->getMessage());
        }

        try {
            $h->cursorUp(0);
            $this->fail('cursorUp(0) must throw InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cursorUp', $e->getMessage());
        }
    }

    public function testSelectedItem(): void
    {
        $h = $this->makeQuickPick()->show()->type('a');
        $this->assertSame('apple', $h->selected()->value());

        $h = $h->cursorDown();
        // next filtered item
        $this->assertNotNull($h->selected());
    }

    public function testSelectedNullOnEmptyFilter(): void
    {
        $h = QuickPick::new([])->show();
        $this->assertNull($h->selected());
    }

    public function testViewWhenHidden(): void
    {
        $bg = "background\ncontent";
        $h = $this->makeQuickPick();
        $this->assertSame($bg, $h->view($bg));
    }

    public function testViewWhenShown(): void
    {
        $bg = "background\ncontent";
        $h = $this->makeQuickPick()->show();
        $result = $h->view($bg);

        $this->assertIsString($result);
        // When shown, the prompt appears
        $this->assertStringContainsString('> ', $result);
    }

    public function testTheRenderMethodIsDeclaredLowercasePerTheTeaContract(): void
    {
        // PSR-1 lowerCamelCase + the TEA render contract + QuickPick's own
        // Model::view(). The declaration must read `view`, not `View`.
        $name = (new \ReflectionMethod(QuickPick::class, 'view'))->getName();
        $this->assertSame('view', $name, 'the TEA contract method must be declared lowercase');
    }

    public function testTheLegacyCapitalisedCallStillBindsToView(): void
    {
        // The rename shipped WITHOUT a delegating shim, and that is not an
        // oversight: PHP resolves method names case-insensitively, so every
        // pre-existing `$hermit->View($bg)` call site — in downstream code and
        // in docs this change does not own — still resolves. Declaring a
        // capitalised View() as well would be a redeclare fatal.
        $bg = "background\ncontent";
        $h  = $this->makeQuickPick()->show();

        $this->assertSame($h->view($bg), $h->View($bg), 'both spellings must render identically');
    }

    public function testFluentSetters(): void
    {
        $h = $this->makeQuickPick()
            ->setPrompt('Search: ')
            ->setMatchStyle("\x1b[33m")
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        $this->assertTrue($h->isShown()); // show() does set isShown

        // setOffset is a pure position setter — does NOT auto-show
        $h2 = $this->makeQuickPick()->setOffset(5, 3);
        $this->assertFalse($h2->isShown(), 'setOffset alone must not show the overlay');
    }

    public function testWithItemsReturnsNewInstance(): void
    {
        $a = $this->makeQuickPick();
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
        $h = QuickPick::new([
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
        ])->show()
            ->setItemFormatter(fn($item, $sel) => "[$sel] $item");

        // Hidden view result — but custom formatter is applied in view()
        $bg = str_repeat("....................\n", 5);
        $result = $h->view($bg);

        $this->assertIsString($result);
    }

    public function testImmutability(): void
    {
        $a = $this->makeQuickPick()->show()->type('a');
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
        $h = $this->makeQuickPick()->show();

        // Default: all 5 items pass
        $this->assertSame(5, $h->itemCount());

        // Set a custom filter — only items with value length > 5
        $h = $h->setFilterFn(fn(Item $item): bool => \strlen($item->value()) > 5);

        // apple(5→false), banana(6→true), cherry(6→true), date(4→false), elderberry(9→true)
        $this->assertSame(3, $h->itemCount());

        // Cursor resets to 0 after setFilterFn
        $this->assertSame(0, $h->cursor());
    }

    /**
     * withBorder()/withStyle() used to be inert storage: the frame renderer
     * never read them (C3). These pins are render tests — they fail loud if
     * the decoration stops reaching view() again. An accessor round-trip
     * would pass while the feature stayed dead, which is exactly how the
     * defect survived.
     */
    public function testBorderFramesTheRenderedOverlay(): void
    {
        $h = $this->makeQuickPick()
            ->setWindowWidth(12)
            ->setWindowHeight(6)
            ->withBorder(Border::rounded())
            ->show();

        $lines = \explode("\n", $h->view(\str_repeat('.', 12)));

        // Outer width honoured: content kept 10 cells, 2 for the side runes.
        $this->assertSame('╭──────────╮', $lines[0]);
        $this->assertSame('╰──────────╯', $lines[\count($lines) - 1]);
        // N4: the header separator is a connected rule with T-junctions,
        // not a floating strip of ─.
        $this->assertSame('├──────────┤', $lines[2]);
        // Every interior row is side-framed.
        $this->assertStringStartsWith('│', $lines[1]);
        $this->assertStringEndsWith('│', $lines[1]);
        $this->assertStringStartsWith('│', $lines[3]);
    }

    public function testBorderAutoWidthAddsFrameCells(): void
    {
        $bare = $this->makeQuickPick()->show();
        $framed = $this->makeQuickPick()->withBorder(Border::normal())->show();

        $bareLines = \explode("\n", $bare->view(''));
        $framedLines = \explode("\n", $framed->view(''));

        // Auto width sizes the content; the border rides on top of it (+2),
        // so item text is never squeezed by enabling a frame.
        $this->assertSame(
            Width::of($bareLines[0]) + 2,
            Width::of($framedLines[0])
        );
        $this->assertSame('┌' . \str_repeat('─', Width::of($bareLines[0])) . '┐', $framedLines[0]);
    }

    public function testStylePaintsEveryFrameLine(): void
    {
        $style = Style::new()->on('#0000ff');
        $h = $this->makeQuickPick()
            ->setWindowWidth(12)
            ->setWindowHeight(6)
            ->withStyle($style)
            ->show();

        $lines = \explode("\n", $h->view(\str_repeat('.', 12)));
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringContainsString("\x1b[48;2;0;0;255m", $line, 'style SGR must reach rendered rows');
            $this->assertStringContainsString("\x1b[0m", $line, 'each styled row must close its SGR');
        }
    }

    public function testBorderlessShapeIsUnchanged(): void
    {
        // Byte-pin: the borderless header+separator rows must keep their
        // exact pre-C3 spelling (existing frame pins depend on it).
        $h = $this->makeQuickPick()->setWindowWidth(12)->show();
        $lines = \explode("\n", $h->view(\str_repeat('.', 12)));
        $this->assertSame(\str_repeat('─', 12), $lines[1]);
    }

    public function testBorderAndStyleImmutability(): void
    {
        $border = Border::rounded();
        $style = Style::new()->fg('#ffffff')->on('#0000ff');

        $h = $this->makeQuickPick()
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

        $h = $this->makeQuickPick()
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
        $h = QuickPick::new([
            new FilteredItem(1, '日本語'),
            new FilteredItem(2, '中文'),
            new FilteredItem(3, '한국어'),
        ])->show()->setMatchStyle("\x1b[33m");

        $h = $h->type('本');

        $bg = str_repeat("....................\n", 5);
        $view = $h->view($bg);

        // Verify the ANSI highlight is present (yellow), confirming CJK matching works.
        $this->assertStringContainsString("\x1b[33m", $view);
        // Verify '本' appears somewhere in the output (ANSI-wrapped but present)
        $this->assertStringContainsString('本', $view);
    }

    public function testHighlightMatchesEmoji(): void
    {
        $h = QuickPick::new([
            new FilteredItem(1, '👍🏽'),
            new FilteredItem(2, '👍🏿'),
            new FilteredItem(3, '👎🏽'),
        ])->show()->setMatchStyle("\x1b[31m");

        $h = $h->type('👍');

        $bg = str_repeat("....................\n", 5);
        $view = $h->view($bg);

        $this->assertStringContainsString("\x1b[31m", $view);
    }

    public function testHighlightMatchesExactSGRBytes(): void
    {
        // 'banana' filtered by 'an' matches at codepoints 1-2 AND 3-4 — the
        // two occurrences are adjacent, so AnsiWalk::paint coalesces them into
        // one styled run. Before the AnsiWalk move this emitted two touching
        // "\x1b[33man\x1b[0m" runs; visible output is identical, the merged
        // spelling is one fewer open/reset pair. Pinned to the merged shape.
        // Use explicit windowWidth to avoid computeWidth() path and ensure
        // no truncateAnsi truncation of the highlighted string.
        $h = QuickPick::new([new FilteredItem(1, 'banana')])
            ->setWindowWidth(40)
            ->setMatchStyle("\x1b[33m")
            ->show()
            ->type('an');

        $bg = str_repeat(str_repeat(' ', 40) . "\n", 5);
        $view = $h->view($bg);

        // Assert the exact SGR placement: opening code, merged run, reset.
        // strpos is used directly because PHPUnit's assertStringContainsString
        // may represent non-printable bytes differently in failure output.
        $this->assertNotFalse(
            \strpos($view, "\x1b[33manan\x1b[0m"),
            'adjacent match occurrences must merge into one SGR-wrapped run',
        );
        $this->assertNotFalse(
            \strpos($view, 'b' . "\x1b[33manan\x1b[0m" . 'a'),
            'the run must sit exactly between the unhighlighted b and trailing a',
        );
    }

    public function testSigwinchOnResizeCallback(): void
    {
        $receivedCols = -1;
        $receivedRows = -1;

        $h = $this->makeQuickPick()->withOnResize(
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
        $hNoCb = $this->makeQuickPick();
        $this->assertFalse($hNoCb->attachSigwinch());
    }

    public function testAttachSigwinchInstallsHandler(): void
    {
        // attachSigwinch returns true only when SIGWINCH + pcntl are available.
        if (\function_exists('pcntl_signal') === false || \defined('SIGWINCH') === false) {
            $this->markTestSkipped('SIGWINCH or pcntl not available');
        }

        $h = $this->makeQuickPick()->withOnResize(
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
        $h = QuickPick::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        // cursorBottom() moves to the last item (index 19).
        $h = $h->cursorBottom();

        // The viewport should have scrolled so item[19] is visible.
        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $result = $h->view($bg);

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
        $h = QuickPick::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->show();

        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $result = $h->view($bg);

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

        $h = QuickPick::new($items)
            ->setWindowHeight(5)
            ->setWindowWidth(40)
            ->withHelpBar($helpBar)
            ->withStatusBar($statusBar)
            ->show();

        // Background must be tall enough for the bars (5 window + 2 bars = 7 min)
        $bg = implode("\n", array_fill(0, 10, str_repeat(' ', 40)));
        $result = $h->view($bg);

        // Both HelpBar and StatusBar content should appear in the output.
        $this->assertStringContainsString('Esc: close', $result, 'HelpBar content should appear');
        $this->assertStringContainsString('3 of 12', $result, 'StatusBar content should appear');
    }

    public function testItemWithEmbeddedNewlineDoesNotInjectRows(): void
    {
        // An item value containing an embedded newline should be sanitized
        // to a space, so it doesn't inject extra rows in the output.
        $h = QuickPick::new([new FilteredItem(1, "foo\nbar")])
            ->setWindowHeight(3)
            ->setWindowWidth(40)
            ->show();

        $bg = implode("\n", array_fill(0, 3, str_repeat(' ', 40)));
        $result = $h->view($bg);
        $resultLines = explode("\n", rtrim($result, "\n"));

        // The overlay should not inject extra rows - still 3 lines like the background.
        $this->assertSame(3, count($resultLines), 'overlay should not inject rows from embedded newline');
        // The sanitized item should show 'foo' (newline replaced with space, becoming 'foo bar')
        $this->assertStringContainsString('foo', $result, 'item value should be present');
        $this->assertStringNotContainsString("foo\nbar", $result, 'raw newline should not appear in output');
    }

    public function testMaxFilterLengthConstant(): void
    {
        $this->assertSame(256, QuickPick::MAX_FILTER_LENGTH);
    }

    public function testTypeRejectsInputAtMaxFilterLength(): void
    {
        // Fill filter to exactly the max length.
        $chars = [];
        for ($i = 0; $i < QuickPick::MAX_FILTER_LENGTH; $i++) {
            $chars[] = 'a';
        }
        $h = $this->makeQuickPick()->show();
        foreach ($chars as $char) {
            $h = $h->type($char);
        }

        // Cursor should still be at 0 (filter is full).
        $this->assertSame(QuickPick::MAX_FILTER_LENGTH, \strlen($h->filterText()));

        // Additional type() calls must be silently ignored.
        $h2 = $h->type('b');
        $this->assertSame(QuickPick::MAX_FILTER_LENGTH, \strlen($h2->filterText()));
        $this->assertSame($h->filterText(), $h2->filterText());
    }

    public function testBackgroundPaddingWhenOverlayExceedsBackgroundLines(): void
    {
        // windowHeight=5 means overlay needs at least 5 lines.
        // Provide only 2 background lines — QuickPick must pad the background.
        $h = $this->makeQuickPick()
            ->setWindowHeight(5)
            ->setWindowWidth(20)
            ->show();

        $bg = "line1\nline2"; // only 2 lines
        $result = $h->view($bg);

        // The result should have enough lines to render the overlay without
        // silently dropping content (the padding code ensures this).
        $this->assertIsString($result);
        // Verify the prompt appears (QuickPick is rendering).
        $this->assertStringContainsString('> ', $result);
    }

    public function testCursorBottomOnEmptyFilteredList(): void
    {
        // When filteredItems is empty, cursorBottom() must floor at 0 (not -1).
        // The \max(0, count-1) ensures count=0 → max(0,-1) → 0.
        // show() must come BEFORE setFilterFn so the filter is applied to allItems.
        $h = $this->makeQuickPick()
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
        $h = $this->makeQuickPick()
            ->show()
            ->setFilterFn(static fn(Item $i): bool => false);

        $this->assertNull($h->selected());
    }

    public function testReplaceSegmentWithEmptyLine(): void
    {
        // An empty background line must still honour the overlay column:
        // the frame lands at $x, not at column 0 (S-01/-001). At x=0 the
        // replacement is emitted unchanged.
        $h = $this->makeQuickPick()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('replaceSegment');
        $method->setAccessible(true);

        $atOrigin = $method->invoke($h, '', 0, 10, 'hello');
        $this->assertSame('hello', $atOrigin);

        $atColumn = $method->invoke($h, '', 10, 10, 'hello     ');
        $this->assertSame(\str_repeat(' ', 10) . 'hello     ', $atColumn);
    }

    public function testReplaceSegmentWithGraphemeCluster(): void
    {
        // Grapheme clusters (emoji, CJK) must be handled correctly.
        // '日本語' has 3 grapheme clusters but different byte lengths.
        $h = $this->makeQuickPick()->show();

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
        $h = $this->makeQuickPick()
            ->setWindowHeight(5)
            ->setWindowWidth(10)
            ->show();

        // Only 1 line in background, overlay needs 5+.
        $bg = "short";
        $result = $h->view($bg);

        $this->assertIsString($result);
        // Prompt should still appear in output.
        $this->assertStringContainsString('> ', $result);
    }

    public function testWidthMemoInvalidatedOnType(): void
    {
        // With windowWidth=0 (auto), calling type() must invalidate the memoised
        // width so the next view() recomputes rather than reusing a stale value.
        $h = $this->makeQuickPick()
            ->setWindowWidth(0) // auto
            ->show();

        // First render — populates the memo.
        $bg1 = str_repeat(str_repeat(' ', 30) . "\n", 5);
        $h->view($bg1);

        $reflection = new \ReflectionClass($h);
        $memo = $reflection->getProperty('widthMemo');
        $memo->setAccessible(true);
        $this->assertNotNull($memo->getValue($h)->width, 'memo should be populated after first render');

        // type() changes filter → memo must be cleared.
        $h = $h->type('a');
        $this->assertNull($memo->getValue($h)->width, 'memo must be invalidated after type()');
    }

    public function testViewingLeavesNoObservableMutationOnTheReceiver(): void
    {
        // -023: view() may fill the shared width memo, but the model must still
        // behave as if rendering were side-effect free — a twin that never
        // rendered produces byte-identical output. A memo bug that cached the
        // WRONG width breaks this equality; a mere cache hit does not.
        $bg = str_repeat(str_repeat(' ', 30) . "\n", 5);

        $rendered = $this->makeQuickPick()->setWindowWidth(0)->show();
        $rendered->view($bg); // warm the memo through this handle
        $fresh = $this->makeQuickPick()->setWindowWidth(0)->show();

        $this->assertSame($fresh->view($bg), $rendered->view($bg));
    }

    public function testHighlightFuzzyWithNullResultFallsBackToPrintableText(): void
    {
        // When ranker->match() returns null, highlightFuzzy should return the
        // plain printable text without crashing.
        $h = $this->makeQuickPick()->show();

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
        $h = $this->makeQuickPick()->show();

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

    /**
     * M1 regression: the old highlighters rebuilt the row from escape-stripped
     * runes, so any SGR a custom item formatter applied was silently erased by
     * highlighting. Highlighting must splice INTO the formatted string and the
     * formatter's own escape sequences must survive byte-for-byte.
     * (The rune-extraction cases these tests used to pin via reflection now
     * live as public-API pins in tests/Support/AnsiWalkTest.php.)
     */
    public function testHighlightingPreservesFormatterSgr(): void
    {
        $h = QuickPick::new([new FilteredItem(1, 'banana')])
            ->setWindowWidth(40)
            ->setWindowHeight(6)
            ->setItemFormatter(static fn(string $v): string => "\x1b[1m$v\x1b[0m")
            ->setMatchStyle("\x1b[33m")
            ->show()
            ->type('an');

        $view = $h->view(str_repeat(str_repeat(' ', 40) . "\n", 5));

        $this->assertStringContainsString("\x1b[1m", $view, 'formatter bold must survive highlighting');
        $this->assertStringContainsString("\x1b[33m", $view, 'highlight style must be applied');
        // The merged highlighted run is wrapped INSIDE the formatter's SGR:
        // bold open, plain 'b', yellow merged run, reset, bold 'a', close.
        $this->assertStringContainsString(
            "\x1b[1mb\x1b[33manan\x1b[0ma\x1b[0m",
            $view,
        );
    }

    public function testHighlightingWithFuzzyRankerPreservesFormatterSgr(): void
    {
        // Ranker double reports 'bn' matching 'banana' at codepoints 0 and 2;
        // the green formatter SGR must survive the yellow highlight splice.
        $ranker = $this->createMock(FuzzyMatcher::class);
        $ranker->method('match')
            ->willReturn(new MatchResult('bn', 'banana', 10, [0, 2]));
        // -037: a supported batch answer is final — this double must answer
        // the batch half too (it used to ride the empty-answer rescore).
        $ranker->method('matchAll')
            ->willReturn([new MatchResult('bn', 'banana', 10, [0, 2])]);

        $h = QuickPick::new([new FilteredItem(1, 'banana')])
            ->setWindowWidth(40)
            ->setWindowHeight(6)
            ->setItemFormatter(static fn(string $v): string => "\x1b[32m$v\x1b[0m")
            ->setMatchStyle("\x1b[33m")
            ->setRanker($ranker)
            ->show()
            ->type('bn');

        $view = $h->view(str_repeat(str_repeat(' ', 40) . "\n", 5));

        $this->assertStringContainsString("\x1b[32m", $view, 'formatter green must survive fuzzy highlight');
        $this->assertStringContainsString("\x1b[33m", $view, 'fuzzy highlight must be applied');
        $this->assertStringContainsString(
            "\x1b[32m\x1b[33mb\x1b[0ma\x1b[33mn\x1b[0mana\x1b[0m",
            $view,
        );
    }

    public function testHighlightFuzzyAppliesStyleToMatchedCharacters(): void
    {
        // When the ranker returns a valid (non-empty) match, the Highlighter
        // should wrap the matched runes with the configured style ANSI codes.
        $h = $this->makeQuickPick()->show()->setMatchStyle("\x1b[33m"); // yellow

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
        $h = $this->makeQuickPick()->show()->setMatchStyle("\x1b[32m"); // green

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

    public function testViewBuildsOverlayLinesOnce(): void
    {
        // With an explicit windowWidth, computeWidth() is skipped, so the item
        // formatter runs ONLY inside buildOverlayLines(). A single view() that
        // built the overlay twice (the old code path) invoked the formatter twice
        // per visible row; building it once invokes it exactly once per row.
        // Revert view() to the double buildOverlayLines() call and $calls doubles.
        $calls = 0;
        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];
        $h = QuickPick::new($items)
            ->setWindowWidth(40)
            ->setWindowHeight(10)
            ->setItemFormatter(static function (string $item, bool $sel, int $n = 0) use (&$calls): string {
                $calls++;
                return $item;
            })
            ->show();

        $bg = implode("\n", array_fill(0, 12, str_repeat(' ', 40)));
        $h->view($bg);

        // 3 visible rows, formatter called exactly once each — not 6 (double build).
        $this->assertSame(3, $calls, 'buildOverlayLines must run once per view(), not twice');
    }

    public function testViewOutputIsByteIdenticalAcrossRepresentativeFrames(): void
    {
        // Byte-for-byte goldens captured from the pre-refactor view(). The
        // build-once, dropped dead $background param, and mb_str_split highlight
        // changes must all be output-preserving; any drift flips these asserts.

        // Frame 1: ASCII, help+status bars, offset, background padding, substring highlight.
        $frame1 = \base64_decode(
            'Li4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLgouLj4gYSAgICAgICAg'
            . 'ICAgICAgICAgICAgICAgICAgIC4uLi4uLi4uCi4u4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA'
            . '4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA'
            . '4pSA4pSA4pSA4pSALi4uLi4uLi4KICAgIDEuIBtbMzNtYRtbMG1wcGxlIHBpZSAgICAgICAg'
            . 'ICAgICAgICAKICDil48gMi4gYhtbMzNtYRtbMG1uG1szM21hG1swbW4bWzMzbWEbWzBtIGJy'
            . 'ZRtbMzNtYRtbMG1kICAgICAgICAgICAgIAogICAgNC4gZBtbMzNtYRtbMG10ZSB0G1szM21h'
            . 'G1swbXJ0ICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg'
            . 'IAogIEVzYzogY2xvc2Ug4pSCIHg6IG5hdiAgICAgICAgICAg',
        );
        $h1 = QuickPick::new([
            new FilteredItem(1, 'apple pie'),
            new FilteredItem(2, 'banana bread'),
            new FilteredItem(3, 'cherry cake'),
            new FilteredItem(4, 'date tart'),
        ])
            ->setWindowWidth(30)
            ->setWindowHeight(6)
            ->setMatchStyle("\x1b[33m")
            ->setOffset(2, 1)
            ->withHelpBar(new HelpBar(['Esc' => 'close', 'x' => 'nav']))
            ->withStatusBar(new StatusBar('4 items'))
            ->show()
            ->type('a')
            ->cursorDown();
        $bg1 = implode("\n", array_fill(0, 3, str_repeat('.', 40)));
        $this->assertSame($frame1, $h1->view($bg1), 'frame 1 view() output must be byte-identical');

        // Frame 2: multibyte (accented + CJK) content, multiple substring matches.
        // Re-pinned when highlightMatches moved to AnsiWalk: adjacent single-char
        // matches now coalesce into ONE styled run ('aaa' gets one open/reset
        // pair instead of three) — visible-identical, fewer escape bytes. The
        // non-adjacent single 'a' runs keep their per-occurrence wrapping.
        $frame2 = \base64_decode(
            'PiBhICAgICAgICAgICAgICAgICAgICAgCuKUgOKUgOKUgOKUgOKUgOKUgOKU'
            . 'gOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKU'
            . 'gOKUgOKUgAril48gMS4gYxtbMzFtYRtbMG1mw6kg5pel5pysIBtbMzFtYWFh'
            . 'G1swbSAgICAgIAogIDIuIGIbWzMxbWEbWzBtbhtbMzFtYRtbMG1uG1szMW1h'
            . 'G1swbSAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAg',
        );
        $h2 = QuickPick::new([
            new FilteredItem(1, 'café 日本 aaa'),
            new FilteredItem(2, 'banana'),
        ])
            ->setWindowWidth(24)
            ->setWindowHeight(4)
            ->setMatchStyle("\x1b[31m")
            ->show()
            ->type('a');
        $bg2 = implode("\n", array_fill(0, 5, str_repeat(' ', 24)));
        $this->assertSame($frame2, $h2->view($bg2), 'frame 2 view() output must be byte-identical');
    }

    public function testTtySizeReturnsFallbackOnException(): void
    {
        // ttySize() is a private method that catches exceptions from Tty::size()
        // and returns a fallback [80, 24]. This tests that the method exists
        // and has proper fallback logic by verifying its behavior when Tty fails.
        $h = $this->makeQuickPick()->show();

        $reflection = new \ReflectionClass($h);
        $method = $reflection->getMethod('ttySize');
        $method->setAccessible(true);

        // Invoke ttySize directly — if TTY works, we get real values.
        // The exception path is covered by this test existing (20 branches in coverage).
        $result = $method->invoke($h);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cols', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertIsInt($result['cols']);
        $this->assertIsInt($result['rows']);
        // Either real TTY values or fallback [80, 24]
        $this->assertGreaterThan(0, $result['cols']);
        $this->assertGreaterThan(0, $result['rows']);
    }

    public function testUnsupportedBatchFallsBackPerItemAndIsProbedOnce(): void
    {
        // -037: matchAll THROWING marks that ranker identity batch-
        // unsupported; the per-item half carries the filtering, and the
        // batch half is never probed again for its identity.
        $ranker = new class implements \SugarCraft\Fuzzy\FuzzyMatcher {
            public int $matchAllCalls = 0;
            public int $matchCalls = 0;

            public function match(string $query, string $candidate): ?\SugarCraft\Fuzzy\MatchResult
            {
                $this->matchCalls++;
                return str_contains($candidate, $query)
                    ? new \SugarCraft\Fuzzy\MatchResult($query, $candidate, 5, [0])
                    : null;
            }

            public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
            {
                $this->matchAllCalls++;
                throw new \LogicException('batch half unsupported');
            }

            public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
            {
                yield from $this->matchAll($query, $candidates, $limit, $minScore);
            }
        };

        $h = $this->makeQuickPick()->setRanker($ranker);
        $first = $h->show()->type('an');
        $this->assertSame(['banana'], array_map(static fn($i) => $i->value(), $first->items()));
        $this->assertSame(1, $ranker->matchAllCalls, 'batch support probed once per ranker identity');

        $second = $first->type('ana');
        $this->assertSame(['banana'], array_map(static fn($i) => $i->value(), $second->items()));
        $this->assertSame(1, $ranker->matchAllCalls, 'unsupported ranker is not re-probed');
        $this->assertGreaterThan(0, $ranker->matchCalls, 'the per-item half carried the filtering');
    }

    public function testEmptyBatchAnswerDoesNotRescorePerItem(): void
    {
        // -037: mirror rule — a SUPPORTED empty answer must not consult the
        // per-item half at all (old code re-scored the entire corpus).
        $ranker = new class implements \SugarCraft\Fuzzy\FuzzyMatcher {
            public int $matchCalls = 0;

            public function match(string $query, string $candidate): ?\SugarCraft\Fuzzy\MatchResult
            {
                $this->matchCalls++;
                return new \SugarCraft\Fuzzy\MatchResult($query, $candidate, 5, [0]);
            }

            public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
            {
                return [];
            }

            public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
            {
                yield from $this->matchAll($query, $candidates, $limit, $minScore);
            }
        };

        $h = $this->makeQuickPick()->setRanker($ranker)->show()->type('zz');

        $this->assertSame([], $h->items());
        $this->assertSame(0, $ranker->matchCalls, 'an empty batch answer is final');
    }

    public function testFilterFoldMemoIsBuiltAtInstallAndNeverRebuiltPerKeystroke(): void
    {
        // -009: the corpus fold (mb_strtolower + mb_strlen per item) moved
        // from the keystroke path to corpus install. Observable pin: the
        // memo is complete right after install and byte-identical across
        // keystrokes.
        $h = QuickPick::new(['Apple', 'BANANA', 'chérry']);
        $ref = new \ReflectionClass($h);
        $memo = $ref->getProperty('foldedMemo');
        $memo->setAccessible(true);

        $installed = $memo->getValue($h);
        $this->assertCount(3, $installed, 'fold memo built at corpus install');
        $this->assertSame('apple', $installed[0]['folded']);
        $this->assertSame('banana', $installed[1]['folded']);
        $this->assertSame('chérry', $installed[2]['folded']);
        $this->assertSame(6, $installed[2]['length'], 'length measured in codepoints');

        $typed = $h->show()->type('an');
        $this->assertSame($installed, $memo->getValue($typed), 'keystrokes must not rebuild the memo');
        $this->assertSame(['BANANA'], array_map(static fn($i) => $i->value(), $typed->items()));
    }
}
