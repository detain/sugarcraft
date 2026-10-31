<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Windows\ConPtySystem;

/**
 * The only file in the ConPTY family that talks to a real kernel32.
 *
 * Off Windows — every Linux and macOS cell — the whole class skips with an
 * honest reason: the adapter LOGIC is already pinned against the transcript
 * double, and what remains (the actual CreatePseudoConsole round trip) cannot
 * execute on this host. On a Windows 10 1809+ / PHP 8.4+ cell it spawns
 * cmd.exe once and proves the console, the byte path, the resize, and the
 * exit-code channel all work end to end. Every wait is bounded per-operation
 * so a wedged host fails loudly instead of hanging the matrix leg.
 */
final class ConPtyLiveTest extends TestCase
{
    /** Per-operation ceiling for the whole probe; generous, this is cmd /c echo. */
    private const LIVE_CEILING_SECONDS = 15.0;

    public function testLivePseudoConsoleRoundTripsAProbeLineThroughCmd(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped(
                'ConPTY needs a real Windows host; the adapter logic is pinned off-Windows through FakeConPtyApi',
            );
        }

        if (\PHP_VERSION_ID < 80400 || !\extension_loaded('ffi')) {
            $this->markTestSkipped(
                'the live ConPTY binding needs PHP 8.4 or newer with ffi enabled (the Windows 8.3 CI cell runs pure tests only)',
            );
        }

        $probe = 'conpty-live-' . \uniqid('pty', true);

        $system = new ConPtySystem();
        $pair = $system->open(100, 30);
        $master = $pair->master();

        try {
            $child = $pair->slave()->spawn(['cmd', '/c', 'echo ' . $probe]);

            $received = '';
            $deadline = \microtime(true) + self::LIVE_CEILING_SECONDS;

            while (!\str_contains($received, $probe)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('ConPTY live read timed out waiting for the probe line');
                }

                $chunk = $master->read(8192, 0.25);

                if ($chunk === null) {
                    continue; // still within the per-read slice
                }

                if ($chunk === '') {
                    self::fail('the ConPTY output pipe closed before the probe appeared');
                }

                $received .= $chunk;
            }

            $master->resize(120, 40);
            self::assertSame(['cols' => 120, 'rows' => 40, 'xpix' => 0, 'ypix' => 0], $master->size());

            $exitDeadline = \microtime(true) + self::LIVE_CEILING_SECONDS;

            while (!$child->exited()) {
                if (\microtime(true) >= $exitDeadline) {
                    self::fail('the cmd /c child never settled within the ceiling');
                }

                \usleep(50_000);
            }

            self::assertSame(0, $child->wait());
        } finally {
            $master->close();
        }
    }
}
