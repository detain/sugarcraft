<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Manages undo/redo stacks for command history.
 *
 * Tracks operations and their inverses so the user can undo mistakes.
 * Immutable: all mutation methods return a NEW HistoryManager.
 *
 * @readonly
 */
final readonly class HistoryManager
{
    /**
     * @param list<HistoryEntry> $undoStack
     * @param list<HistoryEntry> $redoStack
     */
    public function __construct(
        public array $undoStack = [],
        public array $redoStack = [],
    ) {}

    /**
     * Push a new entry onto the history. Clears the redo stack.
     * Returns a NEW HistoryManager with the entry added.
     */
    public function push(HistoryEntry $entry): self
    {
        return new self(
            undoStack: [...$this->undoStack, $entry],
            redoStack: [],
        );
    }

    /**
     * Pop and return the most recent entry for undo, or null if empty.
     * The popped entry is moved to the redo stack.
     * Returns BOTH the popped entry AND the new manager.
     *
     * @return array{entry: ?HistoryEntry, manager: self}
     */
    public function undo(): array
    {
        if ($this->undoStack === []) {
            return ['entry' => null, 'manager' => $this];
        }
        $idx = array_key_last($this->undoStack);
        $entry = $this->undoStack[$idx];
        $newManager = new self(
            undoStack: array_slice($this->undoStack, 0, -1),
            redoStack: [...$this->redoStack, $entry],
        );
        return ['entry' => $entry, 'manager' => $newManager];
    }

    /**
     * Pop and return the most recent entry for redo, or null if empty.
     * The popped entry is moved back to the undo stack.
     * Returns BOTH the popped entry AND the new manager.
     *
     * @return array{entry: ?HistoryEntry, manager: self}
     */
    public function redo(): array
    {
        if ($this->redoStack === []) {
            return ['entry' => null, 'manager' => $this];
        }
        $idx = array_key_last($this->redoStack);
        $entry = $this->redoStack[$idx];
        $newManager = new self(
            undoStack: [...$this->undoStack, $entry],
            redoStack: array_slice($this->redoStack, 0, -1),
        );
        return ['entry' => $entry, 'manager' => $newManager];
    }

    /**
     * Returns true if there is at least one entry on the undo stack.
     */
    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    /**
     * Returns true if there is at least one entry on the redo stack.
     */
    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }
}
