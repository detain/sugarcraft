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
 * The slave device is opened ONCE (O_RDWR) and the single stream is
 * reused for all three of the child's stdio descriptors, so there is
 * exactly one open()-by-path rather than three racing ones. The parent
 * closes its handle after `proc_open`; the child keeps its dup'd copies.
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
     * @see creack/pty.Start()
     * @see portable-pty.SlavePty.Start()
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

        // TOCTOU: the previous descriptor spec named `$master->slavePath`
        // three times, so PHP's stream layer opened the slave device by
        // path THREE separate times — three independent open()-by-path
        // races. Open it ONCE here (O_RDWR: readable for stdin slot 0,
        // writable for stdout/stderr slots 1-2) and reuse the single
        // resource for all three stdio slots. fd 0 is still the slave tty,
        // so the shim's ioctl(0, TIOCSCTTY) keeps working.
        $slave = @\fopen($master->slavePath, 'r+');
        if ($slave === false) {
            throw new PtyException(Lang::t('spawn.slave_open_failed', [
                'path' => $master->slavePath,
            ]));
        }

        $descriptors = [
            0 => $slave,
            1 => $slave,
            2 => $slave,
        ];
        $pipes = [];

        try {
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
        } finally {
            // proc_open dup()s the slave into the child, which now owns
            // its copies; the parent's handle must not linger (it would
            // keep the slave open on the master side). Close on EVERY exit
            // path — success and both throw branches.
            \fclose($slave);
        }
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
