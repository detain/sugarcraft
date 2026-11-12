<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\WindowsShell;

/**
 * Cheap, never-dormant Windows smoke: the binary boots through the same
 * `php bin/candy-code` invocation a Windows user's shell makes, and the bash
 * resolver answers with either a real bash.exe or the install notice. No
 * skip gate: this runs on every host, so a boot-time fatal is caught on any
 * platform the suite touches, not only on the windows-smoke runner.
 */
final class WindowsBootSmokeTest extends TestCase
{
    public function testTheBinaryBootsAndReportsItsVersion(): void
    {
        $bin = \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'candy-code';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' --version';

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'proc_open refused to start the binary');
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "--version exited {$exit}: {$stderr}");
        self::assertStringStartsWith('candy-code', trim($stdout));
    }

    public function testTheShellResolverAnswersWithABashPathOrTheInstallNotice(): void
    {
        $found = WindowsShell::bash();

        if ($found !== '') {
            self::assertFileExists($found, 'the resolver named a bash.exe that is not on disk');
            return;
        }

        self::assertStringContainsString('bash.exe', WindowsShell::BASH_MISSING_NOTICE);
        self::assertStringContainsString('Git for Windows', WindowsShell::BASH_MISSING_NOTICE);
    }
}
