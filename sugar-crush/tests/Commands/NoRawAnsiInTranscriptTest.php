<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Commands whose stdout is folded into the TUI transcript must not colour
 * themselves with raw escape sequences.
 *
 * `/mcp` and `/share` are CLI-shaped classes that `echo` their output, and
 * {@see \SugarCraft\Crush\Chat} captures that with `ob_start()` and appends it
 * to the chat. They styled themselves with `\033[33m…\033[0m`, which surfaced
 * in the chat as a literal `[33m` — the escape byte was consumed somewhere on
 * the way in, leaving the parameter bytes as visible text.
 *
 * Deliberately asserted at the SOURCE rather than fixed by stripping escapes
 * on the way into the transcript: a blanket strip would hide the next
 * offender instead of surfacing it. The TUI styles its own text, so these
 * classes have no business emitting escapes at all.
 */
final class NoRawAnsiInTranscriptTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function transcriptCommandProvider(): array
    {
        return [
            'McpAuthCommand' => [__DIR__ . '/../../src/Commands/McpAuthCommand.php'],
            'ShareCommand' => [__DIR__ . '/../../src/Commands/ShareCommand.php'],
        ];
    }

    /**
     * @dataProvider transcriptCommandProvider
     */
    public function testCommandEmitsNoRawEscapeSequences(string $path): void
    {
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        $this->assertSame(
            0,
            preg_match_all('/\\\\033\[|\\\\e\[|\\\\x1b\[/i', $source),
            basename($path) . ' emits raw ANSI; its output is folded into the TUI transcript, '
                . 'where escapes surface as literal text like "[33m"',
        );
    }
}
