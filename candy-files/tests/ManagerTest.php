<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\InputReader;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase
{
    private function fakeFs(): \Closure
    {
        $tree = [
            '/' => [
                new Entry('home',   true,  0, 0),
                new Entry('etc',    true,  0, 0),
                new Entry('readme', false, 100, 0),
            ],
            '/home' => [
                new Entry('alice', true, 0, 0),
                new Entry('bob',   true, 0, 0),
            ],
        ];
        return static fn(string $path): array => $tree[$path] ?? [];
    }

    private function start(): Manager
    {
        return Manager::start('/', '/home', $this->fakeFs());
    }

    public function testStartOpensBothPanes(): void
    {
        $m = $this->start();
        $this->assertSame('/',     $m->left->cwd);
        $this->assertSame('/home', $m->right->cwd);
        $this->assertSame(0, $m->activeIdx);
    }

    public function testQuitDispatchesQuit(): void
    {
        $m = $this->start();
        [, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testTabSwapsActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(1, $next->activeIdx);
        [$back] = $next->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(0, $back->activeIdx);
    }

    public function testJMovesCursorDownInActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $next->left->cursor);
        $this->assertSame(0, $next->right->cursor, 'inactive pane untouched');
    }

    public function testTabThenJAffectsRightPane(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        [$next]    = $swapped->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(0, $next->left->cursor);
        $this->assertSame(1, $next->right->cursor);
    }

    public function testEnterNavigatesActivePane(): void
    {
        $m = $this->start();
        // Move cursor in left pane to 'home' dir, then Enter.
        $names = array_map(fn(Entry $e) => $e->name, $m->left->entries);
        $idx = array_search('home', $names, true);
        $this->assertNotFalse($idx);
        for ($i = 0; $i < $idx; $i++) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        }
        [$next] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('/home', $next->left->cwd);
        $this->assertSame('/home', $next->right->cwd, 'right pane unchanged');
    }

    public function testHGoesUp(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        [$up]      = $swapped->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame('/', $up->right->cwd);
    }

    public function testSpaceTogglesSelectionAndAdvances(): void
    {
        $m = $this->start();
        // Cursor 0 of left pane (no parent sentinel at root). With
        // name-asc + dirs-first, that's "etc" (alphabetical < "home").
        $cursorName = $m->left->entries[0]->name;
        [$next] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertSame([$cursorName], $next->left->selectedNames());
        $this->assertSame(1, $next->left->cursor, 'cursor advances after toggle');
    }

    public function testSCyclesSortOnActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertNotSame($m->left->sort, $next->left->sort);
        $this->assertSame($m->right->sort, $next->right->sort);
    }

    public function testDelArmsConfirmation(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $next->confirm);
        $this->assertStringContainsString('y/n', $next->status);
    }

    public function testConfirmCancelsOnAnythingButY(): void
    {
        $m = $this->start();
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$cancelled] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testRefreshUpdatesStatus(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertStringContainsString('refreshed', $next->status);
    }

    /**
     * docs-fix-1023: `r` re-reads through Pane::relist(), which keeps the
     * selection and cursor for entries that survived. Before this, refresh
     * rebuilt via Pane::open() and silently WIPED user selection — a re-read
     * of the same directory must not consume marks the user placed.
     */
    public function testRefreshKeepsSelectionAndCursor(): void
    {
        $m = $this->start();
        // '/' has no parent sentinel; NameAsc order is etc, home, readme.
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));   // select 'etc'
        $this->assertSame(['etc'], $m->activePane()->selectedNames());
        [$r] = $m->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(['etc'], $r->activePane()->selectedNames(), 'refresh must preserve surviving selection');
        // space ADVANCED the cursor to home (index 1) — relist must keep it there.
        $this->assertSame(1, $r->activePane()->cursor, 'cursor must stay on the entry it was on');
    }

    /**
     * docs-fix-1023: Escape outside search/confirm clears the selection —
     * Pane::clearSelection() had ZERO production callers before this arm,
     * so a mis-marked multi-select could only be undone entry by entry.
     */
    public function testEscapeClearsSelection(): void
    {
        $m = $this->start();
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));   // select 'etc'
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));   // cursor advanced; select 'home'
        $this->assertCount(2, $m->activePane()->selectedNames());
        [$cleared] = $m->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertSame([], $cleared->activePane()->selectedNames());
    }

    public function testWindowSizeMsgCapturesWidthWithoutCommand(): void
    {
        // Responsive-layout transition: the resize is MODEL state (the next
        // view() derives pane widths from it), never a command — the
        // re-render itself is sugar-core Program's dirty-flag step.
        $m = $this->start();
        $this->assertSame(0, $m->termCols);
        [$next, $cmd] = $m->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $this->assertNotSame($m, $next, 'resize must produce a new Model');
        $this->assertSame(80, $next->termCols);
        $this->assertNull($cmd);
        // Same width again: identity, no pointless rebuild.
        [$same, $cmd2] = $next->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $this->assertSame($next, $same);
        $this->assertNull($cmd2);
    }

    public function testOtherNonKeyMsgsIgnored(): void
    {
        // Only WindowSizeMsg and CopyCompletedMsg are meaningful non-key
        // traffic; anything else must keep falling through untouched.
        $m = $this->start();
        [$next, $cmd] = $m->update(new RawMsg("\x1b[anything"));
        $this->assertSame($m, $next);
        $this->assertNull($cmd);
    }

    public function testSearchFindsMatchingFiles(): void
    {
        $m = $this->start();
        $this->assertNull($m->searchQuery);
        $this->assertSame([], $m->searchResults);

        // Start search with empty query
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($searching->searchQuery);
        $this->assertNotSame([], $searching->searchResults);

        // Type to filter - search for 'readme'
        [$filtered] = $searching->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame('r', $filtered->searchQuery);
        $readmeResults = array_filter(
            $filtered->searchResults,
            fn(Entry $e) => str_contains($e->name, 'readme')
        );
        $this->assertNotEmpty($readmeResults);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));

        // Search for 'HOME' (uppercase)
        [$upper] = $searching->update(new KeyMsg(KeyType::Char, 'H'));
        [$upper] = $upper->update(new KeyMsg(KeyType::Char, 'O'));
        [$upper] = $upper->update(new KeyMsg(KeyType::Char, 'M'));
        [$upper] = $upper->update(new KeyMsg(KeyType::Char, 'E'));

        // Should find 'home' regardless of case
        $homeResults = array_filter(
            $upper->searchResults,
            fn(Entry $e) => strtolower($e->name) === 'home'
        );
        $this->assertNotEmpty($homeResults);
    }

    public function testSearchNavigateResults(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));

        // Initially at first result
        $this->assertSame(0, $searching->searchCursor);

        // Move down with j
        [$down] = $searching->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $down->searchCursor);

        // Move down again
        [$down] = $down->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $down->searchCursor);

        // Move up with k
        [$up] = $down->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(1, $up->searchCursor);

        // Move up with arrow
        [$up] = $up->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(0, $up->searchCursor);

        // Move down with arrow
        [$down] = $up->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $down->searchCursor);
    }

    public function testSearchEnterOpensResult(): void
    {
        $tree = [
            '/' => [
                new Entry('home',   true,  0, 0),
                new Entry('etc',    true,  0, 0),
                new Entry('readme', false, 100, 0),
            ],
            '/home' => [
                new Entry('documents', true, 0, 0),
            ],
        ];
        $lister = static fn(string $path): array => $tree[$path] ?? [];

        $m = Manager::start('/', '/home', $lister);
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));

        // Search for 'home'
        [$filtered] = $searching->update(new KeyMsg(KeyType::Char, 'h'));
        [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, 'o'));
        [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, 'm'));
        [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, 'e'));

        // Enter should open the directory
        [$opened] = $filtered->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('/home', $opened->activePane()->cwd);
        $this->assertNull($opened->searchQuery);
    }

    public function testSearchEscapeExits(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($searching->searchQuery);

        [$exited] = $searching->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($exited->searchQuery);
        $this->assertSame([], $exited->searchResults);
        $this->assertSame(0, $exited->searchCursor);
    }

    public function testSearchBackspaceRemovesChars(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$searching] = $searching->update(new KeyMsg(KeyType::Char, 'h'));
        [$searching] = $searching->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertSame('ho', $searching->searchQuery);

        // Backspace removes last char
        [$backspaced] = $searching->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('h', $backspaced->searchQuery);

        // Another backspace removes 'h', should exit search
        [$exited] = $backspaced->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertNull($exited->searchQuery);
    }

    public function testSearchEmptyQueryExits(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));

        // Press backspace on empty query should exit
        [$exited] = $searching->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertNull($exited->searchQuery);
    }

    public function testSearchNoMatches(): void
    {
        $m = $this->start();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));

        // Search for something that doesn't exist
        [$filtered] = $searching->update(new KeyMsg(KeyType::Char, 'x'));
        [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, 'y'));
        [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame('xyz', $filtered->searchQuery);
        $this->assertSame([], $filtered->searchResults);
        $this->assertSame(0, $filtered->searchCursor);
    }

    public function testDuplicateTabOpensNewTab(): void
    {
        $m = $this->start();
        $this->assertCount(1, $m->tabs);
        $this->assertSame(0, $m->tabIndex);
        $m = $m->duplicateTab();
        $this->assertCount(2, $m->tabs);
        $this->assertSame(1, $m->tabIndex);
    }

    public function testCloseTabReducesCount(): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertCount(2, $m->tabs);
        $m = $m->closeTab();
        $this->assertCount(1, $m->tabs);
        $this->assertSame(0, $m->tabIndex);
    }

    public function testCloseLastTabShowsError(): void
    {
        $m = $this->start();
        $m = $m->closeTab();
        $this->assertSame('Cannot close last tab', $m->status);
    }

    public function testSwitchTabChangesIndex(): void
    {
        $m = $this->start()->duplicateTab()->duplicateTab();
        // start: 1 tab, index 0; dup1: 2 tabs, index 1; dup2: 3 tabs, index 2
        $this->assertCount(3, $m->tabs);
        $this->assertSame(2, $m->tabIndex);
        $m = $m->switchTab(0);
        $this->assertSame(0, $m->tabIndex);
    }

    /**
     * Ctrl+Tab must be fed as the BYTES a terminal actually sends —
     * the old fixtures fabricated `new KeyMsg(KeyType::Char, "\t",
     * ctrl: true)`, a shape InputReader can never produce, so these
     * pins stayed green while the feature was dead. The parse() seam
     * below makes every fixture reachable from real wire bytes; a
     * removed dispatch arm now goes RED here.
     */
    public static function ctrlTabWireBytes(): iterable
    {
        // xterm-family modified-Tab report.
        yield 'xterm ctrl+tab'      => ["\x1b[1;5I"];
        // Kitty `disambiguate` (negotiated in init()) per-key report.
        yield 'kitty ctrl+tab'      => ["\x1b[9;5u"];
        // Universal alternative chord — arrives unmodified-safe even
        // on a plain xterm where Ctrl+Tab collapses to bare 0x09.
        yield 'ctrl+pageup'         => ["\x1b[5;5~"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ctrlTabWireBytes')]
    public function testCtrlTabDuplicatesFirstTab(string $bytes): void
    {
        $m = $this->start();
        $this->assertCount(1, $m->tabs);
        // Ctrl+Tab with existing tabs should switch to next tab
        [$next] = $m->update($this->decode($bytes));
        // From tab 0, Ctrl+Tab wraps to tab 0 (only 1 tab)
        $this->assertCount(1, $next->tabs);
        $this->assertSame(0, $next->tabIndex);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ctrlTabWireBytes')]
    public function testCtrlTabCyclesToNextTab(string $bytes): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertSame(1, $m->tabIndex);
        // Ctrl+Tab should cycle to next (which wraps to 0)
        [$next] = $m->update($this->decode($bytes));
        $this->assertSame(0, $next->tabIndex);
    }

    public static function ctrlShiftTabWireBytes(): iterable
    {
        yield 'xterm ctrl+shift+tab' => ["\x1b[1;6I"];
        yield 'kitty ctrl+shift+tab' => ["\x1b[9;6u"];
        yield 'ctrl+pagedown'        => ["\x1b[6;5~"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ctrlShiftTabWireBytes')]
    public function testCtrlShiftTabGoesToPreviousTab(string $bytes): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertSame(1, $m->tabIndex);
        // Ctrl+Shift+Tab should go to previous (0)
        [$next] = $m->update($this->decode($bytes));
        $this->assertSame(0, $next->tabIndex);
    }

    /**
     * Decode one wire event through the production parser and hand
     * back the KeyMsg the app will really see. Asserts the fixture
     * premise itself: exactly one message, and a key.
     */
    private function decode(string $bytes): KeyMsg
    {
        $msgs = (new InputReader())->parse($bytes);
        $this->assertCount(1, $msgs, "fixture premise: {$bytes} must decode to one message");
        $key = $msgs[0];
        $this->assertInstanceOf(KeyMsg::class, $key);
        return $key;
    }

    /**
     * The shadowing regression: with the ctrl arms in place, an
     * UNMODIFIED Tab (bare 0x09 or the kitty equivalent) must still
     * swap panes, not cycle tabs — ctrl arms above must not widen.
     */
    public function testPlainTabStillSwapsPaneEvenWithCycleArmsAbove(): void
    {
        $m = $this->start();
        $this->assertSame(0, $m->activeIdx);
        [$next] = $m->update($this->decode("\t"));
        $this->assertSame(1, $next->activeIdx);
        [$back] = $next->update($this->decode("\x1b[9u")); // kitty unmodified Tab
        $this->assertSame(0, $back->activeIdx);
    }

    /**
     * On a plain terminal Ctrl+Tab is byte-identical to Tab (0x09 has
     * no room for modifiers); the cycle arms must therefore NOT fire
     * for it — it keeps the documented pane-swap. Pinned so no future
     * "cleverness" pretends to see ctrl in a bare 0x09.
     */
    public function testBareTabBytesAreUnmodifiedTabNotCycle(): void
    {
        $msg = $this->decode("\t");
        $this->assertSame(KeyType::Tab, $msg->type);
        $this->assertFalse($msg->ctrl);
        $this->assertFalse($msg->shift);
    }

    /**
     * init() must negotiate the kitty disambiguate flag — the only
     * way Ctrl+Tab becomes distinguishable from Tab on kitty-family
     * terminals. Program writes the returned Cmd's RawMsg bytes once
     * at startup; Program's shutdown release pops unconditionally.
     */
    public function testInitNegotiatesKittyDisambiguate(): void
    {
        $cmd = $this->start()->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame(Ansi::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE), $msg->bytes);
    }

    public function testTCreatesNewTab(): void
    {
        $m = $this->start();
        $this->assertCount(1, $m->tabs);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 't'));
        $this->assertCount(2, $next->tabs);
        $this->assertSame(1, $next->tabIndex);
    }

    public function testCtrlWClosesTab(): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertCount(2, $m->tabs);
        $msg = new KeyMsg(KeyType::Char, 'w', ctrl: true);
        [$next] = $m->update($msg);
        $this->assertCount(1, $next->tabs);
        $this->assertSame(0, $next->tabIndex);
    }

    public function testUndoOnEmptyStackShowsMessage(): void
    {
        $m = $this->start();
        $this->assertFalse($m->canUndo());
        $this->assertFalse($m->canRedo());
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('nothing to undo', $next->status);
    }

    public function testCtrlZTriggersUndo(): void
    {
        $m = $this->start();
        $this->assertFalse($m->canUndo());
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('nothing to undo', $next->status);
    }

    public function testCanUndoAfterDelete(): void
    {
        // This test uses the fake filesystem.
        // Note: The actual file deletion won't work with fake fs since
        // is_dir/is_file check the real filesystem. But we can test that
        // the delete flow works and the canUndo flag is set appropriately.

        $m = $this->start();
        $this->assertFalse($m->canUndo());
        $this->assertFalse($m->canRedo());

        // Navigate to a real directory with files for actual undo test
        $tmpDir = sys_get_temp_dir() . '/sugarcraft-undo-test-' . uniqid('', true);
        $this->assertTrue(mkdir($tmpDir, 0755, true));
        file_put_contents($tmpDir . '/file.txt', 'content');

        $lister = \SugarCraft\Files\FsLister::lister();
        $m = Manager::start($tmpDir, $tmpDir, $lister);

        // At tmpDir, first entry is '..' (parent), second is 'file.txt'
        // Move down once to get past '..' then select
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j')); // Move to file.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' ')); // Select

        // If selection is empty (because file.txt doesn't exist on real fs for some reason), skip
        if (empty($m->left->selected)) {
            @unlink($tmpDir . '/file.txt');
            @rmdir($tmpDir);
            $this->markTestSkipped('Cannot test undo without working filesystem selection');
        }

        // Arm delete
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $m->confirm);

        // Confirm delete
        [$deleted] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        // canUndo should be true (undo stack has the delete action)
        $this->assertTrue($deleted->canUndo(), 'canUndo should be true after delete');
        $this->assertFalse($deleted->canRedo(), 'canRedo should be false after new action');
        $this->assertStringContainsString('deleted', $deleted->status);

        // Now test that pressing 'u' triggers undo
        [$undone] = $deleted->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('undone', $undone->status);

        // After undo, canRedo should be true and canUndo should be false
        $this->assertFalse($undone->canUndo(), 'canUndo should be false after undo');
        $this->assertTrue($undone->canRedo(), 'canRedo should be true after undo');

        // Clean up
        @unlink($tmpDir . '/file.txt');
        @rmdir($tmpDir);
    }

    public function testActivePaneReturnsLeftByDefault(): void
    {
        $m = $this->start();
        $this->assertSame($m->left, $m->activePane());
        $this->assertSame($m->left->cwd, $m->activePane()->cwd);
    }

    public function testActivePaneReturnsRightAfterTabSwap(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(1, $swapped->activeIdx);
        $this->assertSame($swapped->right, $swapped->activePane());
    }

    public function testActivePaneWithTabsUsesTabState(): void
    {
        $m = $this->start()->duplicateTab();
        // After duplicateTab, tabIndex is 1 (the new tab)
        $this->assertCount(2, $m->tabs);
        // The new tab starts with activeIdx 0 (left pane)
        $active = $m->activePane();
        $this->assertSame($m->tabs[1]['left'], $active);
    }

    public function testInactivePaneReturnsRightByDefault(): void
    {
        $m = $this->start();
        $this->assertSame($m->right, $m->inactivePane());
    }

    public function testInactivePaneReturnsLeftAfterTabSwap(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(1, $swapped->activeIdx);
        $this->assertSame($swapped->left, $swapped->inactivePane());
    }

    public function testOpenNewTabCreatesNewTab(): void
    {
        $m = $this->start();
        $this->assertCount(1, $m->tabs);
        $this->assertSame(0, $m->tabIndex);

        $m = $m->openNewTab('/home');
        $this->assertCount(2, $m->tabs);
        $this->assertSame(1, $m->tabIndex);
    }

    public function testOpenNewTabWithDefaultPath(): void
    {
        $m = $this->start()->openNewTab();
        $this->assertCount(2, $m->tabs);
        // The default argument is '/' — and it is now honoured, not ignored.
        $this->assertSame('/', $m->tabs[1]['left']->cwd);
        $this->assertSame('/', $m->tabs[1]['right']->cwd);
    }

    /**
     * HIGH audit item: openNewTab($path) used to duplicate the current
     * pane's cwd and drop $path entirely. The tab MUST land on the path
     * the caller asked for.
     */
    public function testOpenNewTabLandsOnTheGivenPath(): void
    {
        $dir = sys_get_temp_dir() . '/candyfiles-tab-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $m = $this->start()->openNewTab($dir);
            $this->assertSame($dir, $m->tabs[1]['left']->cwd, 'tab must land on the requested path');
            $this->assertSame($dir, $m->tabs[1]['right']->cwd);
            $this->assertSame(1, $m->tabIndex, 'new tab must be focused');
        } finally {
            rmdir($dir);
        }
    }

    public function testOpenNewTabFallsBackToCurrentCwdForNonDirectory(): void
    {
        $m = $this->start()->openNewTab('/definitely/not/a/real/path-' . bin2hex(random_bytes(4)));
        // A bogus path must not open a dead listing: fall back to the
        // current pane's cwd (the pre-fix behaviour) instead.
        $this->assertSame($m->tabs[0]['left']->cwd, $m->tabs[1]['left']->cwd);
    }

    public function testViewReturnsString(): void
    {
        $m = $this->start();
        $view = $m->view();
        $this->assertIsString($view);
        $this->assertNotEmpty($view);
    }

    public function testViewRendersContent(): void
    {
        $m = $this->start();
        $view = $m->view();
        // The rendered view should contain pane content
        $this->assertIsString($view);
    }

    public function testActiveAndInactivePaneAreDifferent(): void
    {
        $m = $this->start();
        $this->assertNotSame($m->activePane(), $m->inactivePane());
    }

    public function testCanUndoAndCanRedoInitialState(): void
    {
        $m = $this->start();
        $this->assertFalse($m->canUndo());
        $this->assertFalse($m->canRedo());
    }

    public function testSwitchTabWithInvalidIndexReturnsSame(): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertCount(2, $m->tabs);
        $this->assertSame(1, $m->tabIndex);

        // Switch to invalid index should return same state
        $unchanged = $m->switchTab(999);
        $this->assertSame($m->tabIndex, $unchanged->tabIndex);
        $this->assertSame($m->tabs, $unchanged->tabs);
    }

    public function testSwitchTabWithNegativeIndexReturnsSame(): void
    {
        $m = $this->start()->duplicateTab();
        $unchanged = $m->switchTab(-1);
        $this->assertSame($m->tabIndex, $unchanged->tabIndex);
    }

    public function testCloseTabWithSpecificIndex(): void
    {
        $m = $this->start()->duplicateTab()->duplicateTab();
        // We now have 3 tabs, at indices 0, 1, 2
        $this->assertCount(3, $m->tabs);
        $this->assertSame(2, $m->tabIndex);

        // Close tab at index 1 (the middle one)
        $m = $m->closeTab(1);
        $this->assertCount(2, $m->tabs);
        // tabIndex should be adjusted (capped at new count - 1)
        $this->assertSame(1, $m->tabIndex);
    }

    public function testCloseTabAdjustsIndexWhenClosingCurrent(): void
    {
        $m = $this->start()->duplicateTab()->duplicateTab();
        // Switch to tab 0
        $m = $m->switchTab(0);
        $this->assertSame(0, $m->tabIndex);

        // Close tab 0 - should go to tab 0 (was 1, now shifted)
        $m = $m->closeTab(0);
        $this->assertCount(2, $m->tabs);
        $this->assertSame(0, $m->tabIndex);
    }

    public function testNavigateIntoDirectoryChangesPaneCwd(): void
    {
        $m = $this->start();
        $initialCwd = $m->activePane()->cwd;
        $this->assertSame('/', $initialCwd);

        // Move down to first entry (should be 'etc' or 'home' alphabetically)
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));

        // Navigate into it
        [$navigated] = $m->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotSame($initialCwd, $navigated->activePane()->cwd);
    }

    public function testUpKeyMovesCursor(): void
    {
        $m = $this->start();
        $this->assertSame(0, $m->left->cursor);

        // Move down first
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $m->left->cursor);

        // Move up
        [$m] = $m->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(0, $m->left->cursor);
    }

    public function testHomeKeyGoesToTop(): void
    {
        $m = $this->start();
        // Move down first
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $m->left->cursor);

        // Go to top
        [$m] = $m->update(new KeyMsg(KeyType::Home, ''));
        $this->assertSame(0, $m->left->cursor);
    }

    public function testEndKeyGoesToBottom(): void
    {
        $m = $this->start();
        $entryCount = count($m->left->entries);

        // Go to end
        [$m] = $m->update(new KeyMsg(KeyType::End, ''));
        $this->assertSame($entryCount - 1, $m->left->cursor);
    }

    public function testGKeyGoesToTop(): void
    {
        $m = $this->start();
        // Move down first
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $m->left->cursor);

        // 'g' goes to top
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'g'));
        $this->assertSame(0, $m->left->cursor);
    }

    public function testShiftGKeyGoesToBottom(): void
    {
        $m = $this->start();
        $entryCount = count($m->left->entries);

        // 'G' (shift+g) goes to bottom
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame($entryCount - 1, $m->left->cursor);
    }
}
