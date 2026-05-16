<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Shared scaffolding for end-to-end shell + REPL integration tests.
 *
 * Each subclass picks a binary, sends an "echo" probe and an exit
 * sequence, then asserts the probe round-tripped through a real PTY.
 *
 * Mirrors creack/pty integration tests — same skip-guard ladder
 * (POSIX-only, ffi, /dev/ptmx, pcntl, binary present), same
 * try/finally teardown pairing every spawn with kill+wait+close.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.1, P5.2)
 */
abstract class InteractiveShellTestCase extends TestCase
{
    /**
     * Wallclock budget per test in seconds. Kept conservative so a
     * stalled child can't blow the suite's <10s/test target.
     */
    protected const WALLCLOCK_BUDGET_SEC = 5.0;

    /**
     * POSIX + FFI prerequisites — must run before any PTY syscall.
     * Mirrors the helper in {@see \SugarCraft\Pty\Tests\Posix\PosixChildTest}.
     */
    protected function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required to fork the shell child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    /**
     * Skip cleanly if the named binary is not executable. Test files
     * call this AFTER {@see requirePtySyscalls()} to gate per-binary.
     */
    protected function requireBinary(string $path, string $name): void
    {
        if (!\is_executable($path)) {
            $this->markTestSkipped(\sprintf('%s not installed at %s', $name, $path));
        }
    }

    /**
     * Spawn a child, write the script, drain master until the marker
     * appears or the wallclock budget runs out, then reap.
     *
     * The script must include its own exit sequence (e.g. `exit\n`,
     * `exit()\n`, `\x04`) — the helper does not append one. The
     * try/finally guarantees the master is closed and the child reaped
     * even on assertion failure.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env
     */
    protected function runShellRoundTrip(
        array $cmd,
        string $script,
        array|null $env = null,
        float $budget = self::WALLCLOCK_BUDGET_SEC,
    ): string {
        $env ??= [
            'TERM' => 'dumb',
            'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            // Silence locale warnings on slim CI images.
            'LANG' => 'C',
            'LC_ALL' => 'C',
            // Suppress python startup banner / readline.
            'PYTHONSTARTUP' => '',
            'PYTHONDONTWRITEBYTECODE' => '1',
        ];

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;

        try {
            $child = $pair->slave()->spawn($cmd, $env, 80, 24, controllingTerminal: true);

            \stream_set_blocking($master->stream(), false);
            $master->write($script);

            return $this->drainUntilExit($master, $child, $budget);
        } finally {
            if ($child !== null && !$child->exited()) {
                try {
                    $child->kill(MasterPty::SIGKILL);
                } catch (\Throwable) {
                    // Ignore — process may have raced to exit.
                }
                try {
                    $child->wait();
                } catch (\Throwable) {
                    // Ignore — wait may fail if pcntl already reaped.
                }
            }
            if (!$master->isClosed()) {
                $master->close();
            }
        }
    }

    /**
     * Read from $master until the child exits, EOF arrives, or the
     * wallclock budget is exhausted. Returns the concatenated capture.
     */
    private function drainUntilExit(MasterPty $master, Child $child, float $budget): string
    {
        $captured = '';
        $deadline = \microtime(true) + $budget;

        while (\microtime(true) < $deadline) {
            $chunk = $master->read(4096, 0.05);
            if ($chunk === null) {
                if ($child->exited()) {
                    $tail = $master->read(8192, 0.05);
                    if ($tail !== null && $tail !== '') {
                        $captured .= $tail;
                    }
                    break;
                }
                continue;
            }
            if ($chunk === '') {
                break;
            }
            $captured .= $chunk;
        }

        return $captured;
    }
}
