<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Undo;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Undo\UndoAction;
use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Core\Undo\UndoStack;

final class UndoStackTest extends TestCase
{
    public function testNewStackIsEmptyWithDefaultLimit(): void
    {
        $stack = UndoStack::new();

        $this->assertFalse($stack->canUndo());
        $this->assertFalse($stack->canRedo());
        $this->assertSame([], $stack->undo);
        $this->assertSame([], $stack->redo);
        $this->assertSame(UndoStack::DEFAULT_LIMIT, $stack->limit);
        $this->assertSame(100, UndoStack::DEFAULT_LIMIT);
    }

    public function testNewRejectsALimitBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UndoStack::new(0);
    }

    public function testPushAppendsAndEnablesUndo(): void
    {
        $stack = UndoStack::new()->push('a')->push('b');

        $this->assertTrue($stack->canUndo());
        $this->assertFalse($stack->canRedo());
        $this->assertSame(['a', 'b'], $stack->undo);
    }

    public function testPushDoesNotMutateTheReceiver(): void
    {
        $before = UndoStack::new()->push('a');
        $after = $before->push('b');

        $this->assertSame(['a'], $before->undo);
        $this->assertSame(['a', 'b'], $after->undo);
        $this->assertNotSame($before, $after);
    }

    public function testUndoReturnsTheNewestEntryAndMovesItToRedo(): void
    {
        $stack = UndoStack::new()->push('a')->push('b');

        ['entry' => $entry, 'stack' => $next] = $stack->undo();

        $this->assertSame('b', $entry);
        $this->assertSame(['a'], $next->undo);
        $this->assertSame(['b'], $next->redo);
        $this->assertTrue($next->canRedo());
    }

    public function testUndoOnAnEmptyLaneReturnsNullAndTheSameStack(): void
    {
        $stack = UndoStack::new();

        ['entry' => $entry, 'stack' => $next] = $stack->undo();

        $this->assertNull($entry);
        $this->assertSame($stack, $next);
    }

    public function testRedoReturnsTheMostRecentlyUndoneEntry(): void
    {
        $stack = UndoStack::new()->push('a')->push('b');
        ['stack' => $undone] = $stack->undo();

        ['entry' => $entry, 'stack' => $redone] = $undone->redo();

        $this->assertSame('b', $entry);
        $this->assertSame(['a', 'b'], $redone->undo);
        $this->assertSame([], $redone->redo);
    }

    public function testRedoOnAnEmptyLaneReturnsNullAndTheSameStack(): void
    {
        $stack = UndoStack::new()->push('a');

        ['entry' => $entry, 'stack' => $next] = $stack->redo();

        $this->assertNull($entry);
        $this->assertSame($stack, $next);
    }

    public function testUndoThenRedoRoundTripsTheLanes(): void
    {
        $stack = UndoStack::new()->push('a')->push('b')->push('c');

        ['stack' => $s1] = $stack->undo();
        ['stack' => $s2] = $s1->undo();
        ['stack' => $s3] = $s2->redo();
        ['stack' => $s4] = $s3->redo();

        $this->assertSame($stack->undo, $s4->undo);
        $this->assertSame($stack->redo, $s4->redo);
    }

    public function testPushClearsTheRedoLane(): void
    {
        $stack = UndoStack::new()->push('a')->push('b');
        ['stack' => $undone] = $stack->undo();

        $this->assertTrue($undone->canRedo());

        $pushed = $undone->push('c');

        $this->assertFalse($pushed->canRedo());
        $this->assertSame(['a', 'c'], $pushed->undo);
    }

    public function testTheLimitDropsTheOldestUndoEntries(): void
    {
        $stack = UndoStack::new(3);

        foreach (['a', 'b', 'c', 'd', 'e'] as $entry) {
            $stack = $stack->push($entry);
        }

        $this->assertSame(['c', 'd', 'e'], $stack->undo);
        $this->assertSame(3, $stack->limit);
    }

    public function testRedoLaneOrdersTheMostRecentlyUndoneLast(): void
    {
        $stack = UndoStack::new(2)->push('a')->push('b');

        ['stack' => $one] = $stack->undo();
        ['stack' => $two] = $one->undo();

        // 'a' was undone last, so it sits at the end of the redo lane and is
        // the first entry a redo brings back.
        $this->assertSame(['b', 'a'], $two->redo);
        ['entry' => $first] = $two->redo();
        $this->assertSame('a', $first);
    }

    public function testRedoLaneNeverExceedsTheLimit(): void
    {
        $stack = UndoStack::new(2);
        foreach (['a', 'b', 'c', 'd', 'e'] as $entry) {
            $stack = $stack->push($entry);
        }

        ['stack' => $s] = $stack->undo();
        ['stack' => $s] = $s->undo();
        ['entry' => $none, 'stack' => $s] = $s->undo();

        $this->assertNull($none);
        $this->assertSame(['e', 'd'], $s->redo);
        $this->assertLessThanOrEqual(2, count($s->redo));
    }

    public function testEntriesAreStoredOpaquely(): void
    {
        $action = UndoAction::new(UndoActionType::Delete, ['path' => '/x'], 'Delete x');

        $stack = UndoStack::new()->push($action);
        ['entry' => $entry] = $stack->undo();

        $this->assertSame($action, $entry);
    }

    public function testANullPushedEntryMovesTheStackAndIsNotAnEmptyResult(): void
    {
        // Regression: a null entry is indistinguishable from the empty-lane
        // result by `entry` alone, so the lane move is only visible on the lanes.
        $pushed = UndoStack::new()->push(null);

        $undone = $pushed->undo();
        $this->assertNull($undone['entry']);
        $this->assertNotSame($pushed, $undone['stack']);
        $this->assertFalse($undone['stack']->canUndo());
        $this->assertTrue($undone['stack']->canRedo());

        $empty = UndoStack::new()->undo();
        $this->assertNull($empty['entry']);
        $this->assertFalse($empty['stack']->canUndo());
        $this->assertFalse($empty['stack']->canRedo());
    }

    public function testCanUndoAndCanRedoTrackTheLanes(): void
    {
        $stack = UndoStack::new()->push('a');

        $this->assertTrue($stack->canUndo());
        $this->assertFalse($stack->canRedo());

        ['stack' => $undone] = $stack->undo();

        $this->assertFalse($undone->canUndo());
        $this->assertTrue($undone->canRedo());
    }
}
