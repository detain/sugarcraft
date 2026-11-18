<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Theme::detect() on a real terminal under TERM=dumb must answer
 * NoTty, so a no-theme presenter writes no SGR. It used ColorProfile::detect(),
 * which answers Ascii there (attributes kept), so `\x1b[1m` reached the dumb
 * terminal as raw bytes.
 *
 * The tty branch cannot be reached from a non-tty stream, so the probe runs
 * under util-linux `script`, which gives the child a pseudo-terminal. The
 * suite skips where that is not available.
 */
final class DumbTerminalVetoTest extends TestCase
{
    /** Wall-clock budget for the pty child; a wedged probe dies rather than hangs. */
    private const WALL_CLOCK_BUDGET_SECONDS = 20;

    private const SCRIPT_BINARY = '/usr/bin/script';

    private const ENV_KEYS = ['NO_COLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'CLICOLOR'];

    public function testDumbTerminalReceivesNoEscapeBytes(): void
    {
        $output = $this->runOnPseudoTerminal(['TERM' => 'dumb']);

        self::assertStringContainsString('done', $output);
        self::assertStringNotContainsString("\x1b[", $output, 'TERM=dumb on a terminal must receive no SGR');
    }

    public function testCapableTerminalStillReceivesSgr(): void
    {
        // The veto is for dumb terminals only; a styling terminal keeps its colour and bold.
        $output = $this->runOnPseudoTerminal(['TERM' => 'xterm-256color']);

        self::assertStringContainsString("\x1b[", $output);
    }

    /**
     * Run the probe on a pseudo-terminal and return what it printed.
     *
     * @param array<string, string> $extraEnv
     */
    private function runOnPseudoTerminal(array $extraEnv): string
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable(self::SCRIPT_BINARY)) {
            self::markTestSkipped('needs util-linux script(1) to allocate a pseudo-terminal');
        }

        $probe = __DIR__ . '/Support/pty-status-probe.php';
        $command = implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $probe]));

        $env = getenv();
        foreach (self::ENV_KEYS as $k) {
            unset($env[$k]);
        }
        $env = $extraEnv + $env;

        $process = proc_open(
            [
                'timeout', '-s', 'KILL', (string) self::WALL_CLOCK_BUDGET_SECONDS,
                self::SCRIPT_BINARY, '-qec', $command, '/dev/null',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process, 'could not start script(1)');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "pty probe failed: {$stderr}");
        self::assertIsString($stdout);

        return $stdout;
    }
}
