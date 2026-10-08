<?php

declare(strict_types=1);

namespace SugarCraft\Top\State;

/**
 * The remembered proc-tree collapse choices (btop #1791c
 * `proc_tree_persist_state`): process-name ancestry path => collapsed.
 *
 * PIDs change on every restart, so — as the PR does — a choice is keyed by
 * the chain of process NAMES from the tree root down to the process
 * (`systemd → sshd → bash`); siblings sharing a name chain share a choice.
 * Both directions are kept: an explicit EXPAND matters as much as a
 * collapse, because it overrides proc_tree_auto_collapse.
 *
 * Bounded so the state file cannot grow without limit: at most
 * {@see MAX_ENTRIES} paths, least recently used dropped first (insertion
 * order = recency, oldest first); a path not used for {@see MAX_AGE}
 * seconds is dropped on load; a path deeper than {@see MAX_DEPTH} names, or
 * holding a name longer than {@see MAX_NAME} bytes, is never stored.
 *
 * `at` is the unix time the path was last set or matched a running
 * process; null means "touched this session" and is stamped by the save
 * Cmd ({@see toArray()}), so nothing here reads the clock.
 *
 * Mirrors aristocratos/btop PR #1791 remember_tree_state /
 * restore_tree_state, minus its storage (an internal config.conf key).
 */
final class TreeState
{
    public const VERSION = 1;

    public const MAX_ENTRIES = 512;

    public const MAX_DEPTH = 32;

    public const MAX_NAME = 255;

    /** 90 days. */
    public const MAX_AGE = 7776000;

    /** Joins the names of a path into one key (btop uses the same unit separator). */
    public const SEPARATOR = "\x1f";

    /**
     * @param array<string, array{collapsed: bool, at: ?int}> $entries key => choice, oldest first
     */
    private function __construct(
        private readonly array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The key for a root-first name path, or null when it cannot be stored
     * (empty, too deep, an empty or over-long name, or a name holding the
     * separator).
     *
     * @param list<string> $names
     */
    public static function key(array $names): ?string
    {
        if ($names === [] || count($names) > self::MAX_DEPTH) {
            return null;
        }
        foreach ($names as $name) {
            if ($name === '' || \strlen($name) > self::MAX_NAME || str_contains($name, self::SEPARATOR)) {
                return null;
            }
        }

        return implode(self::SEPARATOR, $names);
    }

    /**
     * Decode a state file's payload, dropping whatever does not validate:
     * a wrong version yields the empty state, a malformed entry is skipped,
     * a stale one (older than {@see MAX_AGE} at `$now`) is dropped, and only
     * the newest {@see MAX_ENTRIES} survive.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, int $now): self
    {
        if (($data['version'] ?? null) !== self::VERSION || !\is_array($data['entries'] ?? null)) {
            return self::empty();
        }
        $entries = [];
        foreach ($data['entries'] as $item) {
            if (!\is_array($item) || !\is_bool($item['collapsed'] ?? null) || !\is_int($item['at'] ?? null)) {
                continue;
            }
            $path = $item['path'] ?? null;
            if (!\is_array($path) || !array_is_list($path) || array_filter($path, 'is_string') !== $path) {
                continue;
            }
            $key = self::key($path);
            $at = $item['at'];
            if ($key === null || $at < $now - self::MAX_AGE) {
                continue;
            }
            // A repeated key: the later (newer) entry wins and takes its slot.
            unset($entries[$key]);
            $entries[$key] = ['collapsed' => $item['collapsed'], 'at' => min($at, $now)];
        }

        return new self(self::capped($entries));
    }

    /**
     * The payload to store, oldest first; untouched-this-session entries
     * keep their time, touched ones are stamped `$now`. A path that is not
     * valid UTF-8 cannot round-trip through JSON and is left out.
     *
     * @return array{version: int, entries: list<array{path: list<string>, collapsed: bool, at: int}>}
     */
    public function toArray(int $now): array
    {
        $out = [];
        foreach ($this->entries as $key => $entry) {
            $key = (string) $key;
            if (preg_match('//u', $key) !== 1) {
                continue;
            }
            $out[] = ['path' => explode(self::SEPARATOR, $key), 'collapsed' => $entry['collapsed'], 'at' => $entry['at'] ?? $now];
        }

        return ['version' => self::VERSION, 'entries' => $out];
    }

    /** The remembered choice for `$key`, or null when there is none. */
    public function lookup(string $key): ?bool
    {
        return $this->entries[$key]['collapsed'] ?? null;
    }

    /** Remember `$collapsed` for `$key` as the most recent choice. */
    public function remember(string $key, bool $collapsed): self
    {
        $entries = $this->entries;
        unset($entries[$key]);
        $entries[$key] = ['collapsed' => $collapsed, 'at' => null];

        return new self(self::capped($entries));
    }

    /**
     * Mark `$keys` (paths that matched a running process) as used now, so a
     * choice still in use never ages out. The same instance when nothing
     * changed.
     *
     * @param list<string> $keys
     */
    public function touched(array $keys): self
    {
        $entries = $this->entries;
        $changed = false;
        foreach ($keys as $key) {
            if (isset($entries[$key]) && $entries[$key]['at'] !== null) {
                $entries[$key]['at'] = null;
                $changed = true;
            }
        }

        return $changed ? new self($entries) : $this;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<string> every key, oldest first */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->entries));
    }

    /**
     * @param array<string, array{collapsed: bool, at: ?int}> $entries
     * @return array<string, array{collapsed: bool, at: ?int}>
     */
    private static function capped(array $entries): array
    {
        return count($entries) > self::MAX_ENTRIES
            ? array_slice($entries, -self::MAX_ENTRIES, null, true)
            : $entries;
    }
}
