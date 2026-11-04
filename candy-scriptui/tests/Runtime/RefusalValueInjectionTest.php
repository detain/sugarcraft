<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Lang;

/**
 * Refusal diagnostics carry attacker-shaped argv back to the operator.
 * A crafted option value must never paint the terminal (raw SGR bytes)
 * or forge extra diagnostic lines (embedded newlines) on STDERR — the
 * one stream scripts are told to read diagnostics from (REVIEW-1 M-B).
 */
final class RefusalValueInjectionTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/candy-scriptui';

    private function runInjectionChild(array $args): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX timeout(1) + env shelling required.');
        }
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(self::BIN),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()],
        );
        $this->assertIsResource($proc);
        \fclose($pipes[0]); // EOF on stdin: refusals must not depend on input.
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $stdout, $stderr];
    }

    public function testInterpolatedValuesNeverCarryLiveControlBytes(): void
    {
        $message = Lang::t('runtime.unknown_choice', [
            'option'  => '--suit',
            'value'   => "red\x1b[31mR\nforged line",
            'allowed' => 'hearts|spades',
        ]);
        $this->assertStringNotContainsString("\x1b", $message, 'a refusal must not paint the terminal');
        $this->assertStringNotContainsString("\n", $message, 'a refusal must stay one physical line');
        $this->assertStringContainsString('red', $message, 'the visible letters of the value survive');
        $this->assertStringContainsString('Allowed: hearts|spades.', $message);
    }

    public function testGarbageLevelRefusalCannotForgeStderrLines(): void
    {
        [$status, $stdout, $stderr] = $this->runInjectionChild(['log', '--level=a' . "\x1b" . '[31mR' . "\n" . 'b', 'msg']);
        $this->assertSame(1, $status, 'the unknown-level refusal still fires');
        $this->assertSame('', $stdout);
        $this->assertStringNotContainsString("\x1b[31m", $stderr);
        $this->assertSame(
            1,
            \substr_count($stderr, "\n"),
            'the forged newline inside --level must not split the diagnostic into two lines',
        );
    }

    public function testGarbageBorderRefusalCannotPaintTheTerminal(): void
    {
        [$status, $stdout, $stderr] = $this->runInjectionChild(['style', '--border=fancy' . "\x1b" . '[31m', 'hi']);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringNotContainsString("\x1b[31m", $stderr);
        $this->assertStringContainsString('Allowed:', $stderr);
    }

    public function testTheFoldLivesAtTheSharedTranslationChokepoint(): void
    {
        $lang = (string) \file_get_contents(__DIR__ . '/../../src/Lang.php');
        $this->assertStringContainsString('cellValue', $lang, 'Lang::t must fold control bytes in params');
        $app = (string) \file_get_contents(__DIR__ . '/../../src/Application.php');
        $this->assertStringContainsString('cellValue', $app, 'the enum-violation line shares the fold');
    }
}
