<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * rv-qa-1 / rv-devops-2 (fix 1) and rv-tui-1(c) / rv-tui-3 (fix 3).
 *
 * THE DEFECT THIS PINS CLOSED: `cat | candy-code` — no tty on either side and
 * no `-p` anywhere in argv — used to launch the full interactive Program:
 * measured as 21 KB of ANSI painted into a pipe and a process that never
 * exits (rc 124 under a bounded harness). The pre-flight now demands one tty
 * side before the TUI branch, and points at the two paths that ARE headless
 * by design.
 *
 * THE SIBLING GUARD HERE: bin also carries the rv-sec (fix 3) shutdown
 * recovery — kitty pop, mouse off, bracketed paste off, unicode off, cursor
 * show, alt-screen leave — armed by a flag set at the `Program::run()` call
 * and cleared on its return, so a fatal or an uncaught throw between them
 * still restores the terminal. Its emission cannot be exercised in-process
 * (a real attach needs a tty and a full loop), and faking it in a child buys
 * nothing the source shape does not already pin: the ORDER (registration
 * before the run, flag arming before the call, clearing after it) is the
 * load-bearing property, and an order-preserving source scan is exactly what
 * a mutation breaks. The behavioral half of the pair is the negative one:
 * headless launches stay byte-silent, which the piped child runs below assert
 * on every path that does exit (guard, `--version`).
 */
final class BinInteractiveTtyGuardTest extends TestCase
{
    private const TIMEOUT_SECONDS = 20;

    private string $tempHome = '';

    protected function tearDown(): void
    {
        if ($this->tempHome !== '' && is_dir($this->tempHome)) {
            @rmdir($this->tempHome);
        }

        parent::tearDown();
    }

    public function testAPipedLaunchWithNoPromptExitsTwoAndNamesTheHeadlessPaths(): void
    {
        [$stdout, $stderr, $exitCode] = $this->runBin([], [], keepStdinOpen: true);

        $this->assertSame(2, $exitCode, 'the no-tty TUI path must exit 2 (pre-flight discipline), got: ' . $stderr);
        $this->assertStringContainsString('needs a terminal', $stderr);
        $this->assertStringContainsString('-p', $stderr);
        $this->assertStringContainsString('serve', $stderr);
        $this->assertSame('', $stdout, 'a refused interactive launch may paint nothing on stdout');
        $this->assertStringNotContainsString("\x1b", $stderr . $stdout, 'the refusal is a usage line, not ANSI');
    }

    /**
     * A prompt operand takes the `-p` path BEFORE the guard — the guard's
     * whole point is that it stands only on the interactive branch.
     */
    public function testThePrintModePathIsUntouchedByTheGuard(): void
    {
        [$stdout, $stderr, $exitCode] = $this->runBin(['-p', 'hi'], ['CANDY_CODE_BACKEND_CMD' => 'printf ok']);

        $this->assertSame(0, $exitCode, 'a piped -p run stays legal headless work; rc/err: ' . $exitCode . ' ' . $stderr);
        $this->assertStringContainsString('ok', $stdout);
        $this->assertStringNotContainsString('needs a terminal', $stderr);
    }

    /**
     * `--version` on a pipe stays byte-silent apart from its one line — the
     * guard and the recovery shutdown must not have leaked into the pre-TUI
     * exits (rv-tui-3's headless-silence demand, re-pinned from the owned
     * side next to the guard that owns the same branch).
     */
    public function testVersionOnAPipeEmitsNoEscapeBytes(): void
    {
        [$stdout, $stderr, $exitCode] = $this->runBin(['--version'], []);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString("\x1b", $stdout . $stderr);
    }

    // =========================================================================
    // Fix 3 — source-shape pins on the recovery guard
    // =========================================================================

    public function testTheRecoveryShutdownIsArmedByTheRunCallItProtects(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-code');

        $register = strpos($bin, 'register_shutdown_function');
        $arm = strpos($bin, '$terminalWasRun = true;');
        $call = strpos($bin, '->run(');
        // Last occurrence: the first is the flag's initialisation above the
        // registration; the one that matters is the clear after the call.
        $disarm = strrpos($bin, '$terminalWasRun = false;');

        $this->assertIsInt($register);
        $this->assertIsInt($arm);
        $this->assertIsInt($call);
        $this->assertIsInt($disarm);
        $this->assertLessThan($arm, $register, 'the guard must be registered before the run it covers');
        $this->assertLessThan($call, $arm, 'the flag must be set before the call — a throw inside run() has to find it armed');
        $this->assertLessThan($disarm, $call, 'and cleared after it returns, when Program has torn the terminal down itself');
    }

    public function testTheRecoverySequenceCarriesEveryStateTheProgramToggles(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-code');

        // The guard body is the slice between its registration and the flag
        // arming — pin the idempotent-off set inside exactly that slice, not
        // anywhere in the file, so relocating the existing normal-path pop
        // cannot satisfy it vacuously.
        $start = (int) strpos($bin, 'register_shutdown_function');
        $end = (int) strpos($bin, '$terminalWasRun = true;');
        $this->assertGreaterThan($start, $end);
        $guard = substr($bin, $start, $end - $start);

        foreach ([
            'Ansi::popKittyKeyboard()',
            'Ansi::mouseCellMotionOff()',
            'Ansi::mouseAllMotionOff()',
            'Ansi::bracketedPasteOff()',
            'Ansi::unicodeOff()',
            'Ansi::cursorShow()',
            'Ansi::altScreenLeave()',
        ] as $sequence) {
            $this->assertStringContainsString($sequence, $guard, "recovery must emit {$sequence}");
        }

        $this->assertStringContainsString('if (!$terminalWasRun)', $guard, 'a clean exit must stay byte-silent');
    }

    // =========================================================================
    // Harness (modeled on the Integration dispatch suite's, kept local)
    // =========================================================================

    /**
     * Launch bin with stdin AND stdout as pipes — no tty anywhere — and a
     * bounded drain. Reap-first via proc_close per the house rule that a
     * single-shot proc_get_status after pipe EOF is racy.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{0: string, 1: string, 2: int}
     */
    private function runBin(array $args, array $env, bool $keepStdinOpen = false): array
    {
        $root = \dirname(__DIR__, 2);
        $command = 'exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/bin/candy-code');
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open($command, $descriptors, $pipes, $root, $this->minimalEnv($env));
        $this->assertIsResource($process, 'failed to spawn bin/candy-code');

        // Default: close stdin right away. The child's exit then gives the
        // stdout/stderr pipes their EOF and the drain below terminates; an
        // OPEN, EMPTY stdin is a hang on the `-p` path even at master (the
        // shell-out backend waits for its input to settle), so a test that
        // leaves it open can only pass once the guard has already exited the
        // child. `keepStdinOpen` is exactly that probe: rv-qa-1's shape is
        // `cat | candy-code`, stdin a live pipe that never EOFs, and the
        // guard must fire BEFORE the process would ever touch it.
        if (!$keepStdinOpen) {
            \fclose($pipes[0]);
            unset($pipes[0]);
        }
        $stdout = '';
        $stderr = '';
        $deadline = \microtime(true) + self::TIMEOUT_SECONDS;
        $exitCode = null;

        while (true) {
            $stdout .= (string) \stream_get_contents($pipes[1]);
            $stderr .= (string) \stream_get_contents($pipes[2]);

            if (\microtime(true) > $deadline) {
                if (isset($pipes[0]) && \get_resource_type($pipes[0]) !== 'Unknown') {
                    @\fclose($pipes[0]);
                }
                foreach ([1, 2] as $fd) {
                    if (\get_resource_type($pipes[$fd]) !== 'Unknown') {
                        @\fclose($pipes[$fd]);
                    }
                }
                $this->fail('bin/candy-code did not exit within ' . self::TIMEOUT_SECONDS . 's — the no-tty guard regressed: ' . $stdout);
            }

            $status = \proc_get_status($process);
            if ($status['running'] === false) {
                // Reaped by proc_get_status? No — it never reaps; drain once
                // more, then proc_close below does the reaping.
                break;
            }

            \usleep(20000);
        }

        $stdout .= (string) \stream_get_contents($pipes[1]);
        $stderr .= (string) \stream_get_contents($pipes[2]);
        foreach ($pipes as $fd) {
            if (\get_resource_type($fd) !== 'Unknown') {
                @\fclose($fd);
            }
        }

        $exitCode = \proc_close($process);

        return [$stdout, $stderr, $exitCode];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function minimalEnv(array $overrides): array
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_tty_guard_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        return \array_merge(
            [
                'PATH' => (string) (\getenv('PATH') ?: '/usr/bin:/bin'),
                'HOME' => $this->tempHome,
                'TMPDIR' => (string) (\getenv('TMPDIR') ?: \sys_get_temp_dir()),
            ],
            $overrides,
        );
    }
}
