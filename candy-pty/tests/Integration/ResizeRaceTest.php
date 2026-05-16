<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Stress the TIOCSWINSZ path: resize the PTY 50 times in ~1 second
 * while a child writes `tput cols` on a 10 ms cadence, then assert
 * every non-empty / non-noise line of output is a parseable integer
 * in the resize set.
 *
 * The bug this guards against is a torn read where a width like "120"
 * would arrive as "1" / "20" on consecutive reads — exactly the kind
 * of artifact you'd see if SIGWINCH ran between the slave's stat-buf
 * compose and its terminating "\n".
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.4)
 */
final class ResizeRaceTest extends TestCase
{
    private const BASH_PATH = '/usr/bin/bash';
    private const TPUT_PATH = '/usr/bin/tput';
    private const WALLCLOCK_BUDGET_SEC = 3.0;

    /** Widths cycled through during the resize race. */
    private const WIDTHS = [80, 120, 100, 132, 90];

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
            $this->markTestSkipped('ext-pcntl is required to fork the shell child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testResizeRaceProducesUntornWidths(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::BASH_PATH)) {
            $this->markTestSkipped(\sprintf('bash not installed at %s', self::BASH_PATH));
        }
        if (!\is_executable(self::TPUT_PATH)) {
            $this->markTestSkipped(\sprintf('tput not installed at %s', self::TPUT_PATH));
        }

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;
        $captured = '';

        try {
            $child = $pair->slave()->spawn(
                [self::BASH_PATH, '-c', 'while true; do tput cols; sleep 0.01; done'],
                [
                    'TERM' => 'xterm-256color',
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                ],
                80,
                24,
                controllingTerminal: true,
            );

            \stream_set_blocking($master->stream(), false);

            // Resize 50 times across ~1 s — 20 ms per resize. Drain
            // between every resize so we never let the master's buffer
            // back-pressure into the slave write path.
            $start = \microtime(true);
            $stepDelayUsec = 20_000;
            for ($i = 0; $i < 50; $i++) {
                $width = self::WIDTHS[$i % \count(self::WIDTHS)];
                $master->resize($width, 24);
                $chunk = $master->read(8192, 0.0);
                if (\is_string($chunk) && $chunk !== '') {
                    $captured .= $chunk;
                }
                \usleep($stepDelayUsec);
            }

            // Final drain — give the slave ~100 ms to flush any in-flight
            // writes after the last resize.
            $drainDeadline = \microtime(true) + 0.1;
            while (\microtime(true) < $drainDeadline) {
                $chunk = $master->read(8192, 0.02);
                if ($chunk === null) {
                    continue;
                }
                if ($chunk === '') {
                    break;
                }
                $captured .= $chunk;
            }

            // Hard budget: the loop above is bounded, but make the
            // failure mode obvious if the host stalled.
            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'resize-race loop exceeded 3 s wallclock budget',
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

        $this->assertNotSame('', $captured, 'no output captured from `tput cols` loop');

        // tput cols emits each width followed by "\n". The PTY in cooked
        // mode translates "\n" → "\r\n", so split on either, then drop
        // empties to tolerate the race between resize + the slave's
        // "\n" write.
        $lines = \preg_split('/\r\n|\r|\n/', $captured) ?: [];
        $widths = \array_fill_keys(\array_map('strval', self::WIDTHS), true);

        $sawWidth = false;
        foreach ($lines as $raw) {
            $line = \trim($raw);
            if ($line === '') {
                // Empty between writes — fine.
                continue;
            }
            // Bash may emit job-control or signal-status messages on
            // some hosts; skip anything that obviously isn't a width.
            // The whole assertion is: every line that LOOKS numeric
            // must be a known width.
            if (!\ctype_digit($line)) {
                continue;
            }
            $this->assertArrayHasKey(
                $line,
                $widths,
                \sprintf(
                    'torn read: numeric line %s is not one of %s (captured: %s)',
                    \var_export($line, true),
                    \implode(',', self::WIDTHS),
                    \var_export($captured, true),
                ),
            );
            $sawWidth = true;
        }

        $this->assertTrue(
            $sawWidth,
            'expected at least one numeric width line in `tput cols` output',
        );
    }
}
