<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\HistoryEntry;
use SugarCraft\Stash\HistoryManager;
use PHPUnit\Framework\TestCase;

final class HistoryManagerTest extends TestCase
{
    public function testPushAddsEntryToUndoStack(): void
    {
        $manager = new HistoryManager();
        $entry = HistoryEntry::stage('file.txt');

        $newManager = $manager->push($entry);

        $this->assertSame([$entry], $newManager->undoStack);
        $this->assertSame([], $newManager->redoStack);
    }

    public function testPushClearsRedoStack(): void
    {
        $entry1 = HistoryEntry::stage('file1.txt');
        $entry2 = HistoryEntry::stage('file2.txt');

        $manager = new HistoryManager([], [$entry1]);
        $newManager = $manager->push($entry2);

        $this->assertSame([$entry2], $newManager->undoStack);
        $this->assertSame([], $newManager->redoStack);
    }

    public function testUndoPopsEntryFromUndoStack(): void
    {
        $entry = HistoryEntry::stage('file.txt');
        $manager = (new HistoryManager())->push($entry);

        $result = $manager->undo();

        $this->assertSame($entry, $result['entry']);
        $this->assertSame([], $result['manager']->undoStack);
        $this->assertSame([$entry], $result['manager']->redoStack);
    }

    public function testUndoOnEmptyStackReturnsNullEntry(): void
    {
        $manager = new HistoryManager();

        $result = $manager->undo();

        $this->assertNull($result['entry']);
        $this->assertSame($manager, $result['manager']);
    }

    public function testUndoMultipleEntries(): void
    {
        $entry1 = HistoryEntry::stage('file1.txt');
        $entry2 = HistoryEntry::stage('file2.txt');
        $manager = (new HistoryManager())->push($entry1)->push($entry2);

        $result = $manager->undo();

        $this->assertSame($entry2, $result['entry']);
        $this->assertSame([$entry1], $result['manager']->undoStack);
        $this->assertSame([$entry2], $result['manager']->redoStack);
    }

    public function testRedoPopsEntryFromRedoStack(): void
    {
        $entry = HistoryEntry::stage('file.txt');
        $manager = (new HistoryManager())->push($entry)->undo()['manager'];

        $result = $manager->redo();

        $this->assertSame($entry, $result['entry']);
        $this->assertSame([$entry], $result['manager']->undoStack);
        $this->assertSame([], $result['manager']->redoStack);
    }

    public function testRedoOnEmptyStackReturnsNullEntry(): void
    {
        $manager = new HistoryManager();

        $result = $manager->redo();

        $this->assertNull($result['entry']);
        $this->assertSame($manager, $result['manager']);
    }

    public function testCanUndoReturnsTrueWhenStackNotEmpty(): void
    {
        $manager = (new HistoryManager())->push(HistoryEntry::stage('file.txt'));

        $this->assertTrue($manager->canUndo());
    }

    public function testCanUndoReturnsFalseWhenStackEmpty(): void
    {
        $manager = new HistoryManager();

        $this->assertFalse($manager->canUndo());
    }

    public function testCanRedoReturnsTrueWhenStackNotEmpty(): void
    {
        $manager = (new HistoryManager())
            ->push(HistoryEntry::stage('file.txt'))
            ->undo()['manager'];

        $this->assertTrue($manager->canRedo());
    }

    public function testCanRedoReturnsFalseWhenStackEmpty(): void
    {
        $manager = new HistoryManager();

        $this->assertFalse($manager->canRedo());
    }

    public function testUndoRedoCycleRestoresState(): void
    {
        $entry = HistoryEntry::stage('file.txt');
        $manager = (new HistoryManager())->push($entry);

        // Undo the operation
        $afterUndo = $manager->undo()['manager'];
        $this->assertFalse($afterUndo->canUndo());
        $this->assertTrue($afterUndo->canRedo());

        // Redo the operation
        $afterRedo = $afterUndo->redo()['manager'];
        $this->assertTrue($afterRedo->canUndo());
        $this->assertFalse($afterRedo->canRedo());
    }

    public function testPushAfterUndoRedirectsToUndoStack(): void
    {
        $entry1 = HistoryEntry::stage('file1.txt');
        $entry2 = HistoryEntry::stage('file2.txt');
        $manager = (new HistoryManager())->push($entry1)->undo()['manager'];

        $newManager = $manager->push($entry2);

        $this->assertSame([$entry2], $newManager->undoStack);
        $this->assertSame([], $newManager->redoStack);
    }

    public function testMultipleUndoRedoCycle(): void
    {
        $entry1 = HistoryEntry::stage('file1.txt');
        $entry2 = HistoryEntry::stage('file2.txt');
        $entry3 = HistoryEntry::stage('file3.txt');

        $manager = (new HistoryManager())
            ->push($entry1)
            ->push($entry2)
            ->push($entry3);

        // Undo twice
        $afterUndo1 = $manager->undo()['manager'];
        $afterUndo2 = $afterUndo1->undo()['manager'];

        $this->assertSame([$entry1], $afterUndo2->undoStack);
        $this->assertSame([$entry3, $entry2], $afterUndo2->redoStack);

        // Redo once
        $afterRedo1 = $afterUndo2->redo()['manager'];

        $this->assertSame([$entry1, $entry2], $afterRedo1->undoStack);
        $this->assertSame([$entry3], $afterRedo1->redoStack);
    }

    public function testManagerIsImmutable(): void
    {
        $manager = new HistoryManager();
        $entry = HistoryEntry::stage('file.txt');

        $newManager = $manager->push($entry);

        // Original manager unchanged
        $this->assertSame([], $manager->undoStack);
        $this->assertSame([], $manager->redoStack);

        // New manager has the entry
        $this->assertSame([$entry], $newManager->undoStack);
    }

    public function testConstructorWithInitialStacks(): void
    {
        $entry1 = HistoryEntry::stage('file1.txt');
        $entry2 = HistoryEntry::stage('file2.txt');

        $manager = new HistoryManager([$entry1], [$entry2]);

        $this->assertSame([$entry1], $manager->undoStack);
        $this->assertSame([$entry2], $manager->redoStack);
        $this->assertTrue($manager->canUndo());
        $this->assertTrue($manager->canRedo());
    }
}
