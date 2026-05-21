<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Manages undo/redo state for an input buffer.
 *
 * Tracks a stack of previous buffer states for undo, and a parallel stack
 * of redo states. Each push() clears the redo stack (standard undo behavior).
 *
 * Stack layout: [newest, ..., oldest]
 * - undo() pops newest and pushes to redo, returns the now-new-top
 * - redo() pops redo-stack and pushes to undo, returns it
 */
final readonly class UndoManager
{
    /**
     * @param list<string> $undoStack States to restore on undo (newest first).
     * @param list<string> $redoStack States to restore on redo (newest first).
     */
    public function __construct(
        private array $undoStack = [],
        private array $redoStack = [],
    ) {}

    /**
     * True if there are states to undo.
     */
    public function canUndo(): bool
    {
        return \count($this->undoStack) > 0;
    }

    /**
     * True if there are redo to redo.
     */
    public function canRedo(): bool
    {
        return \count($this->redoStack) > 0;
    }

    /**
     * Push a new state onto the undo stack.
     *
     * Clears the redo stack (standard undo behavior).
     *
     * @param string $state The state to save for potential undo.
     * @return self A new UndoManager with the state pushed.
     */
    public function push(string $state): self
    {
        return new self([$state, ...$this->undoStack], []);
    }

    /**
     * Undo: pop from undo stack and push current to redo stack.
     *
     * @param string $current The current buffer state (pushed to redo before restoring).
     * @return array{0: UndoManager, 1: string, 2: bool} [newManager, restoredState, success]
     */
    public function undo(string $current): array
    {
        if (!$this->canUndo()) {
            return [$this, $current, false];
        }

        $restored = $this->undoStack[0];
        $newUndoStack = \array_slice($this->undoStack, 1);

        return [
            new self($newUndoStack, [$current, ...$this->redoStack]),
            $restored,
            true,
        ];
    }

    /**
     * Redo: pop from redo stack and push current to undo stack.
     *
     * @param string $current The current buffer state (pushed to undo before restoring).
     * @return array{0: UndoManager, 1: string, 2: bool} [newManager, restoredState, success]
     */
    public function redo(string $current): array
    {
        if (!$this->canRedo()) {
            return [$this, $current, false];
        }

        $restored = $this->redoStack[0];
        $newRedoStack = \array_slice($this->redoStack, 1);

        return [
            new self([$current, ...$this->undoStack], $newRedoStack),
            $restored,
            true,
        ];
    }
}
