<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Recorder;

/**
 * P6.5.3 — `--idle-trim` records both `t` (compressed) and `tRaw`
 * (original) when gaps exceed the threshold. Replay defaults to `t`;
 * `--no-trim` restores the real cadence using `tRaw`.
 */
final class RecordCommandIdleTrimTest extends TestCase
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
    }

    // ----- argv parsing ---------------------------------------------

    public function testIdleTrimRequiresPositiveSeconds(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        try {
            $rc = $cmd->run(['--idle-trim=0', '--', '/bin/echo'], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('--idle-trim must be > 0', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testIdleTrimUsageStringMentionsTRaw(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        try {
            $cmd->run(['--help'], $stdout, $stderr);
            \rewind($stderr);
            $usage = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('--idle-trim', $usage);
            $this->assertStringContainsString('tRaw', $usage);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testReplayUsageStringMentionsNoTrim(): void
    {
        $cmd = new ReplayCommand();
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        try {
            $cmd->run([], $stdout, $stderr);
            \rewind($stderr);
            $usage = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('--no-trim', $usage);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    // ----- Recorder withIdleTrim unit tests -------------------------

    public function testRecorderIdleTrimRejectsNonPositiveThreshold(): void
    {
        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-trim-bad-');
        $recorder = Recorder::open($cassette);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $recorder->withIdleTrim(0.0);
        } finally {
            $recorder->close();
            @\unlink($cassette);
        }
    }

    public function testRecorderWithoutTrimWritesPlainT(): void
    {
        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-trim-off-');
        $recorder = Recorder::open($cassette);
        try {
            $recorder->recordOutput('first');
            \usleep(50_000);
            $recorder->recordOutput('second');
        } finally {
            $recorder->close();
        }

        $loaded = (new JsonlFormat())->read($cassette);
        foreach ($loaded->events as $event) {
            $this->assertArrayNotHasKey(
                'tRaw',
                $event->payload,
                'no tRaw should appear without --idle-trim',
            );
        }
        @\unlink($cassette);
    }

    public function testRecorderWithIdleTrimCompressesLongGapsAndWritesTRaw(): void
    {
        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-trim-on-');
        $recorder = Recorder::open($cassette);
        $recorder->withIdleTrim(0.4, compressedMaxSec: 0.2);

        try {
            $recorder->recordOutput('first');
            // Sleep longer than the 0.4s threshold so the next event is
            // forced through the trim path.
            \usleep(600_000);
            $recorder->recordOutput('second');
        } finally {
            $recorder->close();
        }

        $loaded = (new JsonlFormat())->read($cassette);
        $this->assertCount(2, $loaded->events);

        $first = $loaded->events[0];
        $second = $loaded->events[1];

        // First event predates the trim window, no tRaw expected.
        $this->assertArrayNotHasKey('tRaw', $first->payload);

        // Second event MUST carry tRaw. The compressed t is the
        // trimmed gap (≤ 0.2s above the first event); tRaw is the
        // real elapsed time (≥ 0.6s).
        $this->assertArrayHasKey('tRaw', $second->payload);
        $tRaw = (float) $second->payload['tRaw'];
        $this->assertGreaterThanOrEqual(0.55, $tRaw, 'tRaw must reflect the real sleep');
        $this->assertLessThan(
            0.35,
            $second->t - $first->t,
            'compressed t must collapse the gap',
        );
        $this->assertGreaterThan(
            $second->t,
            $tRaw,
            'tRaw must exceed the compressed t when trimming kicks in',
        );
        @\unlink($cassette);
    }

    // ----- end-to-end record + replay --------------------------------

    public function testRecordIdleTrimAgainstRealSleepChild(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-trim-e2e-');
        // Use a unix-socket pair so the pump's stdin never EOFs while the
        // child is sleeping. `/dev/null` would hit EOF immediately and
        // the pump's stdinEofGraceSec (300 ms) would close the master
        // before the child wakes from sleep 1.2.
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$stdinRead, $stdinWrite] = $pair;
        $cmd = new RecordCommand($stdinRead);
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            // bash mini-script: print 'pre', sleep 1.2s, print 'post'.
            // With --idle-trim 0.4, the 'post' event should land with
            // tRaw ≈ 1.2 while its compressed t < 0.6.
            $rc = $cmd->run([
                '--output', $cassette,
                '--idle-trim', '0.4',
                '--',
                '/bin/bash', '-c',
                "printf 'pre\\n'; sleep 1.2; printf 'post\\n'",
            ], $stdout, $stderr);
            $this->assertSame(0, $rc);

            $loaded = (new JsonlFormat())->read($cassette);
            $outputEvents = [];
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $outputEvents[] = $event;
                }
            }
            $this->assertNotEmpty($outputEvents);

            // Walk the output events; identify the first one whose
            // payload bytes contain 'post'. That event is the canary.
            $postEvent = null;
            foreach ($outputEvents as $event) {
                if (\str_contains((string) ($event->payload['b'] ?? ''), 'post')) {
                    $postEvent = $event;
                    break;
                }
            }
            $this->assertNotNull($postEvent, 'cassette must record the post-sleep "post" line');
            $this->assertArrayHasKey(
                'tRaw',
                $postEvent->payload,
                "'post' event must carry tRaw because it follows a >0.4s sleep",
            );
            $tRaw = (float) $postEvent->payload['tRaw'];
            $this->assertGreaterThanOrEqual(
                1.0,
                $tRaw,
                'tRaw must reflect the ~1.2s sleep (allow 0.2s slack)',
            );
            $this->assertLessThan(
                1.0,
                $postEvent->t,
                'compressed t must collapse the sleep gap below 1.0s',
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

    public function testReplayDefaultUsesCompressedTimeline(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-trim-replay-');
        $pair2 = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair2);
        [$stdinRead2, $stdinWrite2] = $pair2;
        $rec = new RecordCommand($stdinRead2);
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $rec->run([
                '--output', $cassette,
                '--idle-trim', '0.4',
                '--',
                '/bin/bash', '-c',
                "printf 'pre\\n'; sleep 1.2; printf 'post\\n'",
            ], $stdout, $stderr);
            $this->assertSame(0, $rc);

            // Default replay (no --no-trim) must finish well under the
            // recorded sleep — uses the compressed t.
            $replayStdout = \fopen('php://memory', 'r+');
            $replayStderr = \fopen('php://memory', 'r+');
            try {
                $start = \microtime(true);
                $replayRc = (new ReplayCommand())->run(
                    [$cassette, '--speed=realtime'],
                    $replayStdout,
                    $replayStderr,
                );
                $elapsed = \microtime(true) - $start;
                $this->assertSame(0, $replayRc);
                $this->assertLessThan(1.0, $elapsed, 'compressed replay should not honour the 1.2s sleep');

                \rewind($replayStdout);
                $payload = (string) \stream_get_contents($replayStdout);
                $this->assertStringContainsString('pre', $payload);
                $this->assertStringContainsString('post', $payload);
            } finally {
                \fclose($replayStdout);
                \fclose($replayStderr);
            }
        } finally {
            if (\is_resource($stdinWrite2)) {
                @\fclose($stdinWrite2);
            }
            if (\is_resource($stdinRead2)) {
                @\fclose($stdinRead2);
            }
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }
}
