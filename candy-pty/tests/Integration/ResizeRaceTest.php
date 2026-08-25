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

        $reading = self::readWidths($captured);

        $this->assertSame(
            [],
            $reading['unexpected'],
            \sprintf(
                'torn read: numeric line(s) %s are not among %s (captured: %s)',
                \implode(',', $reading['unexpected']),
                \implode(',', self::WIDTHS),
                \var_export($captured, true),
            ),
        );
        $this->assertGreaterThan(
            0,
            $reading['numeric'],
            'expected at least one numeric width line in `tput cols` output',
        );
    }

    /**
     * The torn-read detector, run against a reading whose answer is known.
     *
     * {@see testResizeRaceProducesUntornWidths()} asserts an ABSENCE -- that no
     * numeric line falls outside the resize set -- and an absence is also what a
     * detector that matches nothing reports. The aggregate form above makes that
     * failure mode cheaper to reach than the old per-line one did: neutralise
     * the `ctype_digit()` arm, or the set lookup, and `unexpected` is `[]` on
     * every host forever while `numeric` quietly goes to zero. Only the second
     * assertion above would notice, and only for one of those two mutations.
     *
     * So the same static reader is driven here with a transcript carrying the
     * exact artifact the live test exists to catch: a width of 120 torn across
     * two reads as `1` and `20`, with the CR/LF translation a cooked PTY
     * applies, plus one of the non-numeric bash job-control lines the live
     * reader is supposed to ignore.
     *
     * ## Why the separators are enumerated rather than assumed
     *
     * The reader splits on `\r\n`, `\r` OR `\n`, and the fixture originally
     * spelled every line ending `\r\n` -- the one shape a cooked Linux PTY
     * produces, which is to say the shape already known. That is an alphabet
     * narrower than the code it is checking: MEASURED, narrowing the split to
     * `\r\n` alone SURVIVED `--filter ResizeRaceTest` (`OK (2 tests, 9
     * assertions)`), so two of the three separators the reader accepts were
     * asserted by nobody. The other two endings are not decoration -- `\n` is
     * what a raw (non-cooked) master gives, and a lone `\r` is what a child
     * that writes its own CR without LF gives -- and if the reader is ever
     * narrowed, the live test above cannot notice: it asserts an absence, and a
     * split that stops matching produces exactly that.
     *
     * The whitespace-padded row is here for the same reason: `trim()` is the
     * only thing making `" 132 "` numeric, and nothing else in this file has
     * ever handed it a line that needed trimming.
     */
    public function testTheTornReadDetectorSeesATornRead(): void
    {
        $torn = self::readWidths("80\r\n1\r\n20\r\n[1]+  Done\r\n132\r\n");

        $this->assertSame(['1', '20'], $torn['unexpected'], 'the detector missed a torn read');
        $this->assertSame(4, $torn['numeric'], 'the detector miscounted the numeric lines');

        $clean = self::readWidths("80\r\n120\r\n100\r\n132\r\n90\r\n\r\n");

        $this->assertSame([], $clean['unexpected'], 'the detector invented a torn read');
        $this->assertSame(5, $clean['numeric'], 'the detector lost a clean width line');

        // Bare LF: what a master that is NOT in cooked mode delivers.
        $lf = self::readWidths("80\n1\n20\n132\n");
        $this->assertSame(
            ['1', '20'],
            $lf['unexpected'],
            'the reader stopped splitting on a bare \n, so a raw-mode transcript reads as one '
            . 'unparseable line and every torn read in it becomes invisible',
        );
        $this->assertSame(4, $lf['numeric'], 'the reader miscounted a bare-LF transcript');

        // Bare CR: what a child writing its own CR without an LF delivers.
        $cr = self::readWidths("80\r1\r20\r132\r");
        $this->assertSame(
            ['1', '20'],
            $cr['unexpected'],
            'the reader stopped splitting on a bare \r, so a CR-only transcript reads as one '
            . 'unparseable line and every torn read in it becomes invisible',
        );
        $this->assertSame(4, $cr['numeric'], 'the reader miscounted a CR-only transcript');

        // trim(): the only thing that makes a padded line numeric at all.
        $padded = self::readWidths("  80  \r\n\t132\t\r\n 1 \r\n");
        $this->assertSame(
            ['1'],
            $padded['unexpected'],
            'the reader stopped trimming, so a padded width line is not ctype_digit() and is '
            . 'silently dropped instead of judged',
        );
        $this->assertSame(3, $padded['numeric'], 'the reader lost a padded width line');

        $this->assertSame(
            ['numeric' => 0, 'unexpected' => []],
            self::readWidths(''),
            'an empty capture is not a reading; the live test asserts non-emptiness separately',
        );
    }

    /**
     * Split a captured stream into width lines and answer with the count of
     * numeric lines and the ones outside {@see WIDTHS}.
     *
     * ## Why this is one aggregate answer and not an assertion per line
     *
     * WHAT THE LOOP HERE USED TO DO: call `assertArrayHasKey()` once per numeric
     * line, inside the test. WHAT IS TRUE ABOUT THAT: the number of lines the
     * child emits inside a ~1 s window is a function of host scheduling, so the
     * test's ASSERTION COUNT was timing-derived by construction -- and it is the
     * only test in this package that is. MEASURED at `5bef36cb`, PHP 8.3.6, 20
     * consecutive full-suite takes with tests, skips, warnings and rc identical
     * throughout: the package's assertion total came out 1475 twice, 1476 seven
     * times, 1477 ten times and 1478 once. Six further takes logged as JUnit and
     * compared per `<testcase assertions="...">` across all 606 tests found
     * exactly one non-constant entry, this test, at 94/96/94/94/96/93.
     *
     * WHY THAT EARNS A REWRITE RATHER THAN A NOTE: this package's figures are
     * quoted as a floor by the work that consumes it, and a suite whose
     * assertion count is not a function of its source cannot be a floor -- a
     * lane comparing one take against 1476 can be off by two in either direction
     * with nothing wrong at all. Folding the per-line checks into one assertion
     * over the aggregate keeps every line checked and makes the count a
     * property of the source again.
     *
     * The reader itself is unchanged in what it tolerates: blank lines between
     * writes are ignored, and so is anything non-numeric, because bash emits
     * job-control and signal-status lines on some hosts. `tput cols` writes each
     * width followed by "\n" and a cooked PTY translates that to "\r\n", so the
     * split accepts either ending.
     *
     * Static and separate from the test body so that
     * {@see testTheTornReadDetectorSeesATornRead()} can push a KNOWN-TORN
     * transcript through this exact code rather than through a copy of it.
     *
     * @param  string $captured raw bytes read from the master
     * @return array{numeric:int, unexpected:list<string>}
     */
    private static function readWidths(string $captured): array
    {
        $lines = \preg_split('/\r\n|\r|\n/', $captured) ?: [];
        $widths = \array_fill_keys(\array_map('strval', self::WIDTHS), true);

        $numeric = 0;
        $unexpected = [];

        foreach ($lines as $raw) {
            $line = \trim($raw);
            if ($line === '' || !\ctype_digit($line)) {
                continue;
            }

            $numeric++;
            if (!\array_key_exists($line, $widths)) {
                $unexpected[] = $line;
            }
        }

        return ['numeric' => $numeric, 'unexpected' => $unexpected];
    }
}
