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
 * fall off the tail. The bound guards against bursts of STRUCTURAL edits —
 * every delete, paste, completion or history recall snapshots the buffer —
 * not against typing itself: a consecutive typing run folds into ONE
 * snapshot (TextPrompt::pushUndoOn's $insertRun gate), so the "every
 * keystroke pushes" story this docblock used to tell was false to
 * behavior (finding 078).
 *
 * Internally each stack is a chain of immutable nodes (newest first) plus
 * a visible-window count. push/undo/redo move pointers on the node chain
 * in O(1) and never rebuild the whole stack, which they used to do — a
 * spread plus an array_slice, two full copies — at every edit-run boundary
 * (finding 079). A chain is consolidated down to its visible window once
 * the unreachable evicted tail grows to a full window, which frees the
 * dead nodes and bounds memory at roughly two windows per stack;
 * consolidation cannot change any observable outcome because pops and the
 * depth checks only ever look at window entries. The manager itself stays
 * immutable by convention: every operation clones and returns a new
 * instance that shares only nodes, never mutable state.
 */
final class UndoManager
{
    /** Maximum states retained per stack (newest survive, oldest evicted). */
    public const int MAX_STATES = 512;

    /** @var UndoState|null Newest undo state, or null when the window is empty. */
    private ?UndoState $undoTop = null;

    /** @var UndoState|null Newest redo state, or null when the window is empty. */
    private ?UndoState $redoTop = null;

    /** @var int Visible (capped) entries on the undo stack. */
    private int $undoCount = 0;

    /** @var int Visible (capped) entries on the redo stack. */
    private int $redoCount = 0;

    /** @var int Physical chain length below the top; >= visible count. */
    private int $undoLen = 0;
    private int $redoLen = 0;

    /**
     * @param list<string> $undoStack States to restore on undo (newest first).
     * @param list<string> $redoStack States to restore on redo (newest first).
     */
    public function __construct(array $undoStack = [], array $redoStack = [])
    {
        $this->undoTop   = self::chain($undoStack);
        $this->undoCount = \count($undoStack);
        $this->undoLen   = $this->undoCount;
        $this->redoTop   = self::chain($redoStack);
        $this->redoCount = \count($redoStack);
        $this->redoLen   = $this->redoCount;
    }

    /**
     * True if there are states to undo.
     */
    public function canUndo(): bool
    {
        return $this->undoCount > 0;
    }

    /**
     * True if there are states to redo.
     */
    public function canRedo(): bool
    {
        return $this->redoCount > 0;
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
        [$top, $len] = self::coalesce($this->undoTop, $this->undoCount, $this->undoLen);

        $clone = clone $this;
        $clone->undoTop   = new UndoState($state, $top);
        $clone->undoCount = \min($this->undoCount + 1, self::MAX_STATES);
        $clone->undoLen   = $len + 1;
        $clone->redoTop   = null;
        $clone->redoCount = 0;
        $clone->redoLen   = 0;
        return $clone;
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

        [$redoTop, $redoLen] = self::coalesce($this->redoTop, $this->redoCount, $this->redoLen);

        $clone = clone $this;
        $clone->undoTop   = $this->undoTop->older;
        $clone->undoCount = $this->undoCount - 1;
        $clone->undoLen   = $this->undoLen - 1;
        $clone->redoTop   = new UndoState($current, $redoTop);
        $clone->redoCount = \min($this->redoCount + 1, self::MAX_STATES);
        $clone->redoLen   = $redoLen + 1;

        return [$clone, $this->undoTop->state, true];
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

        [$undoTop, $undoLen] = self::coalesce($this->undoTop, $this->undoCount, $this->undoLen);

        $clone = clone $this;
        $clone->undoTop   = new UndoState($current, $undoTop);
        $clone->undoCount = \min($this->undoCount + 1, self::MAX_STATES);
        $clone->undoLen   = $undoLen + 1;
        $clone->redoTop   = $this->redoTop->older;
        $clone->redoCount = $this->redoCount - 1;
        $clone->redoLen   = $this->redoLen - 1;

        return [$clone, $this->redoTop->state, true];
    }

    /**
     * Materialize a newest-first list into a node chain (constructor path only).
     *
     * @param list<string> $stack
     */
    private static function chain(array $stack): ?UndoState
    {
        $node = null;
        foreach (\array_reverse($stack) as $state) {
            $node = new UndoState($state, $node);
        }
        return $node;
    }

    /**
     * Rebuild the node chain from its visible window once the unreachable
     * evicted tail grows to a full window (which bounds memory at roughly
     * two windows per stack), freeing the dead nodes.
     *
     * @return array{0: UndoState|null, 1: int} [top, physical length]
     */
    private static function coalesce(?UndoState $top, int $count, int $len): array
    {
        if ($len - $count < self::MAX_STATES) {
            return [$top, $len];
        }

        $states = [];
        $cursor = $top;
        for ($i = 0; $i < $count && $cursor !== null; $i++) {
            $states[]  = $cursor->state;
            $cursor    = $cursor->older;
        }

        $node = null;
        foreach (\array_reverse($states) as $state) {
            $node = new UndoState($state, $node);
        }
        return [$node, \count($states)];
    }
}
