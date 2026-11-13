<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ShellInvocation;
use SugarCraft\Code\Support\WindowsShell;

final class ShellInvocationTest extends TestCase
{
    protected function tearDown(): void
    {
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
    }

    public function testPosixDefaultsToBinSh(): void
    {
        WindowsShell::setOsFamilyForTesting('Linux');

        self::assertSame(['/bin/sh', '-c', 'echo hi'], ShellInvocation::argv('echo hi'));
    }

    public function testPosixCanAskForBash(): void
    {
        WindowsShell::setOsFamilyForTesting('Linux');

        self::assertSame(['bash', '-c', 'x'], ShellInvocation::argv('x', true));
    }

    public function testWindowsWithoutBashIsNull(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => null);

        self::assertNull(ShellInvocation::argv('x'));
    }

    public function testWindowsWithBashOnPathUsesIt(): void
    {
        $dir = sys_get_temp_dir() . '/shinv-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $bash = $dir . DIRECTORY_SEPARATOR . 'bash.exe';
        touch($bash);
        try {
            WindowsShell::setOsFamilyForTesting('Windows');
            WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => match ($name) {
                'PATH', 'Path' => $dir,
                'PATHEXT' => '.exe',
                default => null,
            });

            $argv = ShellInvocation::argv('echo');

            self::assertNotNull($argv);
            self::assertSame(['-c', 'echo'], array_slice($argv, 1));
            self::assertStringEndsWith('bash.exe', $argv[0]);
        } finally {
            @unlink($bash);
            @rmdir($dir);
        }
    }

    public function testRefusalIsTheWindowsNotice(): void
    {
        self::assertSame(WindowsShell::BASH_MISSING_NOTICE, ShellInvocation::refusal());
    }

    public function testRefusedRunIsA127ResultCarryingTheNoticeOnStderr(): void
    {
        $run = ShellInvocation::refusedRun();

        self::assertSame(127, $run['exitCode']);
        self::assertSame(WindowsShell::BASH_MISSING_NOTICE, $run['stderr']);
        self::assertSame('', $run['stdout']);
        self::assertFalse($run['timedOut']);
        self::assertFalse($run['groupKilled']);
        self::assertSame(0, $run['truncatedBytes']);
    }
}
