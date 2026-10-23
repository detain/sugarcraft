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

        $process = proc_open(
            [\PHP_BINARY, $binary, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
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
        [$exit, $stdout, $stderr] = $this->runBinary([], "q\n");
        self::assertSame(0, $exit, 'the scripted q must quit the game cleanly, got stderr: ' . $stderr);
        self::assertStringContainsString("\x1b[?1049h", $stdout, 'the game must have entered the alternate screen');
        self::assertStringNotContainsString('COMPUTER', $stdout, 'single player must not boot the VS renderer');
    }

    public function testDocumentedVsFlagsBootTheComputerOpponentEngine(): void
    {
        foreach (['-v', '--vs'] as $flag) {
            [$exit, $stdout] = $this->runBinary([$flag], "q\n");
            self::assertSame(0, $exit, "candy-tetris {$flag} must boot and quit cleanly");
            self::assertStringContainsString("\x1b[?1049h", $stdout, "candy-tetris {$flag} must enter the alternate screen");
            self::assertStringContainsString('COMPUTER', $stdout, "candy-tetris {$flag} must render the VS board with the COMPUTER opponent");
        }
    }

}
