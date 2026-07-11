<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end guard tests for the bin/sugarspark CLI entry point.
 *
 * The file-path argument must accept only real local files — never a PHP
 * stream wrapper (php://, data://, http://, …) — so an attacker-supplied
 * "path" cannot be coerced into fetching a URL or evaluating a wrapper.
 */
final class CliTest extends TestCase
{
    private static function bin(): string
    {
        return dirname(__DIR__) . '/bin/sugarspark';
    }

    /**
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private static function runCli(string $arg): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::bin()) . ' '
            . escapeshellarg($arg);

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $stdout, $stderr];
    }

    public function testRejectsDataStreamWrapper(): void
    {
        // Without the is_file() guard, file_get_contents() honours data:// and
        // emits the payload as a report — the guard must refuse the wrapper.
        [$code, $stdout, $stderr] = self::runCli('data://text/plain,injected');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a readable file', $stderr);
        $this->assertStringNotContainsString('injected', $stdout);
    }

    public function testRejectsNonexistentPath(): void
    {
        [$code, , $stderr] = self::runCli('/no/such/sugarspark/input/file');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a readable file', $stderr);
    }

    public function testReadsRealLocalFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'spark');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "\x1b[31mred\x1b[0m");
        try {
            [$code, $stdout, $stderr] = self::runCli($tmp);
            $this->assertSame(0, $code, $stderr);
            $this->assertStringContainsString('red', $stdout);
        } finally {
            @unlink($tmp);
        }
    }
}
