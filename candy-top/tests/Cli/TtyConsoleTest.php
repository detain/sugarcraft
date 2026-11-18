<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Cli\TtyConsole;

/**
 * Pins the console test that gates btop's tty-mode auto-enable. Runs on any
 * host: the OS family is an argument, so the macOS branch is covered on Linux.
 */
final class TtyConsoleTest extends TestCase
{
    /** @return iterable<string, array{?string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'Linux VT console' => ['/dev/tty1', 'Linux', true];
        yield 'Linux serial console' => ['/dev/ttyS0', 'Linux', true];
        yield 'Linux pty session' => ['/dev/pts/3', 'Linux', false];
        yield 'macOS terminal session is not a console' => ['/dev/ttys003', 'Darwin', false];
        yield 'macOS with an unknown name' => ['', 'Darwin', false];
        yield 'unknown name on Linux' => ['', 'Linux', false];
        yield 'no ext-posix on Linux trusts the isatty gate' => [null, 'Linux', true];
        yield 'no ext-posix on Windows stays false' => [null, 'Windows', false];
    }

    #[DataProvider('cases')]
    public function testIsConsole(?string $ttyName, string $osFamily, bool $expected): void
    {
        $this->assertSame($expected, TtyConsole::isConsole($ttyName, $osFamily));
    }
}
