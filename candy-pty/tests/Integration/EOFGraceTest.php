<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Confirms the EOF-grace drain path: closing stdin (via VEOF) on a
 * child mid-write must not chop the tail bytes the child still has
 * queued in the slave's output buffer.
 *
 * The probe is a tiny bash loop that echoes each input line back, then
 * prints `done`, then sleeps 0.5 s. The trailing sleep keeps the
 * child alive after EOF long enough for the master-side reader to
 * drain `done` BEFORE either the parent closes the master or the
 * child's natural exit yanks the slave fd shut.
 *
 * Mirrors creack/pty's "close stdin during sleep" integration check.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.5)
 */
final class EOFGraceTest extends TestCase
{
    private const BASH_PATH = '/usr/bin/bash';
    private const WALLCLOCK_BUDGET_SEC = 5.0;

    /**
     * POSIX + FFI prerequisites — must run before any PTY syscall.
     * Mirrors {@see InteractiveShellTestCase::requirePtySyscalls()}.
     */
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required to fork the bash child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testVeofDrainsTailBytesBeforeChildExit(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::BASH_PATH)) {
            $this->markTestSkipped(\sprintf('bash not installed at %s', self::BASH_PATH));
        }

        $start = \microtime(true);

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;

        try {
            $child = $pair->slave()->spawn(
                [
                    self::BASH_PATH,
                    '-c',
                    'while read line; do echo "got:$line"; done; echo done; sleep 0.5',
                ],
                [
                    'TERM' => 'dumb',
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                ],
                80,
                24,
                controllingTerminal: true,
            );

            \stream_set_blocking($master->stream(), false);

            $captured = '';

            // Push two input lines and drain echoes between them so the
            // master buffer can't backpressure into the slave's read
            // path. Each iteration drains for up to 0.3 s.
            $master->write("line1\n");
            $captured .= $this->drain($master, 0.3);

            $master->write("line2\n");
            $captured .= $this->drain($master, 0.3);

            // VEOF (^D) in canonical mode is the kernel's signal for
            // "deliver buffered bytes + EOF on the next read()" — the
            // bash `read` loop exits, then `echo done; sleep 0.5` keeps
            // the child alive long enough for us to drain the tail.
            $master->write("\x04");

            // Drain up to ~1 s after VEOF, exiting early once we've
            // observed BOTH echoes plus the trailing "done" marker.
            $drainDeadline = \microtime(true) + 1.0;
            while (\microtime(true) < $drainDeadline) {
                $captured .= $this->drain($master, 0.1);
                if (
                    \str_contains($captured, 'got:line1')
                    && \str_contains($captured, 'got:line2')
                    && \str_contains($captured, 'done')
                ) {
                    break;
                }
            }

            $this->assertStringContainsString(
                'got:line1',
                $captured,
                'first echo round-trip lost before VEOF',
            );
            $this->assertStringContainsString(
                'got:line2',
                $captured,
                'second echo round-trip lost before VEOF',
            );
            $this->assertStringContainsString(
                'done',
                $captured,
                'tail "done" marker must drain after VEOF / before child exit',
            );

            $exit = $child->wait();
            $this->assertSame(
                0,
                $exit,
                'bash must exit zero after VEOF + clean while-loop termination',
            );

            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'EOFGraceTest exceeded its 5 s wallclock budget',
            );
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
     * Non-blocking drain for $window seconds. Returns the concatenated
     * bytes that arrived during the window; empty string on quiet/EOF.
     */
    private function drain(MasterPty $master, float $window): string
    {
        $captured = '';
        $deadline = \microtime(true) + $window;
        while (\microtime(true) < $deadline) {
            $chunk = $master->read(8192, 0.05);
            if ($chunk === null) {
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
