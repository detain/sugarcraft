<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Discovery;

/**
 * Whether a pid is still the process a discovery record was written for.
 *
 * The read side of candy-code's `Daemonize::isSameProcess`. A record is a
 * claim, and the kernel recycles pids, so a pid counts only when it is alive,
 * not a zombie, and (when both sides know it) started at the recorded start
 * time. Linux reads `/proc/<pid>/stat`; a host without procfs falls back to
 * `posix_kill(pid, 0)`. Parity with the host body is pinned by candy-code's
 * `ProcessIdentityParityTest` on the live, dead and start-time-mismatch cases.
 */
final class ProcessIdentity
{
    private function __construct()
    {
    }

    public static function isSameProcess(int $pid, ?int $startTime): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = self::procStat($pid);
        if ($stat !== null && \in_array($stat['state'], ['Z', 'X'], true)) {
            return false;
        }
        if ($startTime !== null && $stat !== null && $stat['startTicks'] !== null) {
            return $stat['startTicks'] === $startTime;
        }
        if ($stat !== null) {
            return true;
        }

        return \function_exists('posix_kill') && @\posix_kill($pid, 0);
    }

    /**
     * @return array{state: string, startTicks: ?int}|null
     */
    private static function procStat(int $pid): ?array
    {
        $raw = @\file_get_contents('/proc/' . $pid . '/stat');
        if ($raw === false) {
            return null;
        }
        // The comm field is parenthesised and may hold spaces or parens, so the
        // fields are read from after the last `)`.
        $close = \strrpos($raw, ')');
        if ($close === false) {
            return null;
        }
        $fields = \preg_split('/\s+/', \trim(\substr($raw, $close + 1)));
        if ($fields === false || !isset($fields[0], $fields[19])) {
            return null;
        }

        return [
            'state' => $fields[0],
            'startTicks' => \ctype_digit($fields[19]) ? (int) $fields[19] : null,
        ];
    }
}
