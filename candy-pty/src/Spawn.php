<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Posix\PosixChild;

/**
 * @internal Low-level spawn helper — not part of the public API. Call
 *           {@see \SugarCraft\Pty\Posix\PosixSlavePty::spawn()} (or inject
 *           {@see \SugarCraft\Pty\Contract\SlavePty} via PtySystemFactory)
 *           instead; both of those delegate here. This class is the live
 *           implementation, NOT a deprecated-for-removal shim.
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
 * other tty-driven job-control signals. The wrap carries this process's
 * own autoloader to the shim as `--autoload=` (see {@see self::wrapInShim()})
 * so the shim still resolves `SugarCraft\Pty\*` when it is reached
 * through a symlinked vendor directory.
 *
 * Mirrors charmbracelet/x/xpty.UnixPty.Start's spawn algorithm.
 */
final class Spawn
{
    /** Path to the bundled controlling-terminal shim. */
    private const SHIM_RELATIVE = '/../bin/pty-shim.php';

    /**
     * @internal Live internal helper reached via
     *           {@see \SugarCraft\Pty\Posix\PosixSlavePty::spawn()} and
     *           {@see \SugarCraft\Pty\Pty::spawn()}. Not removal-scheduled.
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
     * Prepend `[PHP_BINARY, /path/to/pty-shim.php, --autoload=<path>?, ...]`
     * to the cmd so the actual command runs inside a session where the
     * slave PTY is the controlling terminal.
     *
     * The `--autoload=` token hands the shim the autoloader THIS process
     * is running under. WHY: PHP resolves `__FILE__`/`__DIR__` through
     * symlinks, so when this package is installed as a Composer path-repo
     * sibling — the monorepo's linked shape, and any consumer whose
     * `vendor/sugarcraft/candy-pty` symlinks a checkout with no `vendor/`
     * of its own — the shim cannot find an autoloader from its own
     * location. This process, by contrast, loaded these very classes
     * through the consumer's autoloader; naming that file outright turns
     * the shim's path guessing into a fact. When no autoload.php can be
     * identified here, the token is omitted and the shim's own resolution
     * ladder (see bin/pty-shim.php) takes over.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private static function wrapInShim(array $cmd): array
    {
        if (!\extension_loaded('pcntl')) {
            throw new PtyException(Lang::t('spawn.shim_pcntl_required'));
        }

        // realpath() so the child's argv[0] names the canonical location:
        // `__DIR__ . '/../bin/…'` would otherwise hand the shim an
        // unresolved path, and every path the shim reasons about (its own
        // probes, the lexical vendor-root read) deserves to start clean.
        $shim = \realpath(__DIR__ . self::SHIM_RELATIVE);
        if ($shim === false || !\is_readable($shim)) {
            throw new PtyException(Lang::t('spawn.shim_not_found', ['path' => __DIR__ . self::SHIM_RELATIVE]));
        }

        $argv = [PHP_BINARY, $shim];
        $autoload = self::callerAutoloader();
        if ($autoload !== null) {
            $argv[] = '--autoload=' . $autoload;
        }

        return [...$argv, ...$cmd];
    }

    /**
     * Absolute path of the `vendor/autoload.php` this process actually
     * included, or null when none can be identified.
     *
     * Scanning the include set rather than probing from `__DIR__` is the
     * whole point: `__DIR__` sits in the REAL package checkout (symlinks
     * resolved), which in a path-repo install is precisely the directory
     * that carries no vendor — while the entrypoint every Composer
     * bootstrap ran (`$loader = require .../vendor/autoload.php`) is the
     * consumer's own, and it is in every phpunit/CLI process by
     * construction. The first match wins: it is the earliest-included,
     * i.e. the loader that started this process.
     *
     * @return string|null
     */
    private static function callerAutoloader(): ?string
    {
        foreach (\get_included_files() as $file) {
            if (\preg_match('#/vendor/autoload\.php$#', $file) === 1 && \is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    private function __construct() {}
}
