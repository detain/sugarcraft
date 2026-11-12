<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end pins for `bin/candy-tetris` (docs-fix C1).
 *
 * The help/unknown-argument paths exit before any terminal-backed Program is
 * constructed, so they are probed exactly as a user invokes them. The game-boot
 * paths are probed by piping a scripted `q` keypress into the real binary: the
 * program boots, paints at least one frame, and quits cleanly. That proves the
 * documented flags reach the real engine, not just the resolver.
 *
 * Children are wall-clock bounded (the shipped child-lifetime discipline): a
 * wedged program is killed at the deadline and the assertion then fails loudly
 * instead of hanging the suite.
 */
final class BinaryLaunchTest extends TestCase
{
    private const DEADLINE_SECONDS = 10.0;

    /** @return array{0: int, 1: string, 2: string} */
    private function runBinary(array $args, string $stdinData = ''): array
    {
        $binary = realpath(__DIR__ . '/../bin/candy-tetris');
        self::assertNotFalse($binary, 'bin/candy-tetris must exist');

        // Locale-hermetic child: the help screen and the unknown-mode refusal
        // are asserted below in their English form, and every visible string
        // now resolves through lang/*. Once sixteen locales ship, a machine
        // whose LANG says otherwise would print them translated and reddens
        // this test for reasons that have nothing to do with the launch path
        // under test. Neutralising LC_ALL/LC_MESSAGES/LANG (C lands T::detect()
        // on English) while carrying the rest of the environment over keeps
        // TERM and PATH intact and the pin deterministic.
        // The force variables are dropped too: a truthy CLICOLOR_FORCE or
        // FORCE_COLOR in the developer's shell would change what the launcher
        // decides about a piped stdout.
        $environment = ['LC_ALL' => 'C', 'LC_MESSAGES' => 'C', 'LANG' => 'C']
            + array_diff_key(getenv(), ['CLICOLOR_FORCE' => 1, 'FORCE_COLOR' => 1]);
        $process = proc_open(
            [\PHP_BINARY, $binary, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            $environment,
        );
        self::assertIsResource($process, 'the binary must be spawnable');

        if ($stdinData !== '') {
            // A non-blocking write loop: a tiny pipe buffer must not wedge the
            // test while the child has not started reading yet.
            stream_set_blocking($pipes[0], false);
            $deadline = microtime(true) + self::DEADLINE_SECONDS;
            while ($stdinData !== '' && microtime(true) < $deadline) {
                $written = fwrite($pipes[0], $stdinData);
                if ($written > 0) {
                    $stdinData = substr($stdinData, $written);
                } else {
                    usleep(20_000);
                }
            }
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $exitCode = null;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                self::fail(sprintf('candy-tetris %s did not exit within %.0fs', implode(' ', $args), self::DEADLINE_SECONDS));
            }
            usleep(20_000);
        }

        // Drain anything the child wrote in its final instant.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertNotSame(-1, $exitCode, 'exit code is only meaningful after a natural reap');

        return [$exitCode, $stdout, $stderr];
    }

    public function testHelpFlagExitsZeroWithUsageScreen(): void
    {
        [$exit, $stdout] = $this->runBinary(['-h']);
        self::assertSame(0, $exit, 'candy-tetris -h must exit successfully');
        self::assertStringContainsString('Usage: candy-tetris', $stdout);
        self::assertStringContainsString('-v, --vs', $stdout);
        self::assertStringContainsString('-h, --help', $stdout);
    }

    public function testLongHelpFlagExitsZeroWithUsageScreen(): void
    {
        [$exit, $stdout] = $this->runBinary(['--help']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage: candy-tetris', $stdout);
    }

    public function testUnknownFlagExitsOneAndNamesTheOffender(): void
    {
        [$exit, $stdout, $stderr] = $this->runBinary(['--puzzle']);
        self::assertSame(1, $exit, 'a refused launch must not exit 0');
        self::assertStringNotContainsString("\x1b[?1049h", $stdout, 'the game must never boot on a refused flag');
        self::assertStringContainsString('unknown mode "--puzzle"', $stderr);
        self::assertStringContainsString('Usage: candy-tetris', $stderr, 'the refusal must show the usage screen');
    }

    public function testBareVFlagIsRefusedNotSilentlySingle(): void
    {
        [$exit, , $stderr] = $this->runBinary(['v']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('unknown mode "v"', $stderr);
    }

    public function testExtraArgumentIsRefused(): void
    {
        [$exit, , $stderr] = $this->runBinary(['-v', '-h']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('unexpected argument "-h"', $stderr);
    }

    public function testNoArgumentsBootsTheSinglePlayerEngine(): void
    {
        [$exit, $output] = $this->runUnderPty([], quitKey: 'q');
        self::assertSame(0, $exit, 'the q key must quit the game cleanly, got: ' . $this->printable($output));
        self::assertStringContainsString("\x1b[?1049h", $output, 'the game must have entered the alternate screen');
        self::assertStringNotContainsString('COMPUTER', $output, 'single player must not boot the VS renderer');
    }

    public function testDocumentedVsFlagsBootTheComputerOpponentEngine(): void
    {
        foreach (['-v', '--vs'] as $flag) {
            [$exit, $output] = $this->runUnderPty([$flag], quitKey: 'q');
            self::assertSame(0, $exit, "candy-tetris {$flag} must boot and quit cleanly");
            self::assertStringContainsString("\x1b[?1049h", $output, "candy-tetris {$flag} must enter the alternate screen");
            self::assertStringContainsString('COMPUTER', $output, "candy-tetris {$flag} must render the VS board with the COMPUTER opponent");
        }
    }

    /**
     * Piped keys used to be read as play: `echo | candy-tetris` took the
     * newline as "begin" and the gravity timer then played on forever,
     * painting the alternate screen into the pipe.
     */
    public function testPipedStdinIsRefusedWithoutEscapeBytes(): void
    {
        foreach ([[], ['-v']] as $args) {
            [$exit, $stdout, $stderr] = $this->runBinary($args, "q\n");
            $label = 'candy-tetris ' . implode(' ', $args);
            self::assertSame(1, $exit, "{$label} on a pipe must be refused with exit 1");
            self::assertSame('', $stdout, "{$label} on a pipe must write nothing to stdout");
            self::assertStringNotContainsString("\x1b", $stderr, 'the refusal is plain text');
            self::assertStringContainsString('needs an interactive terminal', $stderr);
        }
    }

    public function testClosedStdinIsRefusedInsteadOfRunningForever(): void
    {
        [$exit, $stdout, $stderr] = $this->runBinary([]);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('needs an interactive terminal', $stderr);
    }

    /**
     * Keyboard on a real terminal, screen redirected to a file: the frame
     * must not be painted into the file (ColorProfile::detect() is handed
     * STDOUT, so the redirect is seen).
     */
    public function testRedirectedStdoutUnderARealTerminalIsRefused(): void
    {
        $capture = $this->home . '/stdout.capture';
        [$exit, $output] = $this->runUnderPty([], redirectStdoutTo: $capture);
        self::assertSame(1, $exit, 'a redirected stdout must be refused, got: ' . $this->printable($output));
        self::assertSame('', (string) file_get_contents($capture), 'nothing may be painted into the redirect');
        self::assertStringContainsString('needs an interactive terminal', $output, 'the refusal goes to the terminal on stderr');
    }

    /**
     * TERM=dumb used to print the refusal through the frame painter (cursor
     * and sync-mode escapes around it) and then idle until killed.
     */
    public function testDumbTerminalIsRefusedPlainlyAndExits(): void
    {
        [$exit, $output] = $this->runUnderPty([], term: 'dumb');
        self::assertSame(1, $exit, 'TERM=dumb must be refused with exit 1, got: ' . $this->printable($output));
        self::assertStringNotContainsString("\x1b", $output, 'a dumb terminal must receive zero escape bytes');
        self::assertStringContainsString('needs an interactive terminal', $output);
    }

    private string $home = '';

    protected function setUp(): void
    {
        // A private HOME: a booted game reads and settles the cross-session
        // record, which must never be the developer's own.
        $this->home = sys_get_temp_dir() . '/candy-tetris-launch-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->home !== '' && is_dir($this->home)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->home);
        }
    }

    /**
     * Run the binary on a pty of its own via util-linux `script -qec` (the
     * candy-code BinInteractiveTtyGuardTest shape), sized 100x40 so both
     * the solo and the VS frame fit. When $quitKey is given it is typed
     * once the first frame is on screen — earlier keystrokes could be
     * flushed by the switch to raw mode — and re-sent until the child exits.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string} exit code and everything the pty showed
     */
    private function runUnderPty(
        array $args,
        ?string $quitKey = null,
        string $term = 'xterm-256color',
        ?string $redirectStdoutTo = null,
    ): array {
        $binary = realpath(__DIR__ . '/../bin/candy-tetris');
        self::assertNotFalse($binary);
        $inner = 'stty rows 40 cols 100 2>/dev/null; exec '
            . implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $binary, ...$args]))
            . ($redirectStdoutTo !== null ? ' > ' . escapeshellarg($redirectStdoutTo) : '');
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $this->home,
            'TERM' => $term,
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ];
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            $environment,
        );
        self::assertIsResource($process, 'failed to spawn script(1) — is util-linux installed?');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $nextKeyAt = null;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ($quitKey !== null && str_contains($output, "\x1b[?1049h")) {
                $nextKeyAt ??= microtime(true) + 0.3;
                if (microtime(true) >= $nextKeyAt) {
                    fwrite($pipes[0], $quitKey);
                    $nextKeyAt = microtime(true) + 1.0;
                }
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                self::fail('candy-tetris under a pty did not exit within the deadline: ' . $this->printable($output));
            }
            usleep(20_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        return [$exitCode, $output];
    }

    private function printable(string $bytes): string
    {
        return addcslashes(substr($bytes, -600), "\0..\37\177");
    }
}
