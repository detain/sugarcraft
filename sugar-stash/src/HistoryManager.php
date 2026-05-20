<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Manages undo/redo stacks for command history.
 *
 * Tracks operations and their inverses so the user can undo mistakes.
 */
final class HistoryManager
{
    /** @var list<HistoryEntry> */
    private array $undoStack = [];

    /** @var list<HistoryEntry> */
    private array $redoStack = [];

    /**
     * Push a new entry onto the history. Clears the redo stack.
     */
    public function push(HistoryEntry $entry): void
    {
        $this->undoStack[] = $entry;
        $this->redoStack = [];
    }

    /**
     * Pop and return the most recent entry for undo, or null if empty.
     * The popped entry is moved to the redo stack.
     */
    public function undo(): ?HistoryEntry
    {
        if ($this->undoStack === []) {
            return null;
        }
        $entry = array_pop($this->undoStack);
        $this->redoStack[] = $entry;
        return $entry;
    }

    /**
     * Pop and return the most recent entry for redo, or null if empty.
     * The popped entry is moved back to the undo stack.
     */
    public function redo(): ?HistoryEntry
    {
        if ($this->redoStack === []) {
            return null;
        }
        $entry = array_pop($this->redoStack);
        $this->undoStack[] = $entry;
        return $entry;
    }

    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }
}
