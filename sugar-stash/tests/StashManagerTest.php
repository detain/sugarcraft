<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\StashEntry;
use SugarCraft\Stash\StashManager;
use PHPUnit\Framework\TestCase;

final class StashManagerTest extends TestCase
{
    public function testFromGitOutputParsesStashLines(): void
    {
        $lines = [
            'stash@{0}: WIP on main: abc1234 WIP commit message',
            'stash@{1}: On feature: def5678 Another commit',
        ];
        $stashes = StashManager::fromGitOutput($lines);

        $this->assertCount(2, $stashes);
        $this->assertSame(0, $stashes[0]->index);
        $this->assertSame('abc1234', $stashes[0]->sha);
        $this->assertSame('WIP on main', $stashes[0]->branch);
        $this->assertSame('WIP commit message', $stashes[0]->message);
    }

    public function testStashEntryDisplayLine(): void
    {
        $entry = new StashEntry(0, 'abc1234', 'main', 'WIP commit');
        $this->assertSame('stash@{0} abc1234 WIP commit', $entry->displayLine());
    }

    public function testStashEntryStashRef(): void
    {
        $entry = new StashEntry(0, 'abc1234', 'main', 'WIP commit');
        $this->assertSame('stash@{0}', $entry->stashRef());

        $entry2 = new StashEntry(3, 'def5678', 'feature', 'Another');
        $this->assertSame('stash@{3}', $entry2->stashRef());
    }

    public function testWithCursorNavigates(): void
    {
        $stashes = [
            new StashEntry(0, 'a', 'main', 'first'),
            new StashEntry(1, 'b', 'main', 'second'),
            new StashEntry(2, 'c', 'main', 'third'),
        ];
        $sm = new StashManager($stashes, cursor: 0);
        $this->assertSame(0, $sm->cursor);

        $moved = $sm->withCursor(1);
        $this->assertSame(1, $moved->cursor);

        $moved2 = $moved->withCursor(1);
        $this->assertSame(2, $moved2->cursor);

        // Clamp at end
        $moved3 = $moved2->withCursor(1);
        $this->assertSame(2, $moved3->cursor);

        // Clamp at beginning on negative
        $moved4 = $sm->withCursor(-1);
        $this->assertSame(0, $moved4->cursor);
    }

    public function testCurrentReturnsSelectedStash(): void
    {
        $stashes = [
            new StashEntry(0, 'a', 'main', 'first'),
            new StashEntry(1, 'b', 'main', 'second'),
        ];
        $sm = new StashManager($stashes, cursor: 0);
        $this->assertSame($stashes[0], $sm->current());

        $sm2 = $sm->withCursor(1);
        $this->assertSame($stashes[1], $sm2->current());
    }

    public function testEmptyStashManagerReturnsNullCurrent(): void
    {
        $sm = new StashManager([]);
        $this->assertNull($sm->current());
        $this->assertSame(0, $sm->count());
    }
}
