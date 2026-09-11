<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Pty\Pty;

/**
 * Phase 9 layer A — interactive-prompt containment for tool shell-outs.
 *
 * A tool child that keeps the process's controlling terminal can open
 * `/dev/tty` directly: `sudo` prompts THERE (bypassing every pipe this app
 * owns), paints outside the TUI frame, and hangs on a human the frame
 * cannot see. Containment = spawn in a new session (no ctty) + fail-fast
 * environment, both threaded through the one choke point every tool child
 * shares: {@see CapturesProcessOutput::runCaptured()}.
 *
 * WHY A PTY PROOF EXISTS IN THIS FILE (the plan demanded it, by name): a
 * headless CI has no controlling terminal AT ALL, so "child cannot open
 * /dev/tty" passes there with or without the fix — the canonical fixture
 * shaped like the property rather than the bug. The PTY test allocates a
 * REAL controlling terminal via candy-pty, runs the trait inside it, and
 * fails the containment claim against a terminal that genuinely exists.
 * Its environment guards are the same ones ForkedChildTest already relies
 * on in every green run of this suite — if one ever fires, SuiteSkipRoster
 * has already reddened the run for that host, so no new skip shape is
 * introduced here.
 */
final class InteractivePromptContainmentTest extends TestCase
{
    /**
     * Trait host: runCaptured() and the setsid probe are private to the
     * trait, so the test composes them through an anonymous class — the
     * host scope grants access without widening the tools' public surface.
     */
    private static function host(): object
    {
        return new class {
            use CapturesProcessOutput;

            /** @return array<string,mixed> */
            public function capture(string $command): array
            {
                return $this->runCaptured($command);
            }

            public function detachBinary(): string
            {
                return self::detachedSpawnBinary();
            }
        };
    }

    public function testFailFastEnvironmentReachesEveryToolChild(): void
    {
        $probe = self::host()->capture(
            'printf "%s\n"'
            . ' "GTP=[$GIT_TERMINAL_PROMPT]"'
            . ' "GAP=[$GIT_ASKPASS]"'
            . ' "SAP=[$SSH_ASKPASS]"'
            . ' "DF=[$DEBIAN_FRONTEND]"'
            . ' "PAGER=[$PAGER]"'
            . ' "GITPAGER=[$GIT_PAGER]"'
            . ' "SPY=[$SYSTEMD_PAGER]"'
            . ' "PATHSET=[${PATH:+yes}]"'
        );

        self::assertSame(0, $probe['exitCode'], $probe['stderr']);
        self::assertStringContainsString('GTP=[0]', $probe['stdout']);
        self::assertStringContainsString('GAP=[/bin/false]', $probe['stdout']);
        self::assertStringContainsString('SAP=[echo]', $probe['stdout']);
        self::assertStringContainsString('DF=[noninteractive]', $probe['stdout']);
        self::assertStringContainsString('PAGER=[cat]', $probe['stdout']);
        self::assertStringContainsString('GITPAGER=[cat]', $probe['stdout']);
        // The plan's literal `SYSTEMD_PAGER=` (empty) is unrepresentable —
        // PHP's env param drops empty values — so the pin is the settled
        // non-empty stand-in, and `${VAR+x}` in the trait doc's probe is
        // where the drop itself stays on record.
        self::assertStringContainsString('SPY=[/bin/cat]', $probe['stdout']);
        // The env param REPLACES the child environment, so PATH surviving
        // is the proof this is a merge, not a clobber — without it every
        // `bash -c` lookup dies and containment cost more than the hang.
        self::assertStringContainsString('PATHSET=[yes]', $probe['stdout']);
    }

    public function testHomeRidesThroughTheContainmentEnv(): void
    {
        // HOME is the other required-carry: git resolves its config through
        // it, and a stripped HOME silently reroutes every tool-run git call
        // to /root-or-nothing. Expected is read from THIS process, never a
        // literal: the assertion pins inheritance, not a guess about the box.
        $expected = getenv('HOME') === false ? '' : 'yes';
        $probe = self::host()->capture('printf "[${HOME:+yes}]"');

        self::assertSame(0, $probe['exitCode'], $probe['stderr']);
        self::assertSame('[' . $expected . ']', $probe['stdout']);
    }

    public function testSudoAskpassIsStrippedButUnrelatedVarsAreNot(): void
    {
        $previous = getenv('SUDO_ASKPASS');
        $previousGpgTty = getenv('GPG_TTY');
        putenv('SUDO_ASKPASS=/sentinel/must-not-reach-a-tool-child');
        putenv('GPG_TTY=/sentinel/tty-must-not-reach-a-tool-child');
        try {
            $probe = self::host()->capture(
                'printf "SA=[${SUDO_ASKPASS+set}] GT=[${GPG_TTY+set}] KEEP=[${SC_CONTAINMENT_KEEP:-absent}]"'
            );
        } finally {
            // Restore exactly: leave-as-was, or remove if this process never
            // had it — a leaked sentinel would poison sibling tests.
            if ($previous === false) {
                putenv('SUDO_ASKPASS');
            } else {
                putenv('SUDO_ASKPASS=' . $previous);
            }
            if ($previousGpgTty === false) {
                putenv('GPG_TTY');
            } else {
                putenv('GPG_TTY=' . $previousGpgTty);
            }
        }

        self::assertSame(0, $probe['exitCode'], $probe['stderr']);
        self::assertStringContainsString('SA=[]', $probe['stdout'], 'SUDO_ASKPASS must not reach the child');
        self::assertStringContainsString('GT=[]', $probe['stdout'], 'GPG_TTY must not reach the child (E674 strip)');
        self::assertStringContainsString('KEEP=[absent]', $probe['stdout'], 'unset names must stay unset, not be forged empty');

        // And a plain inherited name still arrives — the strip list is a
        // list, not a deny-everything posture.
        putenv('SC_CONTAINMENT_KEEP=present');
        try {
            $probe2 = self::host()->capture('printf "KEEP=[${SC_CONTAINMENT_KEEP:-absent}]"');
        } finally {
            putenv('SC_CONTAINMENT_KEEP');
        }
        self::assertSame('KEEP=[present]', $probe2['stdout']);
    }

    /**
     * The fast-path discipline: streams stay separate, statuses survive the
     * wrapper on BOTH the detach and the fallback path (measured here and
     * identically without setsid — signal death reports 15 in both, the
     * same number PHP itself gives, so no tool result changes shape).
     */
    public function testExitStatusAndStreamSeparationSurviveTheContainmentSpawn(): void
    {
        $host = self::host();

        $clean = $host->capture('printf out; printf err 1>&2');
        self::assertSame('out', $clean['stdout']);
        self::assertSame('err', $clean['stderr']);
        self::assertSame(0, $clean['exitCode']);

        $failed = $host->capture('printf out; printf err 1>&2; exit 42');
        self::assertSame('out', $failed['stdout']);
        self::assertSame('err', $failed['stderr']);
        self::assertSame(42, $failed['exitCode'], 'the -w wrapper must report the COMMAND exit code, never its own');

        $signalled = $host->capture('kill -TERM $$');
        self::assertSame(15, $signalled['exitCode'], 'signal death keeps reporting WTERMSIG (15), measured equal across wrapper and fallback');

        $closedStdin = $host->capture('cat');
        self::assertSame('', $closedStdin['stdout'], 'stdin must still answer EOF instantly');
        self::assertSame(0, $closedStdin['exitCode']);
    }

    /**
     * Behavioral contract: a tty-reading command dies fast with a legible
     * diagnostic on the captured stderr — bounded, never a hang. In a
     * headless run this also passes without setsid (no ctty to reach); the
     * discriminating proof against a live terminal is the PTY test below.
     * `timeout` is only a ceiling that keeps a regressed (attached) child
     * from consuming the 60s defaultTimeLimit — its presence must not be
     * what makes the assertion pass, so the diagnostic is asserted too.
     */
    public function testTtyReaderDiesFastWithDiagnosticInsteadOfHanging(): void
    {
        if (self::host()->detachBinary() === '') {
            // No usable setsid (absent like macOS, or present but unable to
            // forward `-w`): the fail-fast claim belongs to the detach path
            // and must not be faked here. The env half holds in EVERY mode,
            // so pin that instead of passing vacuously.
            $probe = self::host()->capture('printf "GTP=[$GIT_TERMINAL_PROMPT]"');
            self::assertSame('GTP=[0]', $probe['stdout']);
            return;
        }

        $start = microtime(true);
        $run = self::host()->capture('cat /dev/tty');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(10.0, $elapsed, 'a contained tty read must die at open, not run out a timer');
        self::assertNotSame(0, $run['exitCode']);
        self::assertStringContainsString('/dev/tty', $run['stderr']);
        self::assertSame('', $run['stdout']);
    }

    /**
     * Layer C seam, pinned as an ABSENCE: the settled design is an optional
     * `interactive` PARAMETER on Bash (never a second tool). The parameter
     * must not exist before the mechanism does — a schema flag with no
     * effect teaches the model a lie. When layer C lands, this test flips
     * on purpose, which is exactly what makes landing it a decision.
     */
    public function testNoInteractiveParameterIsPlumbedYet(): void
    {
        $schema = (new Bash())->inputSchema();

        self::assertArrayNotHasKey('interactive', $schema['properties']);
        self::assertArrayNotHasKey('interactive', $schema['required'] ?? []);
    }

    /**
     * THE non-vacuous proof (plan step 2): put the tool shell-out under a
     * REAL controlling terminal, have the grandchild try to paint on
     * /dev/tty, and assert the bytes never reach that terminal — while a
     * control write from the PTY-attached process itself DOES. The first
     * assertion proves the terminal is live; without it the second proves
     * nothing.
     */
    public function testToolChildCannotPaintOnALiveControllingTerminal(): void
    {
        $host = self::host();
        if ($host->detachBinary() === '') {
            // No setsid on this host: detach is knowingly absent (macOS
            // ships none). Assert the half that still holds instead of a
            // vacuous pass — the PTY leak claim belongs to the detach path.
            $probe = $host->capture('printf "GTP=[$GIT_TERMINAL_PROMPT]"');
            self::assertSame('GTP=[0]', $probe['stdout']);
            return;
        }

        // Same precondition set ForkedChildTest guards with: a host missing
        // any of these already reddens SuiteSkipRoster through that file,
        // so these skips never fire in a supported run of this suite.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to allocate the pty.');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for the controlling-terminal shim.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }

        // Named by random bytes, not tempnam(): tempnam would leave its own
        // suffix-less file orphaned next to the .php we actually exec.
        $fixture = sys_get_temp_dir() . '/sc_pty_contain_' . bin2hex(random_bytes(6)) . '.php';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($fixture, str_replace(
            'AUTOLOAD_PATH',
            var_export($autoload, true),
            <<<'PHP'
            <?php
            require AUTOLOAD_PATH;

            $host = new class {
                use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;

                /** @return array<string,mixed> */
                public function capture(string $command): array
                {
                    return $this->runCaptured($command);
                }

                public function detachBinary(): string
                {
                    return self::detachedSpawnBinary();
                }
            };

            // Control: a write to /dev/tty by the PTY-attached process ITSELF
            // must reach the terminal. If this line does not show up, the
            // terminal is not live and the containment claim below is worth
            // nothing — which is the failure mode this whole test exists to
            // rule out (headless CI has no ctty; a test built on that silence
            // is the fixture shaped like the property, not the bug).
            $tty = @fopen('/dev/tty', 'w');
            if ($tty === false) {
                fwrite(STDOUT, "SANITY=MISSING\n__END__\n");
                exit(9);
            }
            fwrite($tty, "SANITY_DIRECT\n");
            fclose($tty);

            // The claim: through the contained spawn, a grandchild trying to
            // paint LEAKED on the controlling terminal fails at open, and the
            // command still reports (captured stdout + real exit code).
            $run = $host->capture('echo LEAKED > /dev/tty; cat /dev/tty; echo CAPTURED_STDOUT; exit 42');
            fwrite(STDOUT, sprintf(
                "RESULT exit=%d out=[%s] ttyerr=%s\n__END__\n",
                $run['exitCode'],
                $run['stdout'],
                str_contains($run['stderr'], '/dev/tty') ? 'yes' : 'no',
            ));
            PHP
        ));

        try {
            $pty = Pty::open();
            $child = $pty->spawn([PHP_BINARY, $fixture], null, 80, 24, true);

            // Bounded read, never an unbounded wait: a regressed (attached)
            // grandchild hanging on `cat /dev/tty` must surface as a red
            // with evidence, on the clock, not as the 60s alarm.
            $seen = '';
            $deadline = microtime(true) + 20.0;
            while (microtime(true) < $deadline) {
                $chunk = $pty->read(8192, 0.2);
                if ($chunk !== null && $chunk !== '') {
                    $seen .= $chunk;
                    if (str_contains($seen, '__END__')) {
                        break;
                    }
                }
            }
            $pty->close();

            self::assertStringContainsString(
                'SANITY_DIRECT',
                $seen,
                'harness sanity: the allocated PTY must actually carry a controlling terminal, '
                . 'or a passing containment claim here would prove nothing — seen: ' . var_export($seen, true),
            );
            self::assertStringContainsString('RESULT exit=42', $seen, 'fixture: ' . var_export($seen, true));
            self::assertStringContainsString('out=[CAPTURED_STDOUT]', $seen);
            self::assertStringContainsString('ttyerr=yes', $seen, 'the refusal must arrive as a captured diagnostic, not a hang');
            self::assertStringNotContainsString(
                'LEAKED',
                $seen,
                'a tool child painted through the controlling terminal — the exact Phase 9 display leak',
            );
        } finally {
            @unlink($fixture);
        }
    }
}
