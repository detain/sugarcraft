<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private static string $phpBinary;
    private static string $binPath;

    public static function setUpBeforeClass(): void
    {
        self::$phpBinary = PHP_BINARY;
        self::$binPath = dirname(__DIR__) . '/bin/candyfreeze';
        if (!is_executable(self::$binPath) && !file_exists(self::$binPath)) {
            throw new \RuntimeException('CLI bin not found: ' . self::$binPath);
        }
    }

    private function runCli(array $args, ?string $stdin = null): array
    {
        $cmd = [self::$phpBinary, self::$binPath, ...$args];

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $handle = proc_open($cmd, $desc, $pipes);

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($handle);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
    }

    public function testHelpExitsZeroAndPrintsToStdout(): void
    {
        $result = $this->runCli(['--help']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('candyfreeze', $result['stdout']);
        $this->assertStringContainsString('--theme', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testStdinHappyPath(): void
    {
        $result = $this->runCli(['--no-window', '--no-shadow'], "hello\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('<?xml', $result['stdout']);
        $this->assertStringContainsString('<svg', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testFileInput(): void
    {
        $tmp = sys_get_temp_dir() . '/candyfreeze_test_' . uniqid() . '.txt';
        file_put_contents($tmp, "world\n");

        try {
            $result = $this->runCli(['--no-window', '--no-shadow', $tmp]);

            $this->assertSame(0, $result['exitCode']);
            $this->assertStringStartsWith('<?xml', $result['stdout']);
            $this->assertStringContainsString('<svg', $result['stdout']);
            $this->assertSame('', $result['stderr']);
        } finally {
            unlink($tmp);
        }
    }

    public function testUnknownFlagExitsTwo(): void
    {
        $result = $this->runCli(['--bogus']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('unrecognised flag', $result['stderr']);
    }

    public function testUnknownThemeExitsTwo(): void
    {
        $result = $this->runCli(['--theme', 'zzz']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('unknown theme', $result['stderr']);
    }

    public function testOutputWritesFileAndNotStdout(): void
    {
        $tmp = sys_get_temp_dir() . '/candyfreeze_out_' . uniqid() . '.svg';

        try {
            $result = $this->runCli(['--no-window', '--no-shadow', '-o', $tmp], "test\n");

            $this->assertSame(0, $result['exitCode']);
            $this->assertSame('', $result['stdout']);
            $this->assertFileExists($tmp);
            $content = file_get_contents($tmp);
            $this->assertStringStartsWith('<?xml', $content);
            $this->assertStringContainsString('<svg', $content);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testWriteFailureExitsOne(): void
    {
        $result = $this->runCli(['--no-window', '--no-shadow', '-o', '/nonexistent-dir-xyz/candyfreeze.svg'], "test\n");

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('failed to write', $result['stderr']);
    }

    public function testWindowStyleNoneProducesWindowlessSvg(): void
    {
        $result = $this->runCli(['--window-style', 'none', '--no-shadow'], "x\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('<?xml', $result['stdout']);
        $this->assertSame(0, substr_count($result['stdout'], '<circle'));
    }

    public function testLigaturesEmitsLigatureAttribute(): void
    {
        $result = $this->runCli(['--ligatures', '--no-window', '--no-shadow'], "x\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('font-variant-ligatures="normal"', $result['stdout']);
    }
}
