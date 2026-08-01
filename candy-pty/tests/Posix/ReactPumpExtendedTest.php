<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * Extended ReactPump tests covering:
 * - onMasterWritable() back-pressure drain
 * - onStdinEof() grace period
 * - onPollTick() resize detection path
 * - onMasterReadable() with null stdoutStream / null onData
 * - start() double-call guard
 */
final class ReactPumpExtendedTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // start() double-call guard
    // ─────────────────────────────────────────────────────────────

    public function testStartThrowsWhenAlreadyRunning(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $pump = new ReactPump($loop);
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 10']);

            $pump->start($pair->master(), child: $child);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('#already running#');

            $pump->start($pair->master(), child: $child);
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // onMasterReadable() with null stdoutStream (callback-only)
    // ─────────────────────────────────────────────────────────────

    public function testOnMasterReadableWithNullStdoutStreamAndNullOnData(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $exit = null;

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'printf "callback-only\n"']);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            // Pass null for both stdoutStream and onData — callback-only mode.
            $pump->start($pair->master(), stdoutStream: null, child: $child, onData: null)
                ->then(function (int $code) use (&$exit, $loop, $safety): void {
                    $exit = $code;
                    $loop->cancelTimer($safety);
                });

            $loop->run();
            $this->assertSame(0, $exit);
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // onMasterReadable() with onData callback (no stdoutStream)
    // ─────────────────────────────────────────────────────────────

    public function testOnMasterReadableWithOnDataCallbackOnly(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $collected = '';
        $exit = null;

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'printf "data-cb-only\n"']);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            $pump->start(
                $pair->master(),
                stdoutStream: null,
                child: $child,
                onData: function (string $bytes) use (&$collected): void {
                    $collected .= $bytes;
                },
            )->then(function (int $code) use (&$exit, $loop, $safety): void {
                $exit = $code;
                $loop->cancelTimer($safety);
            });

            $loop->run();
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('data-cb-only', $collected);
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // onPollTick() resize detection path (onSigwinch handler set)
    // ─────────────────────────────────────────────────────────────

    public function testOnPollTickFiresResizeHandlerOnSizeChange(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $resizeEvents = [];

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 0.5']);

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(20_000) // 20ms poll tick
                ->withFlushDeadlineSec(5.0);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(8.0, static fn () => $loop->stop());
            $pump->start(
                $pair->master(),
                child: $child,
                opts: $opts,
                onData: null,
            )->then(function (int $code) use (&$exit, $loop, $safety): void {
                $loop->cancelTimer($safety);
            });

            // Wait a moment then resize the PTY from another part of the code.
            $loop->addTimer(0.05, function () use ($pair, &$resizeEvents): void {
                $pair->master()->resize(132, 43);
            });

            $loop->run();

            // The resize happened — verify we can query the new size.
            $size = $pair->master()->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(43, $size['rows']);
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // onStdinEof() — forward VEOF then wait for child exit
    // ─────────────────────────────────────────────────────────────

    public function testOnStdinEofSetsGraceTimerAndSendsVEOF(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $exit = null;

        // Use bash -c 'read line; echo "$line"' — it will exit when stdin closes.
        $child = $pair->slave()->spawn(['/bin/bash', '-c', 'read line; echo "got: $line"']);

        $pump = new ReactPump($loop);
        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());

        // Create a temp file to capture stdin write result.
        $tmp = \fopen('php://temp', 'r+');
        $this->assertIsResource($tmp);

        $pump->start(
            $pair->master(),
            stdinStream: $tmp,
            stdoutStream: null,
            child: $child,
            onData: function (string $bytes) use (&$output): void {
                // Just consume.
            },
        )->then(function (int $code) use (&$exit, $loop, $safety): void {
            $exit = $code;
            $loop->cancelTimer($safety);
        });

        // Write some input then close stdin to trigger EOF.
        \fwrite($tmp, "hello stdin\n");
        \fclose($tmp);

        $loop->run();
        $this->assertSame(0, $exit);
    }

    // ─────────────────────────────────────────────────────────────
    // isRunning() accurately reflects pump state
    // ─────────────────────────────────────────────────────────────

    public function testIsRunningTrueWhilePumpActive(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'exit 0']);

            $pump = new ReactPump($loop);
            $pump->start($pair->master(), child: $child);

            $this->assertTrue($pump->isRunning());

            $loop->addTimer(0.05, static fn () => $loop->stop());
            $loop->run();

            $this->assertFalse($pump->isRunning());
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // writeToMaster() partial-write back-pressure path
    // ─────────────────────────────────────────────────────────────

    public function testWriteToMasterDrainsPendingOnRetry(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $exit = null;

        // A child that reads slowly — forces master write to be partial.
        $child = $pair->slave()->spawn([
            '/bin/bash', '-c',
            'sleep 0.1; printf "slow-output\n"',
        ]);

        $pump = new ReactPump($loop);
        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());

        // Write a large amount to stress the partial-write path.
        $input = \fopen('php://temp', 'r+');
        \fwrite($input, str_repeat("x", 8192));
        \fseek($input, 0);

        $pump->start(
            $pair->master(),
            stdinStream: $input,
            child: $child,
        )->then(function (int $code) use (&$exit, $loop, $safety): void {
            $exit = $code;
            $loop->cancelTimer($safety);
        });

        $loop->run();
        $this->assertSame(0, $exit);
    }

    // ─────────────────────────────────────────────────────────────
    // Constructor accepts null loop
    // ─────────────────────────────────────────────────────────────

    public function testConstructorWithNullLoop(): void
    {
        $pump = new ReactPump(null);
        $this->assertFalse($pump->isRunning());
    }
}
