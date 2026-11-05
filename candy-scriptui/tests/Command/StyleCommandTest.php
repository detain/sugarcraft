<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
        // preg_replace: Windows CI normalises trailing line endings to CRLF
        // (\r\n); normalise to Unix format (\n) to match expected output.
        $display = preg_replace('/\r?\n$/', "\n", $tester->getDisplay());
        $this->assertSame("hello\n", $display);
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
        // preg_replace: Windows CI normalises trailing line endings to CRLF.
        $display = preg_replace('/\r?\n$/', "\n", $tester->getDisplay());
        $this->assertSame("\x1b[1mhello\x1b[0m\n", $display);
    }

    public function testUnknownBorderIsRefusedWithTheAllowedSet(): void
    {
        $app = new Application();
        $tester = new CommandTester($app->find('style'));
        $status = $tester->execute(['text' => ['hi'], '--border' => 'fancy']);

        self::assertSame(1, $status, 'an unlisted border preset must fail, not render unbordered');
        self::assertSame('', $tester->getDisplay(), 'the refusal is diagnostics: never the data stream');

        $src = (string) \file_get_contents(__DIR__ . '/../../src/Command/StyleCommand.php');
        self::assertStringContainsString("'allowed' => 'normal|rounded|thick|double|block|hidden'", $src);
    }

    /**
     * m12 (step 8 code leg): StyleBuilder fails closed on unparseable
     * flag values with InvalidArgumentException. Unguarded, a bad
     * --foreground surfaced as an uncaught trace; the refusal door must
     * mirror the --border one above — STDERR diagnostics, empty data
     * stream, exit FAILURE.
     */
    public function testUnparseableForegroundIsRefusedNotFatal(): void
    {
        $app = new Application();
        $tester = new CommandTester($app->find('style'));
        $status = $tester->execute(['text' => ['hi'], '--foreground' => 'banana']);

        self::assertSame(1, $status, 'an unparseable colour must fail like the border door, not crash');
        self::assertSame('', $tester->getDisplay(), 'the refusal is diagnostics: never the data stream');

        $src = (string) \file_get_contents(__DIR__ . '/../../src/Command/StyleCommand.php');
        self::assertStringContainsString('catch (\InvalidArgumentException $e)', $src, 'the StyleBuilder door must be guarded in src');
        self::assertStringContainsString('fwrite(STDERR, $e->getMessage()', $src, 'the translated refusal rides STDERR, like the border door');
    }

    public function testUnparseablePaddingIsRefusedNotFatal(): void
    {
        $app = new Application();
        $tester = new CommandTester($app->find('style'));
        $status = $tester->execute(['text' => ['hi'], '--padding' => 'abc']);

        self::assertSame(1, $status, 'a non-numeric padding token must refuse, not crash');
        self::assertSame('', $tester->getDisplay(), 'the refusal is diagnostics: never the data stream');
    }

    /**
     * The env-armed attack shape: `foreground` IS allow-listed (a
     * presentation knob), so CANDY_SCRIPTUI_FOREGROUND=banana reaches the
     * same parser through the token injection — it must ride the refusal
     * door, not the Symfony exception renderer.
     */
    public function testEnvironmentArmedBadColourIsRefusedNotFatal(): void
    {
        $saved = getenv('CANDY_SCRIPTUI_FOREGROUND');
        putenv('CANDY_SCRIPTUI_FOREGROUND=banana');
        $output = new BufferedOutput();
        try {
            $status = (new Application())->run(new ArgvInput(['candy-scriptui', 'style', 'hi']), $output);
            self::assertSame(1, $status, 'an env-armed bad colour must exit FAILURE, not fatal');
            // Unguarded, the exception rides Symfony's top-level renderer
            // and the caller's output stream fills with a formatted error
            // block ("In StyleBuilder.php line N:"). The door keeps the
            // stream empty: the refusal goes to real STDERR as
            // diagnostics, exactly like the --border door.
            self::assertSame('', $output->fetch(),
                'an env-armed refusal must ride the door, not the exception renderer');
        } finally {
            putenv(false === $saved ? 'CANDY_SCRIPTUI_FOREGROUND' : 'CANDY_SCRIPTUI_FOREGROUND=' . $saved);
        }
    }
}
