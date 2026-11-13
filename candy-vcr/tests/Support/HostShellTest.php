<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Support\HostShell;

final class HostShellTest extends TestCase
{
    public function testInteractiveArgsFitTheShellFamily(): void
    {
        self::assertSame([], HostShell::interactiveArgs('C:\\Windows\\System32\\cmd.exe', login: true), 'cmd.exe takes no -i/-l');
        self::assertSame(['-NoLogo', '-NoProfile'], HostShell::interactiveArgs('pwsh.exe', login: false));
        self::assertSame(['-NoLogo'], HostShell::interactiveArgs('powershell', login: true));
        self::assertSame(['-l'], HostShell::interactiveArgs('/bin/bash', login: true));
        self::assertSame(['-i'], HostShell::interactiveArgs('C:/Program Files/Git/usr/bin/sh.EXE', login: false));
    }

    public function testFallbackIsTheHostDefaultShell(): void
    {
        if (HostShell::onWindows()) {
            self::assertSame((string) (\getenv('ComSpec') ?: 'cmd.exe'), HostShell::fallback());
        } else {
            self::assertSame('/bin/sh', HostShell::fallback());
        }
    }

    public function testIsAbsoluteKnowsTheHostSpellings(): void
    {
        self::assertTrue(HostShell::isAbsolute('/bin/sh'));
        self::assertFalse(HostShell::isAbsolute('sh'));
        self::assertSame(HostShell::onWindows(), HostShell::isAbsolute('C:\\Windows\\System32\\cmd.exe'));
        self::assertSame(HostShell::onWindows(), HostShell::isAbsolute('\\\\server\\share\\sh.exe'));
    }

    public function testLocateFindsThePhpBinaryByName(): void
    {
        $dir = \dirname(\PHP_BINARY);
        $previous = \getenv('PATH');
        \putenv('PATH=' . $dir);
        try {
            $found = HostShell::locate('php');
        } finally {
            \putenv($previous === false ? 'PATH' : 'PATH=' . $previous);
        }
        self::assertNotNull($found);
        self::assertSame(\realpath(\PHP_BINARY), \realpath((string) $found));
        self::assertNull(HostShell::locate(''));
    }

    public function testIsExecutableAndWindowsSystemEnv(): void
    {
        self::assertTrue(HostShell::isExecutable(\PHP_BINARY));
        self::assertFalse(HostShell::isExecutable(__FILE__ . '.missing'));

        $env = HostShell::windowsSystemEnv();
        if (HostShell::onWindows()) {
            self::assertArrayHasKey('SystemRoot', $env, 'a Windows child cannot start without SystemRoot');
        } else {
            self::assertSame([], $env);
        }
    }
}
