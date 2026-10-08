<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

/**
 * The proc filter predicates.
 *
 * {@see matches()} ports btop's matches_filter: a plain filter is a
 * case-insensitive substring of the pid, name, command or user; a filter
 * starting with `!` is a regular expression — searched in pid, name and
 * user, but FULL-matched against the command (btop uses regex_match there)
 * — and an incomplete pattern simply matches nothing (btop #1133). btop
 * compiles POSIX extended syntax; PCRE accepts the same patterns users
 * type (classes, alternation, anchors, repetition).
 *
 * Extension (plan Wave U1b): the plain filter also matches a process's
 * container / VM name, so typing a guest name finds its qemu process.
 *
 * {@see containerHidden()} is btop #1873's ctr_hidden: a container picked
 * in the ctr box shows only its processes; else, with
 * proc_filter_containers on, every process in a container — or, for
 * candy-top, in a KVM/QEMU guest — is omitted.
 *
 * {@see gpuHidden()} is btop #1552's proc_gpu_only test (the head of its
 * matches_filter): with the filter on, a process with no GPU time and no
 * GPU memory is omitted. The panel only passes it on while per-process GPU
 * data exists ({@see GpuUsage::measured()}).
 *
 * Mirrors aristocratos/btop Proc::matches_filter / ctr_hidden
 * (src/btop_shared.cpp).
 */
final class ProcFilter
{
    private function __construct()
    {
    }

    public static function matches(ProcEntry $entry, string $filter): bool
    {
        $p = $entry->process;
        if (str_starts_with($filter, '!')) {
            if ($filter === '!') {
                return true;
            }
            $pattern = substr($filter, 1);
            $search = self::regex($pattern, false);
            $full = self::regex($pattern, true);

            return self::found($search, (string) $p->pid)
                || self::found($search, $p->name)
                || self::found($full, $p->cmd)
                || self::found($search, $p->user);
        }

        return str_contains((string) $p->pid, $filter)
            || stripos($p->name, $filter) !== false
            || stripos($p->cmd, $filter) !== false
            || stripos($p->user, $filter) !== false
            || ($p->container !== null && stripos($p->container->name, $filter) !== false);
    }

    public static function gpuHidden(ProcEntry $entry, bool $gpuOnly): bool
    {
        return $gpuOnly && $entry->gpuIdle();
    }

    /**
     * btop #1873 ctr_hidden: with a container selected in the ctr box
     * (`$selected`, its cgroup path) only that container's processes show;
     * otherwise proc_filter_containers omits every containerised one.
     */
    public static function containerHidden(ProcEntry $entry, bool $filterContainers, string $selected = ''): bool
    {
        $container = $entry->process->container;
        if ($selected !== '') {
            return $container?->cgroupPath !== $selected;
        }

        return $filterContainers && $container !== null;
    }

    /** A PCRE for `$pattern`, or null when it does not compile (btop: no match). */
    private static function regex(string $pattern, bool $anchored): ?string
    {
        static $cache = [];
        $key = ($anchored ? 'a' : 's') . $pattern;
        if (!array_key_exists($key, $cache)) {
            if (count($cache) > 64) {
                $cache = [];
            }
            $body = self::escapeDelimiter($pattern);
            $re = $anchored ? '~^(?:' . $body . ')$~s' : '~' . $body . '~s';
            $cache[$key] = @preg_match($re, '') === false ? null : $re;
        }

        return $cache[$key];
    }

    /**
     * Escape every `~` the user did not escape already, so `~` can delimit
     * the pattern without changing it: `a~b` → `a\~b`, while a user's own
     * `a\~b` (and `\\~`, an escaped backslash before a bare `~`) keep their
     * meaning. Counts the backslashes in front of each `~`: an even run
     * means the `~` itself is unescaped.
     */
    public static function escapeDelimiter(string $pattern): string
    {
        $out = '';
        $slashes = 0;
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '~' && $slashes % 2 === 0) {
                $out .= '\\';
            }
            $out .= $ch;
            $slashes = $ch === '\\' ? $slashes + 1 : 0;
        }

        return $out;
    }

    private static function found(?string $re, string $subject): bool
    {
        return $re !== null && @preg_match($re, $subject) === 1;
    }
}
