<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

/**
 * btop's tree view, rebuilt from scratch for every frame.
 *
 * {@see build()} ports the tree half of Proc::collect (src/linux/
 * btop_collect.cpp) and its helpers in src/btop_shared.cpp:
 *
 *  1. a parent pid that is not in the list becomes 0, then the list is
 *     stable-sorted by parent so siblings keep the order the plain sort
 *     gave them; the roots are the children of the lowest parent pid;
 *  2. `_tree_gen` walks the tree: a process omitted by #1873's container
 *     filter or not matching the text filter is hidden — but its
 *     descendants are still visited, and the first match restarts at
 *     depth 0 and shows its whole subtree; a COLLAPSED process absorbs
 *     every descendant's cpu, cumulative cpu, memory, threads and #1552
 *     GPU use (state X excluded) and hides them; proc_aggregate sums
 *     children into parents without hiding them; #1552 proc_gpu_only
 *     hides a GPU-idle process like a non-matching text filter does;
 *  3. `tree_sort` orders each sibling group by threads / memory / cpu /
 *     io / gpu and numbers the visible rows depth-first. #1791a: with
 *     proc_aggregate off the sibling key is the BRANCH TOTAL (own value
 *     plus every non-dead descendant's), so collapsing or expanding a
 *     branch never moves it; btop sorts by the node's own value;
 *  4. `_collect_prefixes` draws the `[-]─` / `[+]─` / ` ├─` / ` └─`
 *     connectors; a hidden parent passes an empty indent to its children.
 *
 * btop's recursive `tree_sort` call passes its "collapsed" flag in the
 * `index_max` slot, so the flag never hides anything there; visibility is
 * decided by `_tree_gen`'s `filtered` marks alone, which is what this port
 * implements directly.
 *
 * Mirrors aristocratos/btop Proc::_tree_gen / tree_sort /
 * _collect_prefixes / toggle_tree_collapse / _auto_collapse_oversized.
 */
final class ProcTree
{
    private const AGG = ['cpu', 'cpuC', 'mem', 'threads', 'gpu', 'gpuMem'];

    private function __construct()
    {
    }

    /**
     * @param list<ProcEntry> $entries already sorted by {@see ProcSorter::sort()} with `$tree` on
     * @param array<int, bool> $collapsed pid => collapsed
     * @return list<ProcEntry> the visible rows in display order
     */
    public static function build(
        array $entries,
        string $sorting,
        bool $reverse,
        string $filter,
        bool $filterContainers,
        bool $aggregate,
        array $collapsed,
        bool $gpuOnly = false,
        string $ctrSelected = '',
    ): array {
        if ($entries === []) {
            return [];
        }
        [$order, $byParent, $rootParent] = self::shape($entries);

        $st = [
            'e' => $entries,
            'by' => $byParent,
            'collapsed' => $collapsed,
            'filter' => $filter,
            'ctr' => $filterContainers,
            'ctrSel' => $ctrSelected,
            'aggregate' => $aggregate,
            'gpuOnly' => $gpuOnly,
            'filtered' => [],
            'depth' => [],
            'seen' => [],
            'v' => [],
        ];
        foreach ($entries as $i => $e) {
            $st['v'][$i] = ['cpu' => $e->cpu, 'cpuC' => $e->cpuC, 'mem' => $e->mem, 'threads' => $e->threads, 'gpu' => $e->gpu, 'gpuMem' => $e->gpuMem];
        }

        $roots = [];
        foreach ($byParent[$rootParent] ?? [] as $i) {
            $roots[] = self::gen($st, $i, 0, false, false);
        }
        unset($order);

        $key = self::sortKey($sorting);
        if ($key !== null) {
            foreach ($roots as $k => $node) {
                $roots[$k] = self::withTotals($st, $node);
            }
            $roots = self::sortTree($roots, $key, $reverse);
        }

        $prefix = [];
        $last = count($roots) - 1;
        foreach ($roots as $k => $node) {
            self::prefixes($st, $prefix, $node, $k === $last, '');
        }

        $out = [];
        self::flatten($st, $prefix, $roots, $out);

        return $out;
    }

    /**
     * btop toggle_tree_collapse (the `E` key): when any non-root parent is
     * expanded collapse every non-root process, else expand them all.
     *
     * @param list<ProcEntry> $entries
     * @param array<int, bool> $collapsed
     * @return array<int, bool>
     */
    public static function toggleAll(array $entries, array $collapsed): array
    {
        $pids = [];
        $parents = [];
        foreach ($entries as $e) {
            $pids[$e->pid()] = true;
            $parents[$e->process->ppid] = true;
        }
        $collapse = false;
        foreach ($entries as $e) {
            if (isset($parents[$e->pid()]) && isset($pids[$e->process->ppid]) && !($collapsed[$e->pid()] ?? false)) {
                $collapse = true;
                break;
            }
        }
        foreach ($entries as $e) {
            if (isset($pids[$e->process->ppid])) {
                $collapsed[$e->pid()] = $collapse;
            }
        }

        return $collapsed;
    }

    /**
     * btop Proc::toggle_children (the `C` key): flip the collapsed state of
     * every direct child of `$pid`.
     *
     * @param list<ProcEntry> $entries
     * @param array<int, bool> $collapsed
     * @return array<int, bool>
     */
    public static function toggleChildren(array $entries, array $collapsed, int $pid): array
    {
        foreach ($entries as $e) {
            if ($e->process->ppid === $pid && $e->pid() !== $pid) {
                $collapsed[$e->pid()] = !($collapsed[$e->pid()] ?? false);
            }
        }

        return $collapsed;
    }

    /**
     * btop _auto_collapse_oversized (proc_tree_auto_collapse, applied when
     * the view switches INTO tree mode): collapse every process deeper
     * than the root's children that has at least `$threshold` children.
     *
     * @param list<ProcEntry> $entries
     * @param array<int, bool> $collapsed
     * @return array<int, bool>
     */
    public static function autoCollapse(array $entries, array $collapsed, int $threshold): array
    {
        if ($threshold <= 0 || $entries === []) {
            return $collapsed;
        }
        [, $byParent, $rootParent] = self::shape($entries);
        $parentOf = [];
        $rootPids = [];
        foreach ($byParent as $ppid => $children) {
            foreach ($children as $i) {
                $parentOf[$i] = $ppid;
                if ($ppid === $rootParent) {
                    $rootPids[$entries[$i]->pid()] = true;
                }
            }
        }
        foreach ($entries as $i => $e) {
            $ppid = $parentOf[$i] ?? $rootParent;
            if ($ppid === $rootParent || isset($rootPids[$ppid])) {
                continue;
            }
            if (count($byParent[$e->pid()] ?? []) >= $threshold) {
                $collapsed[$e->pid()] = true;
            }
        }

        return $collapsed;
    }

    /**
     * Step 1: fix parents, stable-sort by parent, index children.
     *
     * @param list<ProcEntry> $entries
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int}
     */
    private static function shape(array $entries): array
    {
        $pids = [];
        foreach ($entries as $e) {
            $pids[$e->pid()] = true;
        }
        $parent = [];
        foreach ($entries as $i => $e) {
            $ppid = $e->process->ppid;
            $parent[$i] = isset($pids[$ppid]) && $ppid !== $e->pid() ? $ppid : 0;
        }
        $order = array_keys($entries);
        usort($order, static fn (int $a, int $b): int => $parent[$a] <=> $parent[$b]);
        $byParent = [];
        foreach ($order as $i) {
            $byParent[$parent[$i]][] = $i;
        }

        return [$order, $byParent, $parent[$order[0]]];
    }

    /**
     * btop _tree_gen for entry `$i`.
     *
     * @param array<string, mixed> $st
     * @return array{i: int, c: list<array<string, mixed>>, t?: array<string, float|int>}
     */
    private static function gen(array &$st, int $i, int $depth, bool $collapsedCtx, bool $found): array
    {
        $st['seen'][$i] = true;
        $e = $st['e'][$i];
        $filtering = false;
        if (ProcFilter::containerHidden($e, $st['ctr'], $st['ctrSel'])) {
            $filtering = true;
            $st['filtered'][$i] = true;
        } elseif (!$found && ($st['filter'] !== '' || $st['gpuOnly'])) {
            // btop #1552 puts the gpu-only test at the head of matches_filter
            // (the process's OWN use; an empty text filter matches all).
            if (ProcFilter::gpuHidden($e, $st['gpuOnly']) || ($st['filter'] !== '' && !ProcFilter::matches($e, $st['filter']))) {
                $filtering = true;
                $st['filtered'][$i] = true;
            } else {
                $found = true;
                $depth = 0;
            }
        } else {
            $st['filtered'][$i] = false;
        }
        $st['depth'][$i] = $depth;

        $isCollapsed = $st['collapsed'][$e->pid()] ?? false;
        $children = [];
        foreach ($st['by'][$e->pid()] ?? [] as $c) {
            if (isset($st['seen'][$c])) {
                continue;
            }
            if ($collapsedCtx && !$filtering) {
                $st['filtered'][$i] = true;
            }
            $children[] = self::gen($st, $c, $depth + 1, $collapsedCtx || $isCollapsed, $found);
            $live = $st['e'][$c]->process->state !== 'X';
            if (!$filtering && ($collapsedCtx || $isCollapsed)) {
                if ($live) {
                    self::absorb($st, $i, $c);
                }
                $st['filtered'][$c] = true;
            } elseif ($st['aggregate'] && $live) {
                self::absorb($st, $i, $c);
            }
        }

        return ['i' => $i, 'c' => $children];
    }

    /** @param array<string, mixed> $st */
    private static function absorb(array &$st, int $into, int $from): void
    {
        foreach (self::AGG as $k) {
            $st['v'][$into][$k] += $st['v'][$from][$k];
        }
    }

    /**
     * #1791a branch totals, stored on each node as `t`.
     *
     * @param array<string, mixed> $st
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function withTotals(array $st, array $node): array
    {
        $i = $node['i'];
        $e = $st['e'][$i];
        $t = $st['v'][$i] + ['ioRead' => max(0.0, $e->ioRead), 'ioWrite' => max(0.0, $e->ioWrite)];
        $sumChildren = !$st['aggregate'] && !($st['collapsed'][$e->pid()] ?? false);
        foreach ($node['c'] as $k => $child) {
            $child = self::withTotals($st, $child);
            $node['c'][$k] = $child;
            if ($sumChildren && $st['e'][$child['i']]->process->state !== 'X') {
                foreach (['cpu', 'cpuC', 'mem', 'threads', 'gpu', 'gpuMem', 'ioRead', 'ioWrite'] as $f) {
                    $t[$f] += $child['t'][$f];
                }
            }
        }
        if (!$sumChildren) {
            // aggregate / collapsed: own value already holds the branch (io is never aggregated by btop).
            foreach ($node['c'] as $child) {
                $t['ioRead'] += $child['t']['ioRead'];
                $t['ioWrite'] += $child['t']['ioWrite'];
            }
        }
        $node['t'] = $t;

        return $node;
    }

    /**
     * btop tree_sort's key for `$sorting`; null when siblings keep the
     * plain sort's order (pid / name / command / user).
     *
     * @return (\Closure(array<string, float|int>): (float|int))|null
     */
    private static function sortKey(string $sorting): ?\Closure
    {
        return match ($sorting) {
            'threads' => static fn (array $t): int => (int) $t['threads'],
            'memory' => static fn (array $t): int => (int) $t['mem'],
            'cpu direct' => static fn (array $t): float => (float) $t['cpu'],
            'cpu lazy' => static fn (array $t): float => (float) $t['cpuC'],
            'io read' => static fn (array $t): float => (float) $t['ioRead'],
            'io write' => static fn (array $t): float => (float) $t['ioWrite'],
            'io total' => static fn (array $t): float => (float) ($t['ioRead'] + $t['ioWrite']),
            'gpu' => static fn (array $t): float => (float) $t['gpu'],
            'gpu memory' => static fn (array $t): int => (int) $t['gpuMem'],
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function sortTree(array $nodes, \Closure $key, bool $reverse): array
    {
        if (count($nodes) > 1) {
            usort($nodes, $reverse
                ? static fn (array $a, array $b): int => $key($a['t']) <=> $key($b['t'])
                : static fn (array $a, array $b): int => $key($b['t']) <=> $key($a['t']));
        }
        foreach ($nodes as $k => $node) {
            $nodes[$k]['c'] = self::sortTree($node['c'], $key, $reverse);
        }

        return $nodes;
    }

    /**
     * btop _collect_prefixes.
     *
     * @param array<string, mixed> $st
     * @param array<int, string> $prefix
     * @param array<string, mixed> $node
     */
    private static function prefixes(array &$st, array &$prefix, array $node, bool $isLast, string $header): void
    {
        $i = $node['i'];
        $filtered = $st['filtered'][$i] ?? false;
        if ($filtered) {
            $st['depth'][$i] = 0;
        }
        $prefix[$i] = $node['c'] !== []
            ? $header . (($st['collapsed'][$st['e'][$i]->pid()] ?? false) ? '[+]─' : '[-]─')
            : $header . ($isLast ? ' └─' : ' ├─');
        $last = count($node['c']) - 1;
        foreach ($node['c'] as $k => $child) {
            self::prefixes($st, $prefix, $child, $k === $last, $filtered ? '' : $header . ($isLast ? '   ' : ' │ '));
        }
    }

    /**
     * Depth-first visible rows (btop's final sort by tree_index).
     *
     * @param array<string, mixed> $st
     * @param array<int, string> $prefix
     * @param list<array<string, mixed>> $nodes
     * @param list<ProcEntry> $out
     */
    private static function flatten(array $st, array $prefix, array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            $i = $node['i'];
            if (!($st['filtered'][$i] ?? false)) {
                $e = $st['e'][$i];
                $v = $st['v'][$i];
                $out[] = $e->with([
                    'cpu' => (float) $v['cpu'],
                    'cpuC' => (float) $v['cpuC'],
                    'mem' => (int) $v['mem'],
                    'threads' => (int) $v['threads'],
                    'gpu' => (float) $v['gpu'],
                    'gpuMem' => (int) $v['gpuMem'],
                    'prefix' => $prefix[$i] ?? '',
                    'depth' => $st['depth'][$i] ?? 0,
                    'collapsed' => $st['collapsed'][$e->pid()] ?? false,
                ]);
            }
            self::flatten($st, $prefix, $node['c'], $out);
        }
    }
}
