<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * The shipped examples must stay executable, not drift into fiction:
 * docs-review found several that could no longer run. Each case drives a
 * REAL example file under timeout(1), which is itself the bounded
 * teardown — TERM at the deadline, KILL two seconds after — so a hung
 * example can never outlive the test, and the suite's HangWatchdog
 * stays the last resort rather than the first responder.
 *
 * Only self-terminating examples belong here: pump-output counts to N
 * and exits; multi-pump exits once every scripted command finished.
 * The interactive ones (spawn-bash, resize-forwarding) stay manual.
 */
final class ExamplesExecTest extends TestCase
{
    /** Per-example wall budget; both demos self-finish in ~1-3 s. */
    private const TIMEOUT_SECONDS = 20;

    public function testPumpOutputExampleRunsToCompletion(): void
    {
        [$exit, $out] = $this->runExample('pump-output.php', ['2']);

        self::assertSame(0, $exit, "example exited {$exit}; output:\n" . $out);
        self::assertStringContainsString('done —', $out);
        self::assertStringContainsString('lines pumped', $out);
    }

    public function testMultiPumpExampleRunsCustomCommandsToCompletion(): void
    {
        [$exit, $out] = $this->runExample('multi-pump.php', ['echo one-off-A', 'echo one-off-B']);

        self::assertSame(0, $exit, "example exited {$exit}; output:\n" . $out);
        self::assertStringContainsString('MultiPump done', $out);
        self::assertStringContainsString('one-off-A', $out);
        self::assertStringContainsString('one-off-B', $out);
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string} [exit code, captured stdout+stderr]
     */
    private function runExample(string $file, array $args): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty examples are POSIX.');
        }
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('No usable PTY layer on this host (FFI or /dev/ptmx unavailable).');
        }

        $path = \dirname(__DIR__) . '/examples/' . $file;
        self::assertFileExists($path);

        $capture = \tempnam(\sys_get_temp_dir(), 'sugar-pty-example-');
        self::assertIsString($capture);

        $handle = \fopen($capture, 'w');
        self::assertIsResource($handle);

        $process = \proc_open(
            [
                'timeout', '--foreground', '-k', '2',
                (string) self::TIMEOUT_SECONDS,
                \PHP_BINARY, $path,
                ...$args,
            ],
            [1 => $handle, 2 => $handle],
            $pipes,
        );
        \fclose($handle);
        self::assertIsResource($process, 'proc_open refused the timeout wrapper');

        $status = \proc_close($process);
        $output = (string) \file_get_contents($capture);
        @\unlink($capture);

        return [$status, $output];
    }
}
