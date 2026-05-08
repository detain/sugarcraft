<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StyleCommandTest extends TestCase
{
    public function testStripAnsiClearsInlineEscapes(): void
    {
        $cmd = (new Application())->find('style');
        $tester = new CommandTester($cmd);
        $tester->execute([
            'text'         => ["\x1b[31mhello\x1b[0m"],
            '--strip-ansi' => true,
        ]);
        $tester->assertCommandIsSuccessful();
        // Output should have no SGR escapes (the input ones were
        // stripped, no new styling was requested).
        // rtrim: Windows CI normalises output line endings to CRLF.
        $this->assertSame("hello\n", rtrim($tester->getDisplay(), "\r\n"));
    }

    public function testStripAnsiCombinesWithStyling(): void
    {
        $cmd = (new Application())->find('style');
        $tester = new CommandTester($cmd);
        $tester->execute([
            'text'         => ["\x1b[31mhello\x1b[0m"],
            '--bold'       => true,
            '--strip-ansi' => true,
        ]);
        $tester->assertCommandIsSuccessful();
        // The pre-existing red was stripped; the new bold survived.
        // rtrim: Windows CI normalises output line endings to CRLF.
        $this->assertSame("\x1b[1mhello\x1b[0m\n", rtrim($tester->getDisplay(), "\r\n"));
    }
}
