<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

/**
 * {@see PosixPump} delivers every stdin byte even when the master is full
 * at the moment stdin reaches EOF.
 *
 * A would-block master write parks the remainder in the pump. That
 * remainder used to be retried only when stdin next became readable, and
 * the EOF read discarded it outright -- so a caller that handed the pump
 * all of its input and closed stdin (a scripted-input harness) left the
 * tail undelivered: the child waited for bytes that never came and the
 * pump exited on the EOF grace with the child still running. The pump now
 * watches the master for writability while a remainder is parked and only
 * sends VEOF behind the last data byte.
 */
final class PosixPumpBackPressureTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
    }

    public function testStdinTailParkedBehindAFullMasterIsDeliveredAfterStdinEof(): void
    {
        $this->requirePtySyscalls();

        // 3000 complete lines = 300 000 bytes: far past the pty input queue
        // plus the kernel's flip-buffer limit, so the master must would-block
        // while the child sleeps. Complete lines keep canonical mode from
        // discarding an over-long line.
        $line = \str_repeat('a', 99) . "\n";
        $lines = 3000;
        $expected = \strlen($line) * $lines;

        $stdin = \tmpfile();
        $this->assertIsResource($stdin);
        \fwrite($stdin, \str_repeat($line, $lines));
        \rewind($stdin);
        $stdout = \fopen('php://temp', 'r+');

        $pair = (new PosixPtySystem())->open(80, 24);
        $child = null;
        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'stty -echo; sleep 0.5; wc -c']);

            $opts = (new PumpOptions())
                ->withStdinEofGraceSec(3.0)
                ->withPumpDeadlineUs(20_000_000);
            $exit = (new PosixPump())->run($pair->master(), $stdin, $stdout, $child, $opts);

            $output = (string) \stream_get_contents($stdout, -1, 0);
            $this->assertSame(
                0,
                $exit,
                'the child was still running when the pump returned -- it never received the full '
                . "input and its VEOF. Output:\n" . \substr($output, -200),
            );
            $this->assertSame(1, \preg_match('/(\d+)\s*$/', $output, $m), "wc -c printed no count:\n" . \substr($output, -200));
            $this->assertSame($expected, (int) $m[1], 'the child received a different byte count than stdin carried');
        } finally {
            if ($child !== null && !$child->exited()) {
                $child->kill(9);
                $child->wait();
            }
            $pair->master()->close();
            \fclose($stdin);
            \fclose($stdout);
        }
    }
}
