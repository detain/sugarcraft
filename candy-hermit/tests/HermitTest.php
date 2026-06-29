<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\{Hermit, FilteredItem, Item, HelpBar, StatusBar};
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
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
}
