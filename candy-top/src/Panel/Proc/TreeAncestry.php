<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\State\TreeState;

/**
 * Process-name ancestry keys for tree-state persistence (btop PR #1791
 * tree_state_key): the names from the topmost listed ancestor down to the
 * process, joined by {@see TreeState::SEPARATOR}. A parent that is not in
 * the list ends the chain (it is a root, as in {@see ProcTree}); a ppid
 * loop ends where it repeats.
 */
final class TreeAncestry
{
    private function __construct()
    {
    }

    /**
     * Keys for `$pids` (all entries when null), memoised along shared
     * chains; a pid whose path cannot be stored ({@see TreeState::key()})
     * is left out.
     *
     * @param list<ProcEntry> $entries
     * @param ?list<int> $pids
     * @return array<int, string> pid => key
     */
    public static function keys(array $entries, ?array $pids = null): array
    {
        $byPid = [];
        foreach ($entries as $e) {
            $byPid[$e->pid()] = $e;
        }
        $memo = [];
        $out = [];
        foreach ($pids ?? array_keys($byPid) as $pid) {
            if (!isset($byPid[$pid])) {
                continue;
            }
            $names = self::path($byPid, $pid, $memo);
            $key = $names === null ? null : TreeState::key($names);
            if ($key !== null) {
                $out[$pid] = $key;
            }
        }

        return $out;
    }

    /**
     * @param array<int, ProcEntry> $byPid
     * @param array<int, ?list<string>> $memo
     * @return ?list<string> root-first names, null when deeper than {@see TreeState::MAX_DEPTH}
     */
    private static function path(array $byPid, int $pid, array &$memo): ?array
    {
        if (array_key_exists($pid, $memo)) {
            return $memo[$pid];
        }
        // Walk up iteratively (no recursion limit on a deep chain), then fill the memo top-down.
        $chain = [];
        $seen = [];
        $cur = $pid;
        $base = [];
        while (isset($byPid[$cur]) && !isset($seen[$cur])) {
            if (array_key_exists($cur, $memo)) {
                $base = $memo[$cur];
                break;
            }
            $seen[$cur] = true;
            $chain[] = $cur;
            $cur = $byPid[$cur]->process->ppid;
        }
        // In a ppid loop each member's chain starts somewhere else, so
        // nothing is memoised there; a clean chain fills every member.
        $loop = isset($byPid[$cur]) && isset($seen[$cur]);
        $names = $base;
        foreach (array_reverse($chain) as $p) {
            $names = $names === null || count($names) >= TreeState::MAX_DEPTH
                ? null
                : [...$names, $byPid[$p]->process->name];
            if (!$loop) {
                $memo[$p] = $names;
            }
        }

        return $names;
    }
}
