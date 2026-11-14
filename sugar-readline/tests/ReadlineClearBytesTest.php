<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\Tests\Support\RepaintDriver;
use SugarCraft\Readline\TextareaPrompt;

/**
 * Finding 098 (S-63): the Ctrl+L clear and the frame right after it.
 *
 * Three claims get bytes: on a non-TTY capture sink the clear is
 * clear-below (CSI 0J) — never a whole-scene wipe; the repaint that
 * follows starts from row zero (clearDisplay() zeroes lastPaintedRows,
 * so the frame cannot climb into the cleared ground); and under a real
 * tty (script(1) child) the S-23 gate flips the clear to the whole-scene
 * form (CSI 2J + home) while a TERM=dumb tty gets no escapes at all.
 */
final class ReadlineClearBytesTest extends TestCase
{
    public function testCtrlLClearsBelowAndTheNextFrameRestartsAtRowZero(): void
    {
        // TextareaPrompt gives a two-row frame so the climb-then-no-climb
        // interaction is observable (a one-row prompt never climbs anyway).
        $captured = RepaintDriver::capture(new TextareaPrompt('Body'), "x\x0c\r");

        self::assertSame(
            "\r\x1b[0J\x1b[1;36mBody\x1b[0m\n\x1b[7m \x1b[0m\n"
            . "\r\x1b[2A\x1b[0J\x1b[1;36mBody\x1b[0m\nx\x1b[7m \x1b[0m\n"
            . "\x1b[0J"                                        // Ctrl+L: clear-below only on a sink
            . "\r\x1b[0J\x1b[1;36mBody\x1b[0m\nx\x1b[7m \x1b[0m\n", // post-clear frame: row 0, no climb
            $captured,
        );
        self::assertStringNotContainsString("\x1b[0J\r\x1b[2A", $captured, 'the frame after the clear must not climb');
        self::assertStringNotContainsString("\x1b[2J", $captured, 'a capture sink must never receive a whole-scene wipe');
    }

    public function testDumbSinkValueSurvivesTheClearWithoutAnySceneBytes(): void
    {
        $captured = RepaintDriver::capture(new TextPrompt('Name'), "keep\x0c\r");
        self::assertStringContainsString('keep', $captured);
        self::assertStringNotContainsString("\x1b[2J", $captured);
    }

    // =========================================================================
    // tty-gated arms via script(1) — same harness idiom as the rows probes
    // =========================================================================

    public function testRealTtyClearIsTheWholeSceneForm(): void
    {
        $transcript = $this->runUnderScript('xterm-256color');
        if ($transcript === null) {
            self::markTestSkipped('script(1) unavailable — the tty clear arm cannot be exercised in-process (disclosure per S-23 gate family)');
        }

        self::assertStringContainsString('DONE|x', $transcript, 'the child must reach its end marker: ' . addcslashes($transcript, "\x00..\x1f"));
        self::assertStringContainsString("\x1b[2J\x1b[H", $transcript, 'on a live tty Ctrl+L wipes the scene and homes the cursor');
    }

    public function testDumbTtyReceivesNoSceneBytesAtAll(): void
    {
        $transcript = $this->runUnderScript('dumb');
        if ($transcript === null) {
            self::markTestSkipped('script(1) unavailable — the dumb-tty arm cannot be exercised in-process');
        }

        self::assertStringContainsString('DONE|x', $transcript);
        self::assertStringNotContainsString("\x1b[", $transcript, 'a dumb tty must never be sent CSI bytes — not even the clear');
    }

    /** Bytes the child painted on a real pty; null when script(1) is absent. */
    private function runUnderScript(string $term): ?string
    {
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            return null;
        }

        $child = __DIR__ . '/Support/clear_bytes_child.php';
        self::assertFileExists($child);
        $session = tempnam(sys_get_temp_dir(), 'srclear');
        self::assertIsString($session);

        $inner = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($child);
        $command = 'timeout 30 script -q -e -c '
            . \escapeshellarg("env -u COLUMNS -u LINES TERM={$term} {$inner}")
            . ' ' . \escapeshellarg($session) . ' >/dev/null 2>&1';

        $handle = popen($command, 'r');
        self::assertIsResource($handle);
        stream_set_timeout($handle, 30); // S-62 bound parity
        while (!feof($handle)) {
            fgets($handle);
        }
        $status = pclose($handle);

        $transcript = (string) @file_get_contents($session);
        @unlink($session);

        self::assertSame(0, $status, 'scripted child must exit cleanly: ' . addcslashes($transcript, "\x00..\x1f"));
        return $transcript;
    }
}
