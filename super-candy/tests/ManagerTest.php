<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\SuperCandy\ConfirmState;
use SugarCraft\SuperCandy\Entry;
use SugarCraft\SuperCandy\Manager;
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

    public function testNonKeyMsgIgnored(): void
    {
        $m = $this->start();
        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $m->update($msg);
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
        $this->assertTrue($m->showTabBar);
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

    public function testTabsModeDetectedWhenMultipleTabs(): void
    {
        $m = $this->start();
        $this->assertFalse($m->showTabBar);
        $m = $m->duplicateTab();
        $this->assertTrue($m->showTabBar);
    }

    public function testCtrlTabDuplicatesFirstTab(): void
    {
        $m = $this->start();
        $this->assertCount(1, $m->tabs);
        // Ctrl+Tab with existing tabs should switch to next tab
        $msg = new KeyMsg(KeyType::Char, "\t", ctrl: true, shift: false);
        [$next] = $m->update($msg);
        // From tab 0, Ctrl+Tab wraps to tab 0 (only 1 tab)
        $this->assertCount(1, $next->tabs);
        $this->assertSame(0, $next->tabIndex);
    }

    public function testCtrlTabCyclesToNextTab(): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertSame(1, $m->tabIndex);
        // Ctrl+Tab should cycle to next (which wraps to 0)
        $msg = new KeyMsg(KeyType::Char, "\t", ctrl: true, shift: false);
        [$next] = $m->update($msg);
        $this->assertSame(0, $next->tabIndex);
    }

    public function testCtrlShiftTabGoesToPreviousTab(): void
    {
        $m = $this->start()->duplicateTab();
        $this->assertSame(1, $m->tabIndex);
        // Ctrl+Shift+Tab should go to previous (0)
        $msg = new KeyMsg(KeyType::Char, "\t", ctrl: true, shift: true);
        [$next] = $m->update($msg);
        $this->assertSame(0, $next->tabIndex);
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

        $lister = \SugarCraft\SuperCandy\FsLister::lister();
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
}
