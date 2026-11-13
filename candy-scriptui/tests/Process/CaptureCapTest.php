<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Process\RealProcess;

/**
 * scriptui-03 (candy-scriptui-007): captured output is bounded. A child
 * writing past the cap must grow the drop tally, never the parent's RSS
 * past CAPTURE_CAP_BYTES per slot; the head is retained verbatim and the
 * child's exit code is unaffected.
 */
final class CaptureCapTest extends TestCase
{
    private const MIB = 1048576;

    protected function setUp(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('RealProcess capture requires Unix pipes, not available on Windows.');
        }
    }

    /** @param list<string> $argv */
    private function child(array $argv, bool $capOut = true, bool $capErr = false): RealProcess
    {
        $process = RealProcess::spawn($argv, $capOut, $capErr);
        $process->wait();
        return $process;
    }

    /** @param list<string> $argv */
    private function php(array $code): array
    {
        return [\PHP_BINARY, '-r', \implode('', $code)];
    }

    public function testHeadRetainedTailCounted(): void
    {
        // 9 MiB of 'A' then 2 MiB of 'B' — only the first 8 MiB (all A) is retained.
        $process = $this->child($this->php([
            'fwrite(STDOUT, str_repeat("A", 9 * ' . self::MIB . '));',
            'fwrite(STDOUT, str_repeat("B", 2 * ' . self::MIB . '));',
        ]));

        $retained = $process->stdoutBytes();
        $this->assertSame(
            \str_repeat('A', RealProcess::CAPTURE_CAP_BYTES),
            $retained,
            'the retained capture must be exactly the head up to the cap'
        );
        $this->assertSame(
            3 * self::MIB,
            $process->stdoutDropped(),
            '1 MiB of A tail plus 2 MiB of B were dropped, not kept'
        );
        $this->assertSame(0, $process->exitCode(), 'truncation must never touch the child exit code');
    }

    public function testOutputUnderCapIsWhole(): void
    {
        $process = $this->child($this->php([
            'fwrite(STDOUT, "hello");',
        ]));

        $this->assertSame('hello', $process->stdoutBytes());
        $this->assertSame(0, $process->stdoutDropped());
    }

    public function testSlotsAreCappedIndependently(): void
    {
        // A chatty stderr slot must not starve the stdout slot's cap.
        $process = $this->child(
            $this->php([
                'fwrite(STDERR, str_repeat("E", 9 * ' . self::MIB . '));',
                'fwrite(STDOUT, "ok");',
            ]),
            capOut: true,
            capErr: true,
        );

        $this->assertSame('ok', $process->stdoutBytes());
        $this->assertSame(0, $process->stdoutDropped());
        $this->assertSame(
            \str_repeat('E', RealProcess::CAPTURE_CAP_BYTES),
            $process->stderrBytes()
        );
        $this->assertSame(self::MIB, $process->stderrDropped());
    }

    public function testSpinCommandRidesTheDropTallyWithOneNotice(): void
    {
        // Source-shape pin (the CommandSignalAndCursorWiringTest family):
        // both slots' tallies feed exactly one `spinner.output_truncated`
        // notice line on the diagnostics stream. Deleting the notice block
        // or querying only one slot reddens this pin.
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');

        $this->assertStringContainsString('stdoutDropped()', $src);
        $this->assertStringContainsString('stderrDropped()', $src);
        $this->assertStringContainsString('spinner.output_truncated', $src);
        $this->assertSame(
            1,
            \preg_match_all('/fwrite\(STDERR, Lang::t\(\'spinner\.output_truncated\'\)/', $src),
            'exactly one notice site — not one per slot (the line must appear once)'
        );
    }
}
