<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy\Tests;

use CandyCore\SuperCandy\Entry;
use CandyCore\SuperCandy\Pane;
use CandyCore\SuperCandy\Sort;
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
}
