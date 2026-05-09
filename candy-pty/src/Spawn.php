<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Wires `proc_open()` to a slave PTY path so the spawned child's
 * stdin / stdout / stderr all read from / write to the same pseudo-
 * terminal device.
 *
 * The slave path is opened three separate times — once per descriptor
 * — by PHP's stream layer. The kernel handles this fine on Linux; the
 * macOS path is verified by SpawnTest. The alternative (dup the slave
 * fd via `pcntl_fork`) is held in reserve as a fallback if we hit a
 * macOS quirk during PR2 stabilisation.
 *
 * Mirrors charmbracelet/x/xpty.UnixPty.Start's spawn algorithm minus
 * the TIOCSCTTY shim — see candy-pty/CALIBER_LEARNINGS.md for the
 * controlling-terminal gap (Ctrl+C signals don't reach the child yet).
 */
final class Spawn
{
    /**
     * @param list<string>              $cmd
     * @param array<string,string>|null $env  null inherits parent env
     */
    public static function proc(Master $master, array $cmd, ?array $env = null): Child
    {
        if ($cmd === []) {
            throw new \InvalidArgumentException('Spawn::proc requires a non-empty command');
        }

        $descriptors = [
            0 => ['file', $master->slavePath, 'r'],
            1 => ['file', $master->slavePath, 'w'],
            2 => ['file', $master->slavePath, 'w'],
        ];
        $pipes = [];

        $process = \proc_open(
            $cmd,
            $descriptors,
            $pipes,
            null,
            $env,
            null,
        );

        if (!\is_resource($process)) {
            throw new PtyException(Lang::t('spawn.proc_open_failed', [
                'cmd' => \implode(' ', $cmd),
            ]));
        }

        $status = \proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            \proc_close($process);
            throw new PtyException(Lang::t('spawn.no_pid', [
                'cmd' => \implode(' ', $cmd),
            ]));
        }

        return new Child($pid, $process);
    }

    private function __construct() {}
}
