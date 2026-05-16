<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Posix\PosixChild;

/**
 * @deprecated since v0.x; use PosixSlavePty::spawn() or inject
 *              SugarCraft\Pty\Contract\SlavePty via PtySystemFactory.
 *              Will be removed in v2.0.
 *
 * Wires `proc_open()` to a slave PTY path so the spawned child's
 * stdin / stdout / stderr all read from / write to the same pseudo-
 * terminal device.
 *
 * The slave path is opened three separate times — once per descriptor
 * — by PHP's stream layer. The kernel handles this fine on Linux; the
 * macOS path is verified by SpawnTest.
 *
 * When `$controllingTerminal` is true, the spawn is wrapped in
 * `bin/pty-shim.php` which runs `setsid()` + `ioctl(0, TIOCSCTTY, 0)`
 * + `pcntl_exec()` so the child claims the slave PTY as its
 * controlling terminal — required for Ctrl+C → SIGINT delivery and
 * other tty-driven job-control signals.
 *
 * Mirrors charmbracelet/x/xpty.UnixPty.Start's spawn algorithm.
 */
final class Spawn
{
    /** Path to the bundled controlling-terminal shim. */
    private const SHIM_RELATIVE = '/../bin/pty-shim.php';

    /**
     * @deprecated since v0.x; use PosixSlavePty::spawn() instead.
     *              Will be removed in v2.0.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env  null inherits parent env
     * @param bool                      $controllingTerminal  see class
     *                                  doc; opt-in because shim startup
     *                                  costs ~5-50ms and only interactive
     *                                  shells / editors actually need it.
     */
    public static function proc(
        Master $master,
        array $cmd,
        ?array $env = null,
        bool $controllingTerminal = false,
    ): Child {
        if ($cmd === []) {
            throw new \InvalidArgumentException('Spawn::proc requires a non-empty command');
        }

        if ($controllingTerminal) {
            $cmd = self::wrapInShim($cmd);
        }

        $descriptors = [
            0 => ['file', $master->slavePath, 'r'],
            1 => ['file', $master->slavePath, 'w'],
            2 => ['file', $master->slavePath, 'w'],
        ];
        $pipes = [];

        $process = @\proc_open(
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

        return new PosixChild($pid, $process);
    }

    /**
     * Prepend `[PHP_BINARY, /path/to/pty-shim.php]` to the cmd so the
     * actual command runs inside a session where the slave PTY is the
     * controlling terminal.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private static function wrapInShim(array $cmd): array
    {
        if (!\extension_loaded('pcntl')) {
            throw new PtyException(Lang::t('spawn.shim_pcntl_required'));
        }

        $shim = __DIR__ . self::SHIM_RELATIVE;
        if (!\is_file($shim) || !\is_readable($shim)) {
            throw new PtyException(Lang::t('spawn.shim_not_found', ['path' => $shim]));
        }

        return [PHP_BINARY, $shim, ...$cmd];
    }

    private function __construct() {}
}
