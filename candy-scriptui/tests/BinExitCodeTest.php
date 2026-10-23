<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end exit-code probes against the real bin script (docs-fix-1023
 * C1/C2/C3).
 *
 * Before the fix, bin/candy-scriptui discarded Application::run()'s int, so
 * EVERY failure left the process at exit 0 — shell scripts like
 * examples/setup-script.sh gated on a number that never moved, and
 * `--version` / bare invocation died inside doRun (masked by the same
 * swallowed code). These children run the shipped bin verbatim; the
 * assertions below are exactly the shapes the README promises.
 *
 * The children are wall-clock bounded via `timeout -s KILL` and drained
 * before proc_close, so a regression that HANGS the CLI fails loudly
 * (137) instead of wedging the suite.
 */
final class BinExitCodeTest extends TestCase
{
    private const BIN     = __DIR__ . '/../bin/candy-scriptui';
    private const BUDGET  = 20;

    /** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
    private static function runBin(array $args): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // The wall-clock bound uses coreutils `timeout`; Windows
            // runners skip rather than risk an unbounded child.
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $cmd = array_merge(
            ['timeout', '-s', 'KILL', (string) self::BUDGET, PHP_BINARY, self::BIN],
            $args,
        );
        $env = [
            // Deliberately minimal: no LANG/LC_* so the child resolves the
            // documented 'en' fallback; no CANDY_SCRIPTUI_* so ambient env
            // can never inject option values into the probe.
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, \dirname(self::BIN), $env);

        self::assertIsResource($proc, 'the bin child must spawn');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        return [$status, $stdout, $stderr];
    }

    public function testUnknownCommandExitsNonZero(): void
    {
        [$status, $stdout, $stderr] = self::runBin(['nosuchcommand']);

        self::assertSame(1, $status, 'unknown command must exit 1 (before the bin fix the process always exited 0)');
        self::assertStringContainsString('is not defined', $stdout . $stderr);
    }

    public function testVersionFlagExitsZeroAndNamesTheApp(): void
    {
        [$status, $stdout, $stderr] = self::runBin(['--version']);

        self::assertSame(0, $status, '--version must succeed, not die in command lookup');
        self::assertStringContainsString('candy-scriptui', $stdout . $stderr);
    }

    public function testBareInvocationListsCommandsAndExitsZero(): void
    {
        [$status, $stdout, $stderr] = self::runBin([]);

        self::assertSame(0, $status, 'bare invocation must show the list, not crash');
        self::assertStringContainsString('choose', $stdout . $stderr);
    }

    public function testPagerMissingFileFailsLocalizedNotFatal(): void
    {
        $missing = '/definitely/not/here-' . getmypid() . '.txt';
        [$status, $stdout, $stderr] = self::runBin(['pager', '--file=' . $missing]);

        // Before the `use ...Lang;` import fix this path fataled with
        // "Class 'SugarCraft\ScriptUi\Command\Lang' not found" at exit 255.
        self::assertSame(1, $status, 'unreadable --file must exit FAILURE');
        self::assertStringContainsString('could not read file: ' . $missing, $stdout . $stderr);
        self::assertStringNotContainsString('Class "', $stdout . $stderr);
        self::assertStringNotContainsString('Fatal error', $stdout . $stderr);
    }

    public function testRefusedFlagValueIsForwardedAsExitCode(): void
    {
        [$status, $stdout, $stderr] = self::runBin(['confirm', '--default=maybe', 'x']);

        self::assertSame(1, $status, 'a refusal inside a command must reach the shell');
        self::assertStringContainsString('unknown --default value: maybe', $stdout . $stderr);
    }

    public function testShowHelpReachesTheTerminalThroughTheBin(): void
    {
        [$status, $stdout, $stderr] = self::runBin(['style', '--show-help']);

        self::assertSame(0, $status, '--show-help must render help, not run the command');
        self::assertStringContainsString('Usage:', $stdout . $stderr);
    }
}
