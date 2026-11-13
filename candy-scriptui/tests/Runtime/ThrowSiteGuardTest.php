<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\LogCommand;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Command\TableCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The unknown-value family must refuse with the allowed set on STDERR
 * (exit 1) instead of surfacing an exception frame or the old silent
 * paths. The static helpers keep their InvalidArgumentException contract
 — pinned elsewhere; these tests own the CLI surface.
 */
final class ThrowSiteGuardTest extends TestCase
{
    public function testUnknownSpinnerStyleIsRefusedWithAllowedList(): void
    {
        $tester = new CommandTester((new Application())->find('spin'));
        $status = $tester->execute(['--style' => 'whirl', 'argv' => ['true']]);

        self::assertSame(1, $status);
        self::assertSame('', $tester->getDisplay());
    }

    public function testUnknownTableBorderIsRefusedWithAllowedList(): void
    {
        $file = \sys_get_temp_dir() . '/suib-' . \getmypid() . '-' . \uniqid('b', false) . '.csv';
        \file_put_contents($file, "a,b\n");
        try {
            $tester = new CommandTester((new Application())->find('table'));
            $status = $tester->execute(['--file' => $file, '--border' => 'zigzag']);
            self::assertSame(1, $status);
            self::assertSame('', $tester->getDisplay());
        } finally {
            @\unlink($file);
        }
    }

    public function testUnknownLogLevelsAreRefusedWithAllowedList(): void
    {
        foreach (['--level', '--min-level'] as $flag) {
            $tester = new CommandTester((new Application())->find('log'));
            $status = $tester->execute(['message' => 'hi', $flag => 'catastrophic']);
            self::assertSame(1, $status, $flag . ' must refuse');
            self::assertSame('', $tester->getDisplay());
        }
    }

    public function testDeclaredSpellingsCoverEveryStaticResolutionArm(): void
    {
        // The refusal advertises a list; the guard set must equal what
        // the statics actually resolve, or valid spellings would break.
        foreach (SpinCommand::STYLE_SPELLINGS as $name) {
            self::assertInstanceOf(
                \SugarCraft\Forms\Spinner\Style::class,
                SpinCommand::pickStyle($name),
            );
        }
        foreach (TableCommand::BORDER_SPELLINGS as $name) {
            try {
                TableCommand::parseBorder($name); // must resolve (or null for none/off)
            } catch (\InvalidArgumentException $failure) {
                self::fail('border spelling ' . $name . ' is guarded but unresolvable');
            }
        }
        foreach (LogCommand::LEVEL_SPELLINGS as $name) {
            self::assertInstanceOf(
                \SugarCraft\ScriptUi\Log\LogLevel::class,
                \SugarCraft\ScriptUi\Log\LogLevel::fromString($name),
            );
        }
    }

    public function testGuardsRouteThroughTheSharedRefusalShape(): void
    {
        // scriptui-53: the shared refusal shape is executed, not grepped —
        // each guarded flag's bad value must produce the runtime.unknown_choice
        // sentence on the real bin's captured STDERR with captured STDOUT
        // clean (the old arms compared Lang::t call text across three files).
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        /** @var array<string, array{0: list<string>, 1: string, 2: string}> $doors */
        $doors = [
            'spin'  => [['spin', '--style=whirl', '--', '/bin/true'], "Invalid value for --style: 'whirl'. Allowed: line|dot", ''],
            'table' => [['table', '--border=zorg'], "Invalid value for --border: 'zorg'. Allowed: none|normal", "a,b\n"],
            'log'   => [['log', '--level=whisper', 'hello'], "Invalid value for --level: 'whisper'. Allowed: debug|info", ''],
        ];
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        foreach ($doors as $label => [$args, $needle, $stdin]) {
            $proc = proc_open(
                ['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin, ...$args],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                \dirname($bin),
                ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
            );
            self::assertIsResource($proc, $label . ': the bin child must spawn');
            if ($stdin !== '') {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);

            self::assertSame(1, $status, $label . ' must refuse the unlisted value with FAILURE');
            self::assertSame('', $stdout, $label . ': the refusal is diagnostics, never the data stream');
            self::assertStringContainsString($needle, $stderr,
                $label . ' must answer with the shared runtime.unknown_choice sentence');
        }
    }
}
