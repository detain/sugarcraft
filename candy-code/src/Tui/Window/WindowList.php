<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use SugarCraft\Code\Lang;

/**
 * The ordered window tree the strip paints: session entries in tab order,
 * each carrying its child agents, plus which session currently has focus.
 *
 * Immutable like everything else in the kit — every mutation answers a new
 * list, so a frame is a pure function of one value. {@see badge()} is what
 * a shell status row shows; {@see summary()} is the plain-language line for
 * the window title and screen readers.
 */
final class WindowList
{
    /**
     * @param list<WindowEntry> $entries
     */
    private function __construct(
        private readonly array $entries = [],
        private readonly ?string $focusedKey = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /** @return list<WindowEntry> the sessions, in tab order */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** $entry appended — or, same key, replacing its entry in place. */
    public function add(WindowEntry $entry): self
    {
        $next = $this->entries;
        foreach ($next as $index => $existing) {
            if ($existing->ref->key === $entry->ref->key) {
                $next[$index] = $entry;

                return new self($next, $this->focusedKey);
            }
        }
        $next[] = $entry;

        return new self($next, $this->focusedKey);
    }

    /** The session keyed $key taken off; focus falls back to nothing. */
    public function remove(string $key): self
    {
        $next = [];
        foreach ($this->entries as $entry) {
            if ($entry->ref->key !== $key) {
                $next[] = $entry;
            }
        }

        return new self($next, $this->focusedKey === $key ? null : $this->focusedKey);
    }

    /** The session keyed $key replaced by $entry (an unknown key is a no-op). */
    public function update(string $key, WindowEntry $entry): self
    {
        $next = [];
        $found = false;
        foreach ($this->entries as $existing) {
            if ($existing->ref->key === $key) {
                $next[] = $entry;
                $found = true;
            } else {
                $next[] = $existing;
            }
        }

        return $found ? new self($next, $this->focusedKey) : $this;
    }

    /** Focus the session keyed $key (null clears; an unknown key is a no-op). */
    public function focus(?string $key): self
    {
        if ($key !== null && $this->find($key) === null) {
            return $this;
        }

        return new self($this->entries, $key);
    }

    public function focusedKey(): ?string
    {
        return $this->focusedKey;
    }

    /** The focused session entry, or null. */
    public function focused(): ?WindowEntry
    {
        return $this->focusedKey === null ? null : $this->find($this->focusedKey);
    }

    /** The session entry keyed $key, or null; agent keys are not found here. */
    public function find(string $key): ?WindowEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->ref->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** The session carrying the agent keyed $runId, or null. */
    public function parentOf(string $runId): ?WindowEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->child($runId) !== null) {
                return $entry;
            }
        }

        return null;
    }

    /** The total badge across all sessions. */
    public function badge(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->badge;
        }

        return $total;
    }

    /** How many sessions currently want the user. */
    public function attentionCount(): int
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if ($entry->attention->needsAttention()) {
                $count++;
            }
        }

        return $count;
    }

    /** The one-line plain-language account: windows open, windows waiting. */
    public function summary(): string
    {
        if ($this->entries === []) {
            return Lang::t('tui.window.summary.none');
        }

        return Lang::t('tui.window.summary', [
            'sessions' => $this->count(),
            'attention' => $this->attentionCount(),
        ]);
    }
}
