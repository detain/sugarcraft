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

        // scriptui-53: the advertised allowed-set is captured from the real
        // bin's STDERR — the user-visible sentence IS the contract now, so a
        // drifted grep needle cannot silently desync from it.
        [$childStatus, $stdout, $stderr] = self::runBinChild(['style', '--border=fancy', 'hi']);
        self::assertSame(1, $childStatus);
        self::assertSame('', $stdout);
        self::assertStringContainsString(
            "Invalid value for --border: 'fancy'. Allowed: normal|rounded|thick|double|block|hidden.",
            $stderr,
        );
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

        // scriptui-53: the door is now proven by execution, not by grepping
        // the catch/fwrite spelling. Unguarded, the InvalidArgumentException
        // escapes to PHP's fatal handler: the child would die with 255 and a
        // stack frame on stderr. The door must answer exit 1 with the
        // translated refusal on captured STDERR and captured STDOUT clean.
        [$childStatus, $stdout, $stderr] = self::runBinChild(['style', '--foreground=banana', 'hi']);
        self::assertSame(1, $childStatus, 'a guarded refusal exits 1, never dies in the renderer');
        self::assertSame('', $stdout);
        self::assertStringContainsString('banana', $stderr, 'the refusal names the rejected value');
        self::assertStringNotContainsString('.php line', $stderr,
            'an unguarded throw reaches Symfony\'s renderer, which frames the fault with "In <file>.php line N:"');
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

    /** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
    private static function runBinChild(array $args, string $stdin = ''): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname($bin),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'the bin child must spawn');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }
}
