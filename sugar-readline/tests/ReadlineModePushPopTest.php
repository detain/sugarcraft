<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Finding 099 (S-64): the DEC-private mode push/pop pair had only ever
 * been pinned in its negative polarity (non-tty sinks stay silent). On a
 * real tty the pump enables bracketed paste (CSI ?2004h) and the kitty
 * disambiguate flag (CSI >1u) and must restore both on EVERY exit path,
 * in LIFO order (last pushed, first popped). script(1) children expose
 * the transcript; a TERM=dumb tty pins the capability gate stays shut.
 */
final class ReadlineModePushPopTest extends TestCase
{
    private const PASTE_ON = "\x1b[?2004h";
    private const PASTE_OFF = "\x1b[?2004l";
    private const KITTY_PUSH = "\x1b[>1u";
    private const KITTY_POP = "\x1b[<u";

    public function testCleanTtyRunPushesBothModesAndPopsThemInLifoOrder(): void
    {
        $transcript = $this->runUnderScript('clean', 'xterm-256color');
        if ($transcript === null) {
            self::markTestSkipped('script(1) unavailable — the positive mode arm cannot be exercised in-process (disclosure per S-23 gate family)');
        }

        self::assertStringContainsString('DONE|ab', $transcript, 'the child must submit cleanly: ' . addcslashes($transcript, "\x00..\x1f"));
        self::assertStringContainsString(self::PASTE_ON, $transcript, 'bracketed paste must be enabled on a live tty');
        self::assertStringContainsString(self::KITTY_PUSH, $transcript, 'the kitty disambiguate flag must be pushed on a live tty');
        self::assertStringContainsString(self::KITTY_POP, $transcript, 'the kitty flag must be popped on the normal exit path');
        self::assertStringContainsString(self::PASTE_OFF, $transcript, 'bracketed paste must be disabled on the normal exit path');

        self::assertLessThan(
            (int) \strpos($transcript, self::KITTY_PUSH),
            (int) \strpos($transcript, self::PASTE_ON),
            'paste is pushed first (it is popped last)',
        );
        self::assertLessThan(
            (int) \strpos($transcript, self::PASTE_OFF),
            (int) \strpos($transcript, self::KITTY_POP),
            'LIFO teardown: the kitty flag pops before the paste restore',
        );
    }

    public function testExceptionMidLoopStillPopsBothModes(): void
    {
        $transcript = $this->runUnderScript('throw', 'xterm-256color');
        if ($transcript === null) {
            self::markTestSkipped('script(1) unavailable — the exception-path mode arm cannot be exercised in-process');
        }

        self::assertStringContainsString('THREW|boom-mid-loop', $transcript, 'the prompt must really throw inside the loop: ' . addcslashes($transcript, "\x00..\x1f"));
        self::assertStringContainsString(self::PASTE_ON, $transcript);
        self::assertStringContainsString(self::KITTY_PUSH, $transcript);
        self::assertStringContainsString(self::KITTY_POP, $transcript, 'a throw out of runLoop must not leak the kitty flag');
        self::assertStringContainsString(self::PASTE_OFF, $transcript, 'a throw out of runLoop must not leave bracketed paste enabled');

        self::assertLessThan(
            (int) \strpos($transcript, self::PASTE_OFF),
            (int) \strpos($transcript, self::KITTY_POP),
            'the exception path keeps the same LIFO pop order',
        );
    }

    public function testDumbTtyReceivesNeitherPushNorPop(): void
    {
        $transcript = $this->runUnderScript('clean', 'dumb');
        if ($transcript === null) {
            self::markTestSkipped('script(1) unavailable — the dumb-tty mode gate cannot be exercised in-process');
        }

        self::assertStringContainsString('DONE|ab', $transcript);
        self::assertStringNotContainsString("\x1b[", $transcript, 'a dumb tty may not be sent mode bytes — nor any escape at all');
    }

    /** Transcript the child painted on a real pty; null when script(1) is absent. */
    private function runUnderScript(string $mode, string $term): ?string
    {
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            return null;
        }

        $child = __DIR__ . '/Support/mode_push_pop_child.php';
        self::assertFileExists($child);
        $session = tempnam(sys_get_temp_dir(), 'srmode');
        self::assertIsString($session);

        $inner = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($child) . ' ' . \escapeshellarg($mode);
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
