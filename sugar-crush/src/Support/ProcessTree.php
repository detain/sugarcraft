<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Read-only view of the kernel's process table through `/proc` — the half of
 * {@see ProcessContainment::killTree()} that answers "who descends from this
 * pid, and which process group is each of them in".
 *
 * WHY A TABLE SCAN AND NOT A RECORDED LEDGER (audit B2/F-E2). The processes a
 * turn leaves behind are not all spawned by code that could record them: a
 * `make` forks compilers, a Task sub-agent forks its own parallel group, a
 * user hook forks whatever it likes. Every one of them, however, is a
 * DESCENDANT of the process the teardown is aimed at for as long as that
 * process is alive, and the kernel already keeps that relation. Reading it is
 * the only answer that cannot miss a spawn site nobody thought to instrument.
 *
 * LINUX ONLY, BY CONSTRUCTION: every method answers "nothing known" (null or
 * an empty list) when `/proc/<pid>/stat` is not readable, and the caller then
 * falls back to the direct-pid behaviour it had before this class existed.
 */
final class ProcessTree
{
    /**
     * Whether this host exposes a readable `/proc/<pid>/stat` at all.
     */
    public static function available(): bool
    {
        return \PHP_OS_FAMILY === 'Linux' && \is_readable('/proc/self/stat');
    }

    /**
     * The fields of one `/proc/<pid>/stat` line this package needs, or null for
     * a line that does not parse.
     *
     * Parsed from the text AFTER THE LAST `)`: field 2 is the command name in
     * parentheses and may itself contain spaces and parentheses (`(sh) (x)` is
     * a legal comm), so splitting the whole line on spaces would misnumber
     * every later field for exactly the process a hostile command named.
     *
     * `startTicks` is field 22 (`starttime`, clock ticks since boot): what
     * tells a pid from a later process the kernel handed the same number to.
     * It is null — not 0, which is a real value for a process started at boot
     * — on a line too short to carry it or whose field is not a number; the
     * other fields still parse, since a caller asking only for the state has
     * no use for a refusal.
     *
     * @return array{pid: int, state: string, ppid: int, pgid: int, sid: int, startTicks: int|null}|null
     */
    public static function parseStat(string $line): ?array
    {
        $open = \strpos($line, ' (');
        $close = \strrpos($line, ')');
        if ($open === false || $close === false || $close < $open) {
            return null;
        }

        $pid = \substr($line, 0, $open);
        $rest = \preg_split('/\s+/', \trim(\substr($line, $close + 1)));
        if (!\ctype_digit($pid) || !\is_array($rest) || \count($rest) < 4) {
            return null;
        }

        // Field 3 (state) is index 0 here, so starttime (field 22) is index 19.
        $startTicks = $rest[19] ?? null;

        return [
            'pid' => (int) $pid,
            'state' => (string) $rest[0],
            'ppid' => (int) $rest[1],
            'pgid' => (int) $rest[2],
            'sid' => (int) $rest[3],
            'startTicks' => \is_string($startTicks) && \ctype_digit($startTicks) ? (int) $startTicks : null,
        ];
    }

    /**
     * One pid's parsed stat, or null when it is gone (or /proc is absent).
     *
     * @return array{pid: int, state: string, ppid: int, pgid: int, sid: int, startTicks: int|null}|null
     */
    public static function stat(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }

        $line = @\file_get_contents('/proc/' . $pid . '/stat');

        return \is_string($line) ? self::parseStat($line) : null;
    }

    /**
     * Every process the kernel lists right now, keyed by pid, or null when
     * /proc cannot be read. A process that exits between the directory read
     * and its stat read is simply absent — the scan is a snapshot, and its
     * caller ({@see ProcessContainment::killTree()}) re-scans until stable.
     *
     * @return array<int, array{pid: int, state: string, ppid: int, pgid: int, sid: int, startTicks: int|null}>|null
     */
    public static function snapshot(): ?array
    {
        if (!self::available()) {
            return null;
        }

        $entries = @\scandir('/proc');
        if (!\is_array($entries)) {
            return null;
        }

        $table = [];
        foreach ($entries as $entry) {
            if (!\ctype_digit($entry)) {
                continue;
            }
            $stat = self::stat((int) $entry);
            if ($stat !== null) {
                $table[$stat['pid']] = $stat;
            }
        }

        return $table;
    }

    /**
     * Every pid below $pid in the parent→child relation, breadth first, NOT
     * including $pid itself. Read from $snapshot when one is given (so a
     * caller can pair the walk with the states of the same scan), from a fresh
     * {@see snapshot()} otherwise; empty when /proc is absent.
     *
     * @param array<int, array{pid: int, state: string, ppid: int, pgid: int, sid: int, startTicks: int|null}>|null $snapshot
     * @return list<int>
     */
    public static function descendants(int $pid, ?array $snapshot = null): array
    {
        $table = $snapshot ?? self::snapshot();
        if ($table === null || $pid <= 0) {
            return [];
        }

        $children = [];
        foreach ($table as $row) {
            $children[$row['ppid']][] = $row['pid'];
        }

        $found = [];
        $queue = [$pid];
        while ($queue !== []) {
            $parent = \array_shift($queue);
            foreach ($children[$parent] ?? [] as $child) {
                if ($child === $pid || isset($found[$child])) {
                    continue;
                }
                $found[$child] = true;
                $queue[] = $child;
            }
        }

        return \array_keys($found);
    }
}
