<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;

/**
 * Sampled entries + options → the rows the proc list shows — the
 * post-collection half of btop's Proc::collect (filter, sort, tree).
 *
 * Plain view: every entry is sorted ({@see ProcSorter}), then the ones
 * #1873's container filter (or the ctr box's selected container) omits, #1552's proc_gpu_only omits or the text
 * filter rejects are dropped.
 * Tree view: the same sort, then {@see ProcTree}. Pure; the panel memoises
 * the result per {@see key()}.
 *
 * Mirrors aristocratos/btop Proc::collect's "Match filter / Sort processes
 * / Generate tree view" block (src/linux/btop_collect.cpp).
 */
final class ProcTable
{
    private function __construct()
    {
    }

    /**
     * @param list<ProcEntry> $entries in the previous frame's sorted order (ties stay put)
     * @param array<int, bool> $collapsed tree-view collapse state by pid
     * @param bool $gpu current per-process GPU values exist, so proc_gpu_only applies
     *                  (false with none, or after a narrow box dropped them)
     * @return array{0: list<ProcEntry>, 1: list<ProcEntry>} [visible rows, all entries in their new sorted order]
     */
    public static function build(array $entries, Config $config, array $collapsed, bool $gpu = false): array
    {
        $sorting = $config->procSorting();
        $reverse = $config->bool('proc_reversed');
        $tree = $config->bool('proc_tree');
        $filter = $config->string('proc_filter');
        $containers = $config->bool('proc_filter_containers');
        $gpuOnly = $gpu && $config->bool('proc_gpu_only');
        $ctrSelected = $config->string(Schema::CTR_SELECTED);

        $sorted = ProcSorter::sort($entries, $sorting, $reverse, $tree);
        if ($tree) {
            return [
                ProcTree::build($sorted, $sorting, $reverse, $filter, $containers, $config->bool('proc_aggregate'), $collapsed, $gpuOnly, $ctrSelected),
                $sorted,
            ];
        }
        if ($filter === '' && !$containers && !$gpuOnly && $ctrSelected === '') {
            return [$sorted, $sorted];
        }
        $rows = [];
        foreach ($sorted as $e) {
            if (ProcFilter::containerHidden($e, $containers, $ctrSelected) || ProcFilter::gpuHidden($e, $gpuOnly) || ($filter !== '' && !ProcFilter::matches($e, $filter))) {
                continue;
            }
            $rows[] = $e;
        }

        return [$rows, $sorted];
    }

    /** Everything {@see build()} reads from the config, plus the caller's tree-state version and GPU flag. */
    public static function key(Config $config, int $treeVersion, bool $gpu = false): string
    {
        return implode("\0", [
            $config->procSorting(),
            $config->bool('proc_reversed') ? 1 : 0,
            $config->bool('proc_tree') ? 1 : 0,
            $config->string('proc_filter'),
            $config->bool('proc_filter_containers') ? 1 : 0,
            $config->bool('proc_aggregate') ? 1 : 0,
            $gpu && $config->bool('proc_gpu_only') ? 1 : 0,
            $config->string(Schema::CTR_SELECTED),
            $treeVersion,
        ]);
    }
}
