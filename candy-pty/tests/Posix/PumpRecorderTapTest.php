<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Recorder;

/**
 * P6.1 — PosixPump Recorder tap. Spawns a real bash session under a
 * PTY with a {@see \SugarCraft\Vcr\Recorder} attached via
 * {@see PumpOptions::withRecorder()}, then re-reads the cassette via
 * {@see JsonlFormat} and asserts the recorded `output` events contain
 * the strings the child wrote.
 *
 * Uses cassette readback (not {@see \SugarCraft\Vcr\Player::play()})
 * because Player drives a candy-core Program, not a raw byte stream —
 * the assertion here is that the tap recorded faithfully, which a
 * direct cassette walk proves without coupling to Program semantics.
 */
final class PumpRecorderTapTest extends TestCase
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
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable on this host.');
        }
        if (!\class_exists(\SugarCraft\Vcr\Recorder::class)) {
            $this->markTestSkipped('sugarcraft/candy-vcr is not autoloadable.');
        }
    }

    public function testRecorderTeesOutputBytes(): void
    {
        $this->requirePtySyscalls();

        $cassettePath = \tempnam(\sys_get_temp_dir(), 'pump-rec-');
        $this->assertIsString($cassettePath);

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $stdin = null;
        $stdout = null;
        $recorder = null;

        try {
            $master = $pair->master();
            // Plain (non-ctty) spawn. The recorder tap is orthogonal
            // to ctty semantics. Single-shot `printf` issues both
            // tokens in one write() — race-free vs the pump's EOF
            // grace window. Coverage of the ctty path lives in
            // {@see PosixPumpTest::testSimpleEcho}.
            $child = $pair->slave()->spawn(
                ['/bin/bash', '-c', "printf 'hello-world\\n'"],
            );

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');
            $this->assertIsResource($stdin);
            $this->assertIsResource($stdout);

            $recorder = Recorder::open($cassettePath);

            $opts = (new PumpOptions())->withRecorder($recorder);

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            $recorder->recordQuit();
            $recorder->close();
            $recorder = null;

            $this->assertSame(0, $exitCode);

            // Sanity: the bytes the pump wrote to its stdout sink
            // should carry the marker.
            \rewind($stdout);
            $pumpOutput = \stream_get_contents($stdout, -1, 0);
            $this->assertStringContainsString('hello-world', $pumpOutput);

            // The recorded cassette should carry the same bytes via
            // its `output` events (one or more `b` payloads whose
            // concatenation contains the markers).
            $cassette = (new JsonlFormat())->read($cassettePath);
            $this->assertGreaterThan(
                0,
                $cassette->eventCount(),
                'cassette should record at least one event',
            );

            $outputBlob = '';
            $sawQuit = false;
            foreach ($cassette->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $outputBlob .= (string) ($event->payload['b'] ?? '');
                } elseif ($event->kind === EventKind::Quit) {
                    $sawQuit = true;
                }
            }
            $this->assertStringContainsString(
                'hello-world',
                $outputBlob,
                'recorder should tee the master-read bytes faithfully',
            );
            $this->assertTrue($sawQuit, 'recordQuit() should land a Quit event in the cassette');
        } finally {
            if ($recorder !== null) {
                $recorder->close();
            }
            if (\is_resource($stdin)) {
                \fclose($stdin);
            }
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            $pair->master()->close();
            if (\is_string($cassettePath) && \file_exists($cassettePath)) {
                @\unlink($cassettePath);
            }
        }
    }

    public function testRecorderIsNoOpWhenUnset(): void
    {
        $this->requirePtySyscalls();

        // Regression-pin: PumpOptions without a recorder must walk the
        // same pump loop as before P6.1 — no recorder calls, no
        // closure indirection. A simple "echo hello" run is the
        // canonical happy-path check from PosixPumpTest::testSimpleEcho.
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $stdin = null;
        $stdout = null;

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'echo hello']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = new PumpOptions();
            $this->assertNull(
                $opts->recorder,
                'PumpOptions::$recorder should default to null for zero-overhead path',
            );

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('hello', $output);
        } finally {
            if (\is_resource($stdin)) {
                \fclose($stdin);
            }
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            $pair->master()->close();
        }
    }

    public function testWithRecorderIsImmutable(): void
    {
        // Pure structural test — no PTY syscalls required, runs on
        // FFI-less CI too.
        $cassettePath = \tempnam(\sys_get_temp_dir(), 'pump-rec-mut-');
        $this->assertIsString($cassettePath);

        $recorder = null;
        try {
            if (!\class_exists(\SugarCraft\Vcr\Recorder::class)) {
                $this->markTestSkipped('sugarcraft/candy-vcr is not autoloadable.');
            }

            $recorder = Recorder::open($cassettePath);

            $base = new PumpOptions();
            $tapped = $base->withRecorder($recorder);

            $this->assertNotSame($base, $tapped, 'withRecorder must return a new instance');
            $this->assertNull($base->recorder, 'original PumpOptions must stay untouched');
            $this->assertSame($recorder, $tapped->recorder);

            $detached = $tapped->withRecorder(null);
            $this->assertNull($detached->recorder, 'withRecorder(null) must detach the tap');
            $this->assertSame($recorder, $tapped->recorder, 'detaching must not mutate the prior instance');
        } finally {
            if ($recorder !== null) {
                $recorder->close();
            }
            if (\file_exists($cassettePath)) {
                @\unlink($cassettePath);
            }
        }
    }
}
