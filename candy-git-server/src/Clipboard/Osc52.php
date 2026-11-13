<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Clipboard;

use SugarCraft\GitServer\Lang;

/**
 * OSC 52 clipboard handler for candy-git-server.
 *
 * OSC 52 (Operating System Command 52) is used to read/write clipboard data
 * from within a terminal. This handler bridges the TUI clipboard events
 * to the server-side clipboard management.
 *
 * Format: OSC 52 ; selection ; payload
 * - selection: clipboard selection name (e.g., 'c' for clipboard, 'p' for primary)
 * - payload: base64-encoded data to write, or '?' to request a read
 *
 * Port of charmbracelet/soft-serve clipboard handling.
 *
 * @see https://github.com/charmbracelet/soft-serve
 * @see \SugarCraft\Vterm\Handler\OscHandler for the TUI-side OSC 52 parser
 */
final class Osc52
{
    /** Default clipboard selection name. */
    public const SELECTION_CLIPBOARD = 'c';

    /** Primary selection (X11). */
    public const SELECTION_PRIMARY = 'p';

    /** Secondary selection. */
    public const SELECTION_SECONDARY = 's';

    /**
     * Secondary cap on buffered event COUNT (104). It is no longer the
     * memory story — the byte budget below is — but it keeps the small-
     * payload churn honest without walking anything.
     */
    private const MAX_EVENTS = 1000;

    /**
     * 104: the memory budget is BYTES, not a count. OSC 52 payloads are
     * attacker-chosen length, so "1000 events" had been unbounded memory
     * dressed as a cap — the stated prohibition against unbounded growth
     * needed the unit the payload actually consumes.
     */
    private const MAX_PENDING_BYTES = 262_144;

    /** Valid selection names. */
    private const VALID_SELECTIONS = [
        self::SELECTION_CLIPBOARD,
        self::SELECTION_PRIMARY,
        self::SELECTION_SECONDARY,
    ];

    /** @var array<string, string> selection => clipboard content */
    private array $clipboards = [];

    /** @var list<array{kind: string, selection: string, payload?: string}> Pending events */
    private array $events = [];

    /**
     * 104: ring cursor — eviction unsets the head slot and advances this
     * index (O(1) per drop) instead of array_slice-ing the whole list on
     * every overflow; pendingEvents() compacts once at drain.
     */
    private int $eventHead = 0;

    /** @var int buffered payload bytes, kept at or below MAX_PENDING_BYTES. */
    private int $eventBytes = 0;

    /** @var list<callable> Listeners for clipboard changes */
    private array $listeners = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Parse and apply an OSC 52 payload.
     *
     * @param string $data  The OSC 52 payload (everything after "52;")
     * @return array{kind: string, selection: string, payload?: string}|null  The parsed event, or null if malformed
     */
    public function parse(string $data): ?array
    {
        $semi = \strpos($data, ';');
        if ($semi === false) {
            return null;
        }

        $selection = \substr($data, 0, $semi);
        $payload = \substr($data, $semi + 1);

        if ($selection === '' || !\in_array($selection, self::VALID_SELECTIONS, true)) {
            return null;
        }

        if ($payload === '?') {
            $event = ['kind' => 'read', 'selection' => $selection];
        } else {
            $event = ['kind' => 'write', 'selection' => $selection, 'payload' => $payload];
        }

        $this->recordEvent($event);
        return $event;
    }

    /**
     * Write clipboard data (decodes base64 and stores).
     *
     * @param string $selection  The selection name
     * @param string $data       Raw (not base64) data to store
     */
    public function write(string $selection, string $data): void
    {
        if (!\in_array($selection, self::VALID_SELECTIONS, true)) {
            return;
        }

        $this->clipboards[$selection] = $data;
        $event = ['kind' => 'write', 'selection' => $selection, 'payload' => $data];

        $this->recordEvent($event);
        $this->notifyListeners($event);
    }

    /**
     * Read clipboard data for a selection.
     *
     * @param string $selection  The selection name
     * @return string|null  The clipboard content, or null if empty
     */
    public function read(string $selection): ?string
    {
        return $this->clipboards[$selection] ?? null;
    }

    /**
     * Check if a selection has content.
     */
    public function has(string $selection): bool
    {
        return isset($this->clipboards[$selection]) && $this->clipboards[$selection] !== '';
    }

    /**
     * Clear a clipboard selection.
     */
    public function clear(string $selection): void
    {
        if (!\in_array($selection, self::VALID_SELECTIONS, true)) {
            return;
        }

        unset($this->clipboards[$selection]);
        $this->recordEvent(['kind' => 'clear', 'selection' => $selection]);
        $this->notifyListeners(['kind' => 'clear', 'selection' => $selection]);
    }

    /**
     * Get all pending clipboard events.
     *
     * @return list<array{kind: string, selection: string, payload?: string}>
     */
    public function pendingEvents(): array
    {
        $events = \array_values($this->events);
        $this->events     = [];
        $this->eventHead  = 0;
        $this->eventBytes = 0;

        return $events;
    }

    /**
     * Generate an OSC 52 response string for a clipboard read.
     *
     * @param string $selection  The selection name
     * @param string $data       Raw (not base64) data
     * @return string  The complete OSC 52 response string (without ESC prefix)
     */
    public function buildReadResponse(string $selection, string $data): string
    {
        $encoded = \base64_encode($data);
        // OSC 52 ; selection ; base64data ST
        return "52;{$selection};{$encoded}";
    }

    /**
     * Register a listener for clipboard changes.
     *
     * @param callable $listener  (array $event) => void
     */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Remove a previously registered onChange listener (104 — the API
     * was append-only, so a listener outliving its session or transport
     * could never be detached). Identity match on the same callable;
     * returns whether anything was removed.
     */
    public function offChange(callable $listener): bool
    {
        $kept = [];
        foreach ($this->listeners as $candidate) {
            if ($candidate !== $listener) {
                $kept[] = $candidate;
            }
        }
        $removed = \count($kept) !== \count($this->listeners);
        $this->listeners = $kept;

        return $removed;
    }

    /**
     * Get the list of valid selection names.
     *
     * @return list<string>
     */
    public static function validSelections(): array
    {
        return self::VALID_SELECTIONS;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Buffer an event under the byte budget (104) with O(1) ring
     * eviction, the count cap as the secondary bound. A single payload
     * past the whole budget evicts ITSELF rather than displacing the
     * entire backlog — a one-event giant is exactly the shape the cap
     * exists to refuse.
     *
     * @param array{kind: string, selection: string, payload?: string} $event
     */
    private function recordEvent(array $event): void
    {
        $this->events[] = $event;
        $this->eventBytes += \strlen((string) ($event['payload'] ?? ''));

        // 104: byte budget first (payloads are attacker-chosen length),
        // count second. count() already excludes evicted slots, so the
        // live size is count() itself; eviction drops the OLDEST by
        // advancing the head cursor — no re-slice of the whole list per
        // overflow.
        while (
            \array_key_exists($this->eventHead, $this->events)
            && ($this->eventBytes > self::MAX_PENDING_BYTES || \count($this->events) > self::MAX_EVENTS)
        ) {
            $this->eventBytes -= \strlen((string) ($this->events[$this->eventHead]['payload'] ?? ''));
            unset($this->events[$this->eventHead]);
            $this->eventHead++;
        }
    }

    /**
     * Notify all listeners of a clipboard event.
     *
     * @param array{kind: string, selection: string, payload?: string} $event
     */
    private function notifyListeners(array $event): void
    {
        // PHP array-by-value iteration: this loop already runs over a
        // snapshot of the list, so a listener that calls offChange()
        // (itself or a sibling) mid-dispatch cannot skip or double-fire
        // this same notification (104 documented shape).
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }
}
