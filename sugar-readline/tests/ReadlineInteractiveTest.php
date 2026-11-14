<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Readline;

/**
 * S-06 (finding 005): Readline::interactive() encapsulates the raw-mode
 * ritual the README Quick Start never used to run — chords must work
 * quickstart-as-written on a TTY, and the whole flip must stay a no-op on
 * non-TTY hosts (pipes, CI, tests).
 *
 * @see \SugarCraft\Readline\Readline::interactive()
 */
final class ReadlineInteractiveTest extends TestCase
{
    public function testInteractiveIsANoOpWithoutATty(): void
    {
        $child = __DIR__ . '/Support/interactive_noop_child.php';
        self::assertFileExists($child);

        // PATH=/nonexistent makes any stty exec loud-by-absence: the guard
        // must return BEFORE reaching it, so no STDERR warning is printed.
        $command = 'env PATH=/nonexistent ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($child) . ' < /dev/null 2>&1';

        $output = self::runChild($command);

        self::assertStringContainsString(
            'OWNED=false SUBMITTED=true VALUE=\'hi\'',
            $output,
            'non-TTY interactive() must run unflipped through the injected driver: ' . $output,
        );
        self::assertStringNotContainsString(
            'stty unavailable',
            $output,
            'the isatty guard must keep interactive() from ever reaching stty',
        );
    }

    public function testCtrlCAbortsThroughInteractiveOnAPty(): void
    {
        $session = self::drivePty('interactive', '\003');

        self::assertStringContainsString('CHORD', $session, 'Ctrl+C must arrive as a byte through interactive(): ' . self::visible($session));
        self::assertStringContainsString('PROBE submitted=false aborted=true', $session, 'the prompt must abort on the chord, not die: ' . self::visible($session));
        self::assertStringContainsString('CANONICAL_AFTER=true', $session, 'the finally must hand the device back its snapshot: ' . self::visible($session));
    }

    public function testCtrlCKillsTheCookedChildWithoutRawMode(): void
    {
        // The documented adoption cliff, pinned from the other side: without
        // the flip Ctrl+C is a SIGINT death — no verdict ever prints. If the
        // harness silently failed to deliver the byte, the cooked child would
        // reach EOF and print UNREACHABLE; its absence alongside PROBE's is
        // what makes this a real pin.
        $session = self::drivePty('cooked', '\003');

        self::assertStringNotContainsString('PROBE=', $session);
        self::assertStringNotContainsString('UNREACHABLE', $session, 'cooked stdin must never reach the pump read: ' . self::visible($session));
    }

    /**
     * Feed shell-quoted octal bytes (e.g. '\003') to a `script`-allocated
     * pts running the interactive child in one mode; return the session.
     */
    private static function drivePty(string $mode, string $octalFeed): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('util-linux script(1) harness is Linux-shaped here');
        }
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            self::markTestSkipped('script(1) unavailable');
        }

        $child = __DIR__ . '/Support/interactive_pty_child.php';
        $session = tempnam(sys_get_temp_dir(), 'sri');
        self::assertIsString($session);

        $inner = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($mode);
        // Keep the master open a beat past the feed so the byte is delivered
        // while the child is blocked in its read, not after hangup.
        // Give the child a beat to boot and (in interactive mode) flip the
        // device before the byte arrives — then hold the master open through
        // processing. Feeding instantly would raise SIGINT against the still
        // canonical discipline even when interactive() works correctly.
        $command = '{ sleep 1; printf \'' . $octalFeed . '\'; sleep 2; } | script -q -e -c ' . escapeshellarg('env -u COLUMNS -u LINES ' . $inner)
            . ' ' . escapeshellarg($session) . ' >/dev/null 2>&1';

        try {
            $handle = popen($command, 'r');
            self::assertIsResource($handle);
            while (!feof($handle)) {
                fgets($handle);
            }
            pclose($handle);

            $output = (string) file_get_contents($session);
            if (str_contains($output, 'NOTTY')) {
                self::markTestSkipped('pts harness degraded on this box');
            }

            return $output;
        } finally {
            @unlink($session);
        }
    }

    private static function runChild(string $command): string
    {
        $handle = popen($command, 'r');
        self::assertIsResource($handle);

        $output = '';
        while (!feof($handle)) {
            $chunk = fgets($handle);
            if (false === $chunk) {
                break;
            }
            $output .= $chunk;
        }
        $status = pclose($handle);

        self::assertSame(0, $status, 'child exited non-zero: ' . $output);

        return $output;
    }

    /** Strip control noise so failure messages stay readable. */
    private static function visible(string $session): string
    {
        return trim((string) preg_replace('/[^\x20-\x7E\n]+/', '·', $session));
    }
}
