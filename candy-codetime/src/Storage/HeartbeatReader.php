<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Storage;

use SugarCraft\CodeTime\Heartbeat;

/**
 * The read contract the dashboard and the export/gaps report paths need.
 *
 * Both shipped storages satisfy it: the JSONL {@see \SugarCraft\CodeTime\Store}
 * and the SQLite {@see SqliteBackend}. That is what lets `dashboard` honor
 * `--backend=` exactly like `push`, `export` and `gaps` do — one option, one
 * meaning, no silently-empty TUI over sqlite data.
 */
interface HeartbeatReader
{
    /**
     * Load every heartbeat in [$from, $to] — date range inclusive.
     *
     * @return list<Heartbeat>
     */
    public function loadRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Drop any cached reads so the next load sees what is on disk now.
     *
     * The SQLite backend keeps no cache (its reads are always live), so it
     * implements this as the honest no-op the interface demands.
     */
    public function invalidate(): void;
}
