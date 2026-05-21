<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\UndoManager;

final class UndoManagerTest extends TestCase
{
    public function testNewManagerHasNoUndoOrRedo(): void
    {
        $um = new UndoManager();
        $this->assertFalse($um->canUndo());
        $this->assertFalse($um->canRedo());
    }

    public function testPushAddsToUndoStack(): void
    {
        $um = new UndoManager();
        $um = $um->push('state1');
        $this->assertTrue($um->canUndo());
        $this->assertFalse($um->canRedo());
    }

    public function testPushClearsRedoStack(): void
    {
        $um = new UndoManager();
        $um = $um->push('s1');
        $um = $um->undo('s1')[0];
        $this->assertTrue($um->canRedo());
        $um = $um->push('s2'); // Push after undo clears redo
        $this->assertFalse($um->canRedo());
    }

    public function testUndoRestoresState(): void
    {
        $um = new UndoManager();
        $um = $um->push('state1');
        [$newUm, $restored, $ok] = $um->undo('current');
        $this->assertTrue($ok);
        $this->assertSame('state1', $restored);
        $this->assertFalse($newUm->canUndo()); // Undo stack now empty
        $this->assertTrue($newUm->canRedo());  // Redo stack has 'current'
    }

    public function testUndoFailsWhenEmpty(): void
    {
        $um = new UndoManager();
        [$newUm, $restored, $ok] = $um->undo('current');
        $this->assertFalse($ok);
        $this->assertSame('current', $restored);
    }

    public function testRedoRestoresState(): void
    {
        $um = new UndoManager();
        $um = $um->push('state1');
        $um = $um->undo('current')[0];
        $this->assertTrue($um->canRedo());

        [$newUm, $restored, $ok] = $um->redo('current');
        $this->assertTrue($ok);
        $this->assertSame('current', $restored); // 'current' was pushed to undo
        $this->assertTrue($newUm->canUndo());
        $this->assertFalse($newUm->canRedo());
    }

    public function testRedoFailsWhenEmpty(): void
    {
        $um = new UndoManager();
        [$newUm, $restored, $ok] = $um->redo('current');
        $this->assertFalse($ok);
        $this->assertSame('current', $restored);
    }

    public function testMultipleUndoRedo(): void
    {
        $um = new UndoManager();
        $um = $um->push('s1');
        $um = $um->push('s2');
        $um = $um->push('s3');

        // Undo: restore s3, undoStack=['s2', 's1'], redoStack=['s3']
        [$um, $restored, $ok] = $um->undo('s3');
        $this->assertTrue($ok);
        $this->assertSame('s3', $restored);

        // Undo: restore s2, undoStack=['s1'], redoStack=['s3', 's2']
        [$um, $restored, $ok] = $um->undo('s2');
        $this->assertTrue($ok);
        $this->assertSame('s2', $restored);

        // Undo: restore s1, undoStack=[], redoStack=['s3', 's2', 's1']
        [$um, $restored, $ok] = $um->undo('s1');
        $this->assertTrue($ok);
        $this->assertSame('s1', $restored);

        // No more undo
        [$um, $restored, $ok] = $um->undo('s1');
        $this->assertFalse($ok);

        // Redo s1 (from redoStack=['s3', 's2', 's1'])
        [$um, $restored, $ok] = $um->redo('s1');
        $this->assertTrue($ok);
        $this->assertSame('s1', $restored);

        // Redo s2 (from redoStack=['s3', 's2'])
        [$um, $restored, $ok] = $um->redo('s2');
        $this->assertTrue($ok);
        $this->assertSame('s2', $restored);

        // Redo s3 (from redoStack=['s3']) — succeeds, empties redoStack
        [$um, $restored, $ok] = $um->redo('s3');
        $this->assertTrue($ok);
        $this->assertSame('s3', $restored);

        // No more redo (redoStack is now empty)
        [$um, $restored, $ok] = $um->redo('s3');
        $this->assertFalse($ok);
    }

    public function testImmutability(): void
    {
        $um1 = new UndoManager();
        $um2 = $um1->push('state1');
        $this->assertNotSame($um1, $um2);
        $this->assertFalse($um1->canUndo());
        $this->assertTrue($um2->canUndo());
    }
}
