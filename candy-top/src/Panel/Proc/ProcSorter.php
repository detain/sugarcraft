<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

/**
 * btop's process sorting.
 *
 * {@see sort()} ports Proc::proc_sorter: a STABLE sort on one key — pid,
 * memory, threads, cpu and io descending and the text keys ascending by
 * default, every direction flipped by proc_reversed. The input order is
 * whatever the previous frame ended with (the caller keeps it), which is
 * what makes ties stable across updates exactly as btop's in-place
 * `rng::stable_sort` on its persistent vector does.
 *
 * "cpu lazy" sorts by the cumulative cpu_c and then pulls hogs forward
 * (btop_shared.cpp:132-150): the highest cpu_p among the first six rows
 * (at least 10) becomes the bar at row six — 30 when nothing beat 30 —
 * rows already over 30 % at the head stay put, and up to eleven later rows
 * over the bar are rotated to the front. Not in tree view and not reversed.
 *
 * btop PR #1823 adds "io read" / "io write" / "io total" (bytes/s; an
 * unknown rate sorts as 0, btop's io_read_b default). btop PR #1552 adds
 * "gpu" / "gpu memory" (gpu_p / gpu_m, descending by default).
 *
 * Mirrors aristocratos/btop Proc::proc_sorter (src/btop_shared.cpp).
 */
final class ProcSorter
{
    private function __construct()
    {
    }

    /**
     * @param list<ProcEntry> $entries in the previous frame's order
     * @return list<ProcEntry>
     */
    public static function sort(array $entries, string $sorting, bool $reverse, bool $tree = false): array
    {
        $cmp = self::comparator($sorting, $reverse);
        if ($cmp !== null) {
            usort($entries, $cmp);
        }
        if (!$tree && !$reverse && $sorting === 'cpu lazy') {
            $entries = self::lazyRotate($entries);
        }

        return $entries;
    }

    /**
     * The comparator for `$sorting` — null for an unknown key (btop's
     * switch falls through and leaves the order alone).
     *
     * @return (\Closure(ProcEntry, ProcEntry): int)|null
     */
    public static function comparator(string $sorting, bool $reverse): ?\Closure
    {
        // [extractor, ascending-by-default]
        [$key, $asc] = match ($sorting) {
            'pid' => [static fn (ProcEntry $e): int => $e->pid(), false],
            'name' => [static fn (ProcEntry $e): string => $e->process->name, true],
            'command' => [static fn (ProcEntry $e): string => $e->process->cmd, true],
            'threads' => [static fn (ProcEntry $e): int => $e->threads, false],
            'user' => [static fn (ProcEntry $e): string => $e->process->user, true],
            'memory' => [static fn (ProcEntry $e): int => $e->mem, false],
            'cpu direct' => [static fn (ProcEntry $e): float => $e->cpu, false],
            'cpu lazy' => [static fn (ProcEntry $e): float => $e->cpuC, false],
            'io read' => [static fn (ProcEntry $e): float => max(0.0, $e->ioRead), false],
            'io write' => [static fn (ProcEntry $e): float => max(0.0, $e->ioWrite), false],
            'io total' => [static fn (ProcEntry $e): float => $e->ioTotal(), false],
            'gpu' => [static fn (ProcEntry $e): float => $e->gpu, false],
            'gpu memory' => [static fn (ProcEntry $e): int => $e->gpuMem, false],
            default => [null, true],
        };
        if ($key === null) {
            return null;
        }
        $ascending = $asc !== $reverse;

        // strcmp: std::string's operator< compares bytes.
        return $ascending
            ? static fn (ProcEntry $a, ProcEntry $b): int => is_string($ka = $key($a)) ? strcmp($ka, $key($b)) <=> 0 : $ka <=> $key($b)
            : static fn (ProcEntry $a, ProcEntry $b): int => is_string($kb = $key($b)) ? strcmp($kb, $key($a)) <=> 0 : $kb <=> $key($a);
    }

    /**
     * btop's "cpu lazy" hog rotation, verbatim — including that every
     * rotated row lands at the SAME offset (btop never advances it after a
     * rotate), so the last hog pulled sits first.
     *
     * @param list<ProcEntry> $v
     * @return list<ProcEntry>
     */
    public static function lazyRotate(array $v): array
    {
        $max = 10.0;
        $target = 30.0;
        $x = 0;
        $offset = 0;
        $n = count($v);
        for ($i = 0; $i < $n; $i++) {
            $cpu = $v[$i]->cpu;
            if ($i <= 5 && $cpu > $max) {
                $max = $cpu;
            } elseif ($i === 6) {
                $target = $max > 30.0 ? $max : 10.0;
            }
            if ($i === $offset && $cpu > 30.0) {
                $offset++;
            } elseif ($cpu > $target) {
                $moved = array_splice($v, $i, 1);
                array_splice($v, $offset, 0, $moved);
                if (++$x > 10) {
                    break;
                }
            }
        }

        return $v;
    }
}
