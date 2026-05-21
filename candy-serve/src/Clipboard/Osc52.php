<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Clipboard;

use SugarCraft\Serve\Lang;

/**
 * OSC 52 clipboard handler for candy-serve.
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
 * @see \SugarCraft\Vt\Handler\OscHandler for the TUI-side OSC 52 parser
 */
final class Osc52
{
    /** Default clipboard selection name. */
    public const SELECTION_CLIPBOARD = 'c';

    /** Primary selection (X11). */
    public const SELECTION_PRIMARY = 'p';

    /** Secondary selection. */
    public const SELECTION_SECONDARY = 's';

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

        $this->events[] = $event;
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

        $this->events[] = $event;
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
        $this->events[] = ['kind' => 'clear', 'selection' => $selection];
        $this->notifyListeners(['kind' => 'clear', 'selection' => $selection]);
    }

    /**
     * Get all pending clipboard events.
     *
     * @return list<array{kind: string, selection: string, payload?: string}>
     */
    public function pendingEvents(): array
    {
        $events = $this->events;
        $this->events = [];
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
     * Notify all listeners of a clipboard event.
     *
     * @param array{kind: string, selection: string, payload?: string} $event
     */
    private function notifyListeners(array $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }
}
