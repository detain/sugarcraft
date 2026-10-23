<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\Entry;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Sort;
use PHPUnit\Framework\TestCase;

final class PaneTest extends TestCase
{
    /** @return \Closure(string): list<Entry> */
    private function fakeFs(array $tree): \Closure
    {
        return static function (string $path) use ($tree): array {
            return $tree[$path] ?? [];
        };
    }

    private function tree(): array
    {
        return [
            '/'         => [
                new Entry('home',    true, 0, 0),
                new Entry('etc',     true, 0, 0),
                new Entry('readme',  false, 1024, 0),
            ],
            '/home'     => [
                new Entry('alice',   true, 0, 0),
                new Entry('bob',     true, 0, 0),
                new Entry('.config', true, 0, 0, isHidden: true),
            ],
            '/home/alice' => [
                new Entry('notes.txt', false, 256, 0),
            ],
        ];
    }

    public function testOpenAddsParentSentinelExceptAtRoot(): void
    {
        $fs = $this->fakeFs($this->tree());
        $root = Pane::open('/', $fs);
        $this->assertFalse($root->entries[0]->isParentSentinel(), 'no parent sentinel at /');

        $home = Pane::open('/home', $fs);
        $this->assertTrue($home->entries[0]->isParentSentinel(), 'parent sentinel below /');
    }

    public function testHiddenEntriesFilteredByDefault(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        $names = array_map(fn(Entry $e) => $e->name, $home->entries);
        $this->assertNotContains('.config', $names);
    }

    public function testToggleHiddenIncludesDotEntries(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs)->toggleHidden($fs);
        $names = array_map(fn(Entry $e) => $e->name, $home->entries);
        $this->assertContains('.config', $names);
    }

    public function testNavigateIntoDirectory(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // Cursor 0 = "..", move to 'alice' (index 1 after sort).
        $names = array_map(fn(Entry $e) => $e->name, $home->entries);
        $aliceIdx = array_search('alice', $names, true);
        $this->assertNotFalse($aliceIdx);
        $home = $home->moveCursor($aliceIdx);
        $alice = $home->navigate($fs);
        $this->assertSame('/home/alice', $alice->cwd);
    }

    public function testNavigateOnParentSentinelGoesUp(): void
    {
        $fs = $this->fakeFs($this->tree());
        $alice = Pane::open('/home/alice', $fs);
        // Cursor 0 = "..", navigate goes to /home.
        $home = $alice->navigate($fs);
        $this->assertSame('/home', $home->cwd);
    }

    public function testNavigateOnFileIsNoop(): void
    {
        $fs = $this->fakeFs($this->tree());
        $root = Pane::open('/', $fs);
        // Move to 'readme'.
        $names = array_map(fn(Entry $e) => $e->name, $root->entries);
        $idx = array_search('readme', $names, true);
        $root = $root->moveCursor($idx);
        $next = $root->navigate($fs);
        $this->assertSame($root->cwd, $next->cwd);
        $this->assertSame($root->cursor, $next->cursor);
    }

    public function testCursorClampedToBounds(): void
    {
        $fs = $this->fakeFs($this->tree());
        $p = Pane::open('/home', $fs);
        $down = $p->moveCursor(100);
        $this->assertSame(count($p->entries) - 1, $down->cursor);
        $up = $down->moveCursor(-1000);
        $this->assertSame(0, $up->cursor);
    }

    public function testToggleSelectionAddsAndRemoves(): void
    {
        $fs = $this->fakeFs($this->tree());
        $root = Pane::open('/', $fs);
        $names = array_map(fn(Entry $e) => $e->name, $root->entries);
        $idx = array_search('readme', $names, true);
        $p = $root->moveCursor($idx)->toggleSelection();
        $this->assertSame(['readme'], $p->selectedNames());
        $p = $p->toggleSelection();
        $this->assertSame([], $p->selectedNames());
    }

    public function testToggleSelectionIgnoresParentSentinel(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // cursor 0 = ".."
        $p = $home->toggleSelection();
        $this->assertSame([], $p->selectedNames());
    }

    /**
     * docs-fix-1023: relist() is the re-read transition used by refresh and
     * '.' — it KEEPS the selection/cursor for entries that survived, while
     * Pane::open() (entering a directory) still starts clean.
     */
    public function testRelistKeepsSurvivingSelectionAndCursor(): void
    {
        $tree = $this->tree();
        $fs = $this->fakeFs($tree);
        $home = Pane::open('/home', $fs);            // ['..','alice','bob']
        $p = $home->moveCursor(1)->toggleSelection(); // cursor 'alice', alice selected
        $this->assertSame(['alice'], $p->selectedNames());

        $again = $p->relist($fs);
        $this->assertSame(['alice'], $again->selectedNames(), 're-read of the same dir must keep selection');
        $this->assertSame(1, $again->cursor, 'cursor must stay on the same entry');
    }

    public function testRelistPrunesGoneNamesAndClampsCursor(): void
    {
        $tree = $this->tree();
        $fs = $this->fakeFs($tree);
        $home = Pane::open('/home', $fs);
        $p = $home->moveCursor(1)->toggleSelection()
            ->moveCursor(1)->toggleSelection();       // alice + bob selected, cursor on bob
        $this->assertSame(['alice', 'bob'], $p->selectedNames());

        // The directory shrinks to bob only.
        $shrunk = $this->fakeFs(['/home' => [new Entry('bob', true, 0, 0)]]);
        $after = $p->relist($shrunk);
        $this->assertSame(['bob'], $after->selectedNames(), 'vanished names leave the selection');
        $this->assertSame(1, $after->cursor, 'cursor lands on bob (entry order: .., bob)');

        // Even the cursor entry gone: clamped into range, no crash.
        $empty = $after->relist($this->fakeFs(['/home' => []]));
        $this->assertSame(0, $empty->cursor);
    }

    /**
     * '.' (toggleHidden) re-reads via relist, so toggling hidden visibility
     * must not consume the selection either.
     */
    public function testToggleHiddenKeepsSelection(): void
    {
        $tree = $this->tree();
        $fs = $this->fakeFs($tree);
        $home = Pane::open('/home', $fs);
        $p = $home->moveCursor(1)->toggleSelection();
        $toggled = $p->toggleHidden($fs);
        $this->assertTrue($toggled->showHidden);
        $this->assertSame(['alice'], $toggled->selectedNames(), 'hidden toggle must preserve surviving selection');
        // With dotfiles visible, NameAsc order is ['..','.config','alice','bob'] —
        // relist follows the ENTRY, not the stale index.
        $this->assertSame(2, $toggled->cursor);
    }

    public function testSetSortRefreshesAndUpdatesOrder(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        $bySize = $home->setSort(Sort::SizeDesc, $fs);
        $this->assertSame(Sort::SizeDesc, $bySize->sort);
    }

    public function testParentPathLogic(): void
    {
        $this->assertSame('/',     Pane::parentPath('/'));
        $this->assertSame('/home', Pane::parentPath('/home/alice'));
        $this->assertSame('/',     Pane::parentPath('/home'));
    }

    public function testJoinPathLogic(): void
    {
        $this->assertSame('/foo',     Pane::join('/',     'foo'));
        $this->assertSame('/a/b/foo', Pane::join('/a/b',  'foo'));
        $this->assertSame('/a/b/foo', Pane::join('/a/b/', 'foo'));
    }

    public function testGotoTopResetsCursorToZero(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // Move cursor down first
        $moved = $home->moveCursor(2);
        $this->assertSame(2, $moved->cursor);
        // gotoTop should reset to 0
        $top = $moved->gotoTop();
        $this->assertSame(0, $top->cursor);
        // Verify it's a new instance
        $this->assertNotSame($home, $top);
    }

    public function testGotoBottomPositionsCursorOnLastEntry(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // gotoBottom should position cursor on last entry (bob at index 2 after .., alice, bob)
        $bottom = $home->gotoBottom();
        $this->assertSame(2, $bottom->cursor, 'cursor should be on last entry');
    }

    public function testCursorOnNameFindsExistingEntry(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // Cursor starts at 0 (..)
        $this->assertSame(0, $home->cursor);
        // cursorOnName should move cursor to 'alice' at index 1
        $found = $home->cursorOnName('alice');
        $this->assertSame(1, $found->cursor, 'cursor should be on alice');
    }

    public function testCursorOnNameReturnsUnchangedForMissingName(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        $originalCursor = $home->cursor;
        $notFound = $home->cursorOnName('nonexistent');
        // Should return same instance when name not found
        $this->assertSame($home, $notFound);
        $this->assertSame($originalCursor, $notFound->cursor);
    }

    public function testClearSelectionRemovesAllSelections(): void
    {
        $fs = $this->fakeFs($this->tree());
        $root = Pane::open('/', $fs);
        // Select multiple entries
        $readmeIdx = array_search('readme', array_map(fn(Entry $e) => $e->name, $root->entries), true);
        $etcIdx = array_search('etc', array_map(fn(Entry $e) => $e->name, $root->entries), true);
        $p = $root->moveCursor($readmeIdx)->toggleSelection();
        $p = $p->moveCursor($etcIdx - $readmeIdx)->toggleSelection();
        $this->assertCount(2, $p->selectedNames());
        // clearSelection should remove all
        $cleared = $p->clearSelection();
        $this->assertSame([], $cleared->selectedNames());
        // Verify it's a new instance
        $this->assertNotSame($p, $cleared);
    }

    public function testSelectedNamesReturnsSelectedEntryNames(): void
    {
        $fs = $this->fakeFs($this->tree());
        $root = Pane::open('/', $fs);
        // Select 'readme' and 'etc'
        $readmeIdx = array_search('readme', array_map(fn(Entry $e) => $e->name, $root->entries), true);
        $etcIdx = array_search('etc', array_map(fn(Entry $e) => $e->name, $root->entries), true);
        $p = $root->moveCursor($readmeIdx)->toggleSelection();
        $p = $p->moveCursor($etcIdx - $readmeIdx)->toggleSelection();
        $names = $p->selectedNames();
        $this->assertContains('readme', $names);
        $this->assertContains('etc', $names);
        $this->assertCount(2, $names);
    }

    public function testCurrentEntryReturnsEntryAtCursor(): void
    {
        $fs = $this->fakeFs($this->tree());
        $home = Pane::open('/home', $fs);
        // Cursor 0 is ".."
        $this->assertSame('..', $home->currentEntry()->name);
        // Move cursor to alice at index 1
        $moved = $home->moveCursor(1);
        $this->assertSame('alice', $moved->currentEntry()->name);
    }

    public function testCurrentEntryReturnsNullForEmptyPane(): void
    {
        $fs = $this->fakeFs([]);
        $empty = new Pane('/empty', []);
        $this->assertNull($empty->currentEntry());
    }
}
