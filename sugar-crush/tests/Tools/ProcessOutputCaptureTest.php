<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Regression guard: a tool subprocess must never write to the real terminal.
 *
 * `exec()` captures stdout only — the child inherits the PHP process's stderr,
 * so anything written there lands on the actual terminal. Underneath a running
 * TUI that paints outside the managed frame at whatever cursor position the
 * terminal is at, and survives until the next full repaint. Reported from a
 * live session: `grep: write error: Broken pipe` appeared at the bottom-left,
 * outside the chat area, then vanished when the reply arrived and the screen
 * was rebuilt.
 *
 * The same leak also lost the diagnostic: stderr is where a failing command
 * explains itself, so the model received an empty result flagged as an error
 * with no reason in it.
 */
final class ProcessOutputCaptureTest extends TestCase
{
    /** The actual reported symptom: a broken-pipe warning must not escape. */
    public function testBrokenPipeStderrDoesNotReachTheTerminal(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute([
            'command' => 'grep -rn needle /etc/hostname | head -1; echo finished',
            'id' => 'call_1',
        ]);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked, 'tool stderr leaked to the terminal');
        $this->assertStringContainsString('finished', $result->content());
    }

    /** Nothing a command writes to stderr may escape, broken pipe or not. */
    public function testArbitraryStderrIsCapturedNotPrinted(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute([
            'command' => 'echo to-stdout; echo to-stderr 1>&2',
            'id' => 'call_2',
        ]);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked);
        // Succeeded with real stdout, so the stderr TEXT stays out of the
        // result -- but its existence is announced, because a dropped stream
        // nobody mentions reads to the model as a stream that was never
        // written. Asserted as an exact string so the marker cannot be lost or
        // reworded silently.
        $this->assertSame(
            "to-stdout\n... [stderr suppressed: the command succeeded and also wrote to stderr; "
            . "re-run with 2>&1 to see it]",
            $result->content(),
        );
        $this->assertStringNotContainsString('to-stderr', $result->content(), 'the text itself must not be merged in');
        $this->assertFalse($result->isError());
    }

    /**
     * The diagnostic half: a failing command must hand the model the reason,
     * not an empty string with an error flag.
     */
    public function testFailingCommandSurfacesItsStderrToTheModel(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute(['command' => 'ls /definitely-not-here-xyz', 'id' => 'call_3']);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked);
        $this->assertTrue($result->isError());
        $this->assertNotSame('', $result->content(), 'a failing command must explain itself');
        $this->assertStringContainsString('No such file', $result->content());
    }

    /** A command reading stdin must not hang the agent forever. */
    public function testCommandReadingStdinDoesNotBlock(): void
    {
        $tool = new Bash();

        $started = microtime(true);
        $result = $tool->execute(['command' => 'cat', 'id' => 'call_4']);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(10.0, $elapsed, 'stdin must be closed so the child cannot block');
        $this->assertSame('', $result->content());
    }
}
