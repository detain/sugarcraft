<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * P6.5.5 — Shirley-style integration: record a real bash session,
 * walk the cassette back, assert the recorded `output` events
 * round-trip what the shell wrote.
 *
 * Bounded at ~10 s wallclock so the integration suite stays small
 * (plan target: ≤30 s total for P6.5.5 + P6.5.6). The shell itself
 * runs in ~0.5 s; the remaining time is PTY teardown grace.
 */
final class ShirleyBashTest extends TestCase
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
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true spawns.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable on this host.');
        }
    }

    public function testRecordBashEchoSleepEchoCassetteRoundTrips(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'shirley-bash-');
        $this->assertIsString($cassette);

        // Keep stdin live until bash exits so the pump's 300 ms EOF
        // grace doesn't close the master before the second `echo
        // world` lands on the PTY.
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$stdinRead, $stdinWrite] = $pair;

        $cmd = new RecordCommand($stdinRead);
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $start = \microtime(true);
            $rc = $cmd->run([
                '--output', $cassette,
                '--',
                '/bin/bash', '-c',
                "echo hello; sleep 0.2; echo world",
            ], $stdout, $stderr);
            $elapsed = \microtime(true) - $start;

            $this->assertSame(0, $rc, 'bash mini-session must exit 0');
            $this->assertLessThan(
                10.0,
                $elapsed,
                'bash integration must stay bounded — saw ' . \round($elapsed, 2) . 's',
            );

            // Walk the cassette: concatenate every Output event's b
            // bytes; both markers must appear.
            $loaded = (new JsonlFormat())->read($cassette);
            $outputBlob = '';
            $sawQuit = false;
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $outputBlob .= (string) ($event->payload['b'] ?? '');
                } elseif ($event->kind === EventKind::Quit) {
                    $sawQuit = true;
                }
            }
            $this->assertStringContainsString('hello', $outputBlob, 'cassette must carry "hello"');
            $this->assertStringContainsString('world', $outputBlob, 'cassette must carry "world"');
            $this->assertTrue($sawQuit, 'recorder must emit a quit event on clean child exit');

            // Cassette duration should be at least the 0.2 s sleep
            // (real wallclock includes pump teardown grace, so the
            // upper bound is generous).
            $this->assertGreaterThan(
                0.05,
                $loaded->duration(),
                'cassette duration must reflect the recorded sleep',
            );
        } finally {
            if (\is_resource($stdinWrite)) {
                @\fclose($stdinWrite);
            }
            if (\is_resource($stdinRead)) {
                @\fclose($stdinRead);
            }
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }
}
