<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * P6.5.1 — `candy-vcr record` skeleton. The command spawns a real
 * child under a PTY, records via the P6.1 pump tap, and lays down a
 * JSONL cassette. The tests skip cleanly on FFI-less / sandboxed CI
 * — full record→cassette assertions are guarded behind a PTY-syscall
 * gate identical to the candy-pty test suite.
 */
final class RecordCommandTest extends TestCase
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
        if (!\is_executable('/bin/echo')) {
            $this->markTestSkipped('/bin/echo is not executable on this host.');
        }
    }

    // ----- argv parsing / usage ----------------------------------

    public function testApplicationRegistersRecordCommand(): void
    {
        $app = new Application();
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $app->run(['candy-vcr', 'help'], $stdout, $stderr);
            $this->assertSame(0, $rc);
            \rewind($stdout);
            $help = (string) \stream_get_contents($stdout);
            $this->assertStringContainsString('record', $help);
            $this->assertStringContainsString('Record a command', $help);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testMissingCommandPrintsUsage(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run([], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $usage = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('usage: candy-vcr record', $usage);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testUnknownOptionExitsTwo(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(['--bogus', '--', '/bin/echo'], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('unknown option --bogus', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testNonPositiveDimensionsRejected(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(['--cols=0', '--', '/bin/echo'], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('positive integers', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    // ----- real-PTY record round-trip ----------------------------

    public function testEchoRecordingProducesCassetteWithOutputAndQuit(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-echo-');
        $this->assertIsString($cassette);

        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--output', $cassette, '--', '/bin/echo', 'hello-record'],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc, 'echo exits 0 even when piped through a PTY');

            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('candy-vcr: recorded', $err);

            $reader = new JsonlFormat();
            $loaded = $reader->read($cassette);
            $this->assertGreaterThan(
                0,
                $loaded->eventCount(),
                'cassette should record at least one event',
            );

            $outputBlob = '';
            $sawQuit = false;
            $sawResize = false;
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $outputBlob .= (string) ($event->payload['b'] ?? '');
                } elseif ($event->kind === EventKind::Quit) {
                    $sawQuit = true;
                } elseif ($event->kind === EventKind::Resize) {
                    $sawResize = true;
                }
            }
            $this->assertStringContainsString(
                'hello-record',
                $outputBlob,
                'recorded output must contain the echoed string',
            );
            $this->assertTrue($sawQuit, 'recorder must emit a quit event on clean exit');
            $this->assertTrue($sawResize, 'initial recordResize(cols,rows) must land an event');
            $this->assertSame(RecordCommand::DEFAULT_COLS, $loaded->header->cols);
            $this->assertSame(RecordCommand::DEFAULT_ROWS, $loaded->header->rows);
        } finally {
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            if (\is_resource($stderr)) {
                \fclose($stderr);
            }
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }

    public function testCustomColsRowsLandInCassetteHeader(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-size-');
        $this->assertIsString($cassette);

        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--output', $cassette, '--cols', '132', '--rows', '40', '--', '/bin/echo', 'ok'],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc);

            $loaded = (new JsonlFormat())->read($cassette);
            $this->assertSame(132, $loaded->header->cols);
            $this->assertSame(40, $loaded->header->rows);

            // The first event after the header should be the resize.
            $resizeCount = 0;
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Resize) {
                    $resizeCount++;
                    $this->assertSame(132, $event->payload['cols']);
                    $this->assertSame(40, $event->payload['rows']);
                }
            }
            $this->assertGreaterThan(0, $resizeCount, 'resize event must land for custom size');
        } finally {
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            if (\is_resource($stderr)) {
                \fclose($stderr);
            }
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }

    public function testFailingCommandPropagatesNonZeroExit(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-fail-');
        $this->assertIsString($cassette);

        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--output', $cassette, '--', '/bin/sh', '-c', 'exit 7'],
                $stdout,
                $stderr,
            );
            $this->assertSame(7, $rc, 'recorder must surface the recorded child\'s exit code');
        } finally {
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            if (\is_resource($stderr)) {
                \fclose($stderr);
            }
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }
}
