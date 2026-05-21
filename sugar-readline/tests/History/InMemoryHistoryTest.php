<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;

final class InMemoryHistoryTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $h = new InMemoryHistory();
        $this->assertNull($h->getPrevious());
        $this->assertNull($h->getNext());
    }

    public function testPushAddsEntry(): void
    {
        $h = new InMemoryHistory();
        $h->push('hello');
        $this->assertSame('hello', $h->getPrevious());
    }

    public function testPushIgnoresEmptyString(): void
    {
        $h = new InMemoryHistory();
        $h->push('');
        $this->assertNull($h->getPrevious());
    }

    public function testPushIgnoresDuplicateOfLastEntry(): void
    {
        $h = new InMemoryHistory();
        $h->push('hello');
        $h->push('world');
        $h->push('hello'); // duplicate of first — ignored
        $h->push('world'); // duplicate of last — ignored
        // Should still only have ['hello', 'world']
        $this->assertSame('world', $h->getPrevious()); // position=1
        $this->assertSame('hello', $h->getPrevious()); // position=0
    }

    public function testGetPreviousWalksBackward(): void
    {
        $h = new InMemoryHistory();
        $h->push('first');
        $h->push('second');
        $h->push('third');

        $this->assertSame('third', $h->getPrevious()); // position=0
        $this->assertSame('second', $h->getPrevious()); // position=1
        $this->assertSame('first', $h->getPrevious()); // position=2
        $this->assertNull($h->getPrevious()); // exhausted
    }

    public function testGetNextWalksForward(): void
    {
        $h = new InMemoryHistory();
        $h->push('first');
        $h->push('second');

        // Walk to oldest.
        $h->getPrevious(); // third=position=0
        $h->getPrevious(); // second=position=1

        // Walk back.
        $this->assertSame('second', $h->getNext()); // position=0
        $this->assertNull($h->getNext()); // back to live buffer
    }

    public function testGetNextAtLiveBufferReturnsNull(): void
    {
        $h = new InMemoryHistory();
        $h->push('only');
        $this->assertNull($h->getNext()); // position=-1 (live buffer)
    }

    public function testResetClearsNavigationPosition(): void
    {
        $h = new InMemoryHistory();
        $h->push('a');
        $h->push('b');
        $h->getPrevious(); // position=0

        $h->reset();

        // After reset, getPrevious should go to most recent again.
        $this->assertSame('b', $h->getPrevious());
    }

    public function testClearRemovesAllEntries(): void
    {
        $h = new InMemoryHistory();
        $h->push('first');
        $h->push('second');
        $h->clear();

        $this->assertNull($h->getPrevious());
        $this->assertNull($h->getNext());
    }

    public function testPushAfterNavigationResetsPosition(): void
    {
        $h = new InMemoryHistory();
        $h->push('a');
        $h->push('b');
        $h->getPrevious(); // position=0

        $h->push('c'); // non-empty, non-duplicate — resets position to -1

        // getPrevious should now start from the beginning.
        $this->assertSame('c', $h->getPrevious()); // c is most recent
    }

    public function testEmptyPushDoesNotResetPosition(): void
    {
        $h = new InMemoryHistory();
        $h->push('a');
        $h->push('b');
        $h->getPrevious(); // position=0

        $h->push(''); // empty — should NOT reset position

        $this->assertSame('a', $h->getPrevious()); // still at same place
    }

    public function testNavigationPastOldestReturnsNull(): void
    {
        $h = new InMemoryHistory();
        $h->push('only');

        $h->getPrevious(); // position=0, returns 'only'
        $result = $h->getPrevious(); // beyond oldest, returns null, position stays at max

        $this->assertNull($result);
        // Next getNext should return null (live buffer)
        $this->assertNull($h->getNext());
    }
}
