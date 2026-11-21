<?php

declare(strict_types=1);

namespace SugarCraft\Core\Undo;

/**
 * Immutable undo/redo history with a bounded depth.
 *
 * Every operation returns a new stack and leaves the receiver untouched, so a
 * model can hold the history in its state without a mutable reference leaking
 * across `update()` calls. Entries are opaque: the stack stores whatever the
 * application pushes (an {@see UndoAction}, or an app-specific record such as
 * candy-git's `HistoryEntry`), so one implementation serves every undo lane.
 *
 * The rules every copy of this logic in the monorepo shares, and which the
 * tests pin:
 *
 *   - push() clears the redo lane. A new action invalidates whatever was
 *     undone, because redoing it would now replay against a changed state.
 *   - When a lane exceeds the limit it drops its OLDEST entries. The entry the
 *     user is most likely to undo next is the newest one, so eviction bites
 *     the far end of the lane.
 *   - undo() and redo() move one entry between the two lanes and report the
 *     moved entry alongside the new stack. On an empty lane the entry is null
 *     and the stack is returned unchanged. A null is also a valid pushed
 *     entry, so a null `entry` does not by itself say whether anything moved:
 *     branch on canUndo() or canRedo() before reading it.
 *
 * The limit counts entries. Byte or weight budgets (candy-files caps the
 * file legs it remembers) stay with the application, which trims the lanes
 * it returns before pushing them back.
 *
 * Mirrors the undo/redo history of candy-git's `HistoryManager` (the
 * `MAX_HISTORY` cap and oldest-first eviction) and candy-files' `Manager`
 * undo lanes (`UNDO_LIMIT`).
 */
final class UndoStack
{
    /**
     * Default cap, matching candy-git's `HistoryManager::MAX_HISTORY`.
     */
    public const DEFAULT_LIMIT = 100;

    /**
     * @param list<mixed> $undo  Oldest first; the last element is undone next.
     * @param list<mixed> $redo  Oldest first; the last element is redone next.
     */
    private function __construct(
        public readonly array $undo,
        public readonly array $redo,
        public readonly int $limit,
    ) {
    }

    /**
     * An empty stack.
     *
     * @throws \InvalidArgumentException when $limit is below 1
     */
    public static function new(int $limit = self::DEFAULT_LIMIT): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException("UndoStack limit must be at least 1, got {$limit}");
        }

        return new self([], [], $limit);
    }

    /**
     * Record a new action. Clears the redo lane.
     */
    public function push(mixed $entry): self
    {
        return new self(self::trimmed([...$this->undo, $entry], $this->limit), [], $this->limit);
    }

    /**
     * Step back one action.
     *
     * @return array{entry: mixed, stack: self} `entry` is the action undone.
     *         It is null when the undo lane was empty (and `stack` is `$this`),
     *         and also when a null was pushed; check canUndo() first.
     */
    public function undo(): array
    {
        if ($this->undo === []) {
            return ['entry' => null, 'stack' => $this];
        }

        $entry = $this->undo[array_key_last($this->undo)];

        return [
            'entry' => $entry,
            'stack' => new self(
                array_slice($this->undo, 0, -1),
                self::trimmed([...$this->redo, $entry], $this->limit),
                $this->limit,
            ),
        ];
    }

    /**
     * Step forward one action that was previously undone.
     *
     * @return array{entry: mixed, stack: self} `entry` is the action redone.
     *         It is null when the redo lane was empty (and `stack` is `$this`),
     *         and also when a null was pushed; check canRedo() first.
     */
    public function redo(): array
    {
        if ($this->redo === []) {
            return ['entry' => null, 'stack' => $this];
        }

        $entry = $this->redo[array_key_last($this->redo)];

        return [
            'entry' => $entry,
            'stack' => new self(
                self::trimmed([...$this->undo, $entry], $this->limit),
                array_slice($this->redo, 0, -1),
                $this->limit,
            ),
        ];
    }

    public function canUndo(): bool
    {
        return $this->undo !== [];
    }

    public function canRedo(): bool
    {
        return $this->redo !== [];
    }

    /**
     * Keep a lane within $limit by dropping its oldest entries.
     *
     * @param list<mixed> $lane
     * @return list<mixed>
     */
    private static function trimmed(array $lane, int $limit): array
    {
        return count($lane) > $limit ? array_slice($lane, -$limit) : $lane;
    }
}
