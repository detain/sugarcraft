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
 *
 * Both stacks are bounded at self::MAX_STATES entries; the oldest states
 * fall off the tail. Undo history is a convenience, not an archive: a
 * long typing session (every keystroke pushes) must not grow memory
 * without limit.
 */
final readonly class UndoManager
{
    /** Maximum states retained per stack (newest survive, oldest evicted). */
    public const int MAX_STATES = 512;
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
     * True if there are states to redo.
     */
    public function canRedo(): bool
    {
        return \count($this->redoStack) > 0;
    }

    /**
     * Push a new state onto the undo stack.
     *
     * Clears the redo stack (standard undo behavior) and evicts the oldest
     * undo states past self::MAX_STATES.
     *
     * @param string $state The state to save for potential undo.
     * @return self A new UndoManager with the state pushed.
     */
    public function push(string $state): self
    {
        return new self(
            \array_slice([$state, ...$this->undoStack], 0, self::MAX_STATES),
            [],
        );
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
            new self(
                $newUndoStack,
                \array_slice([$current, ...$this->redoStack], 0, self::MAX_STATES),
            ),
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
            new self(
                \array_slice([$current, ...$this->undoStack], 0, self::MAX_STATES),
                $newRedoStack,
            ),
            $restored,
            true,
        ];
    }
}
