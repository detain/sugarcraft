<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Lang;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Recorder;
use SugarCraft\Core\Renderer;

/**
 * A model with no behaviour of its own — the exec-capture and recorder
 * wiring under test never reaches into it.
 */
final class ExecCaptureNullModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?Cmd}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'exec-capture';
    }
}

/**
 * sc-06 / sugar-core-002 + sugar-core-018: the exec capture loop carries a
 * per-stream byte cap, a wall deadline, and a transient-select retry in ONE
 * loop shape; both write twins treat stream_select() failure as transient
 * and record FLUSHED bytes only.
 *
 * The private legs are driven through reflection deliberately: the deadline
 * and cap are injectable parameters so the proof is bounded-time on the
 * real kernel machinery (pipes, socketpairs) rather than mocked streams —
 * flushed-byte truth is measured against what the kernel actually accepted.
 */
final class ProgramExecCaptureTest extends TestCase
{
    /**
     * @param list<mixed> $args
     */
    private function invokeStatic(string $class, string $method, array $args): mixed
    {
        $m = new \ReflectionMethod($class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /**
     * @return array{0:resource, 1:resource, 2:resource, 3:resource} proc, stdout, stderr, (stdout pipe mirror handled by drain)
     */
    private function spawn(array $argv): array
    {
        $proc = proc_open(
            $argv,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $this->assertIsResource($pipes[1]);
        $this->assertIsResource($pipes[2]);
        return [$proc, $pipes[1], $pipes[2]];
    }

    /**
     * @param resource|null $peer out-param: the reader end of the pair
     */
    private function cappedSocketPair(&$peer)
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$writer, $peer] = $sockets;
        if (defined('SO_SNDBUF') && function_exists('socket_import_stream')) {
            $socket = @socket_import_stream($writer);
            if ($socket !== false) {
                @socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 4096);
            }
        }
        $this->assertNotFalse(stream_set_blocking($writer, false));
        return $writer;
    }

    private function drainAll($sock, int $limit, float $timeout = 5.0): string
    {
        $this->assertNotFalse(stream_set_blocking($sock, false));
        $got   = '';
        $start = microtime(true);
        while (strlen($got) < $limit && (microtime(true) - $start) < $timeout) {
            $chunk = fread($sock, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                $r      = [$sock];
                $w      = $e = null;
                $ready  = @stream_select($r, $w, $e, 0, 50000);
                if ($ready === false || $ready === 0) {
                    break; // drained (or select cannot watch this handle)
                }
                continue;
            }
            $got .= $chunk;
        }
        return $got;
    }

    // ─── drainCapturedPipes: healthy capture ───────────────────────────────

    public function testDrainCapturesBothStreamsConcurrently(): void
    {
        [$proc, $out, $err] = $this->spawn(['sh', '-c', 'printf out; printf err >&2']);
        [$stdout, $stderr, $timedOut] = $this->invokeStatic(
            Program::class,
            'drainCapturedPipes',
            [$out, $err],
        );
        $this->assertSame('out', $stdout);
        $this->assertSame('err', $stderr);
        $this->assertFalse($timedOut);
        proc_close($proc);
    }

    // ─── 002 leg: running byte cap → truncate-with-marker ─────────────────

    public function testDrainTruncatesEachStreamWithMarkerAndStillCompletes(): void
    {
        [$proc, $out, $err] = $this->spawn([
            'sh',
            '-c',
            'head -c 300000 /dev/zero | tr "\\0" a; head -c 300000 /dev/zero | tr "\\0" b >&2',
        ]);
        $cap   = 1024;
        $t0    = microtime(true);
        [$stdout, $stderr, $timedOut] = $this->invokeStatic(
            Program::class,
            'drainCapturedPipes',
            [$out, $err, 15.0, $cap],
        );
        $marker = "\n...[capture truncated at {$cap} bytes]";
        $this->assertSame(str_repeat('a', $cap) . $marker, $stdout);
        $this->assertSame(str_repeat('b', $cap) . $marker, $stderr);
        $this->assertFalse($timedOut); // draining past the cap never blocks the child
        $this->assertLessThan(10.0, microtime(true) - $t0);
        proc_close($proc);
    }

    // ─── 002 leg: wall deadline bounds the wait ────────────────────────────

    public function testDrainDeadlineStopsWaitingOnANeverEndingChild(): void
    {
        [$proc, $out, $err] = $this->spawn(['sh', '-c', 'exec sleep 30']);
        $t0                 = microtime(true);
        [$stdout, $stderr, $timedOut] = $this->invokeStatic(
            Program::class,
            'drainCapturedPipes',
            [$out, $err, 0.3],
        );
        $elapsed = microtime(true) - $t0;
        $this->assertTrue($timedOut);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
        $this->assertGreaterThanOrEqual(0.2, $elapsed); // waited at least one tick past the 0.3 deadline check
        $this->assertLessThan(5.0, $elapsed);           // and never near the child's real 30s life
        proc_terminate($proc);
        proc_close($proc);
    }

    public function testTerminateAndReapIsBoundedAndCompletes(): void
    {
        [$proc] = $this->spawn(['sh', '-c', 'trap "" TERM; sleep 30; echo']);
        $t0     = microtime(true);
        // Trapped SIGTERM → the bound passes, SIGKILL escalates, the grace
        // tick reaps it. The reap completing is the whole point of the arm.
        $this->assertTrue($this->invokeStatic(Program::class, 'terminateAndReap', [$proc, 0.4]));
        $this->assertLessThan(5.0, microtime(true) - $t0);
    }

    // ─── 002 leg: select-false is transient, not "abandon the drain" ──────

    public function testDrainSelectFalseArmRetriesInsideTheDeadline(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__) . '/src/Program.php');
        $this->assertSame(
            1,
            preg_match(
                '/if \(@stream_select\(\$read, \$write, \$except, 1\) === false\) \{[^{}]*?usleep\(2000\);\s*continue;/',
                $src,
            ),
            'the drain loop must retry a transient select failure inside the deadline',
        );
        // The retry arm must not smuggle back the old single-strike exit.
        preg_match(
            '/if \(@stream_select\(\$read, \$write, \$except, 1\) === false\) \{(?<arm>[^{}]*?)continue;/',
            $src,
            $m,
        );
        $this->assertArrayHasKey('arm', $m);
        $this->assertStringNotContainsString('break', $m['arm']);
    }

    // ─── 018 legs: write twins — resume, retry-shape, deadline ────────────

    public function testWriteAllResumesShortWritesAtTheFwriteOffset(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$writer, $peer] = $sockets;
        if (function_exists('socket_import_stream')) {
            $sock = @socket_import_stream($writer);
            if ($sock !== false) {
                @socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 4096);
            }
        }
        $this->assertNotFalse(stream_set_blocking($writer, false));

        // A child drains the other end into a file, so short-write rounds
        // genuinely occur (an 8 KiB buffer cannot take a 4 MiB burst in one
        // go) AND the accepted bytes can be verified byte-for-byte after
        // the loop completes.
        $dump = sys_get_temp_dir() . '/sc06-sockdump-' . bin2hex(random_bytes(6)) . '.bin';
        $kid  = proc_open(
            ['sh', '-c', 'cat > ' . $dump],
            [0 => $peer, 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $kidPipes,
        );
        $this->assertIsResource($kid);
        fclose($peer);

        $payload = bin2hex(random_bytes(2 * 1024 * 1024)); // 4 MiB of unique bytes
        $t0      = microtime(true);
        $flushed = $this->invokeStatic(Renderer::class, 'writeAll', [$writer, $payload, 10.0]);
        $this->assertIsInt($flushed);
        // A non-blocking send cannot take a 4 MiB burst in one syscall into
        // a socket buffer two-to-fifty orders smaller, so multiple fwrite
        // rounds ran; the mutation probe (rewrite-from-zero) reddens the
        // byte-exact comparison below.
        $this->assertSame(strlen($payload), $flushed);

        // Bounded wait for the reader child to consume the rest of the buffer.
        $deadline = microtime(true) + 10.0;
        while ((microtime(true) < $deadline) && proc_get_status($kid)['running']) {
            usleep(20000);
        }
        proc_terminate($kid);
        proc_close($kid);

        $bytes = (string) @file_get_contents($dump);
        unlink($dump);
        $this->assertSame(
            substr($payload, 0, strlen($bytes)),
            $bytes,
            'kernel-accepted bytes must be the exact continuation — a re-sent prefix means the loop restarted from offset 0',
        );
        $this->assertLessThan(30.0, microtime(true) - $t0);
    }

    public function testWriteTwinsRetrySelectFalseInsteadOfDroppingTheTail(): void
    {
        foreach ([
            ['Renderer.php', '/if \(@stream_select\(\$r, \$w, \$e, 1\) === false\) \{\s*if \(\+\+\$selectFalse > self::MAX_SELECT_FALSE_RETRY\) \{/'],
            ['Program.php', '/if \(@stream_select\(\$r, \$w, \$e, 1\) === false\) \{\s*if \(\+\+\$selectFalse > Renderer::MAX_SELECT_FALSE_RETRY\) \{/'],
        ] as [$file, $pattern]) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/src/' . $file);
            $this->assertSame(
                1,
                preg_match($pattern, $src),
                "{$file}: stream_select false must enter a bounded retry, not a bare break/return",
            );
        }
    }

    // ─── 018 legs: recorder tee = FLUSHED bytes only ──────────────────────

    private function teeRecorder(array &$log): Recorder
    {
        $log = [];
        return new class ($log) implements Recorder {
            /** @param list<string> $log shared by reference with the test */
            public function __construct(private array &$log)
            {
            }

            public function recordResize(int $cols, int $rows): void
            {
            }

            public function recordInputBytes(string $bytes): void
            {
            }

            public function recordOutput(string $bytes): void
            {
                $this->log[] = $bytes;
            }

            public function recordQuit(): void
            {
            }

            public function close(): void
            {
            }
        };
    }

    public function testWriteOutputRecordsTheFlushedPrefixOfACappedStream(): void
    {
        $peer    = null;
        $writer  = $this->cappedSocketPair($peer);
        $memory  = fopen('php://memory', 'r');
        $program = new Program(
            new ExecCaptureNullModel(),
            new ProgramOptions(
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
                input: $memory,
                output: $writer,
                loop: new StreamSelectLoop(),
            ),
        );
        $log = [];
        $program->setRecorder($this->teeRecorder($log));

        $payload = bin2hex(random_bytes(131072));
        $m       = new \ReflectionMethod($program, 'writeOutput');
        $m->setAccessible(true);
        $m->invoke($program, $payload, 0.5);

        $recorded = implode('', $log);
        $this->assertNotSame('', $recorded);
        $this->assertLessThan(strlen($payload), strlen($recorded), 'the socket cap must bite before the payload ends');
        $this->assertSame(
            strlen($this->drainAll($peer, strlen($payload))),
            strlen($recorded),
            'the recorder must claim exactly the bytes the kernel accepted — no ghost tail',
        );
        $this->assertStringStartsWith($recorded, $payload);
    }

    public function testWriteOutputRecordsTheWholeChunkOnAFullFlush(): void
    {
        $out     = fopen('php://memory', 'w+');
        $program = new Program(
            new ExecCaptureNullModel(),
            new ProgramOptions(
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
                input: fopen('php://memory', 'r'),
                output: $out,
                loop: new StreamSelectLoop(),
            ),
        );
        $log = [];
        $program->setRecorder($this->teeRecorder($log));

        $m = new \ReflectionMethod($program, 'writeOutput');
        $m->setAccessible(true);
        $m->invoke($program, "\x1b[2Jframe", 0.5);

        $this->assertSame(["\x1b[2Jframe"], $log);
        rewind($out);
        $this->assertSame("\x1b[2Jframe", stream_get_contents($out));
    }

    public function testRendererEmitRecordsFlushedBytesOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Renderer.php');
        $this->assertSame(
            1,
            preg_match(
                '/\$flushed = self::writeAll\(\$this->out, \$bytes\);(?<body>.*?)recordOutput\(/s',
                $src,
                $m,
            ),
            'emit() must capture the flushed count from writeAll before recording',
        );
        $this->assertStringContainsString('$flushed', $m['body']);
        $this->assertStringNotContainsString(
            'recordOutput($bytes)',
            str_replace("\n", ' ', preg_replace('/^\s*\/\/.*$/m', '', $src) ?? $src),
            'no seat may tee the requested bytes as if they were written',
        );
    }

    // ─── 002 leg: structured failure message renders ──────────────────────

    public function testExecDeadlineMessageCarriesCommandDeadlineAndBytes(): void
    {
        $msg = Lang::t('program.exec_deadline_exceeded', [
            'cmd'     => 'sleep 30',
            'seconds' => Program::EXEC_WAIT_DEADLINE_SECONDS,
            'bytes'   => 17,
        ]);
        $this->assertStringContainsString('sleep 30', $msg);
        $this->assertStringContainsString('120', $msg);
        $this->assertStringContainsString('17', $msg);
    }

    // ─── sc-13 / sugar-core-011: Windows capture leg ──────────────────────

    public function testWindowsFallbackReadsBothStreamsNotSilentEmpty(): void
    {
        [$proc, $out, $err] = $this->spawn(['sh', '-c', 'printf out; printf err >&2']);
        [$stdout, $stderr, $timedOut] = $this->invokeStatic(
            Program::class,
            'drainCapturedPipes',
            [$out, $err, 5.0, 4096, true], // $onWindows=true drives the CRT leg
        );
        $this->assertSame('out', $stdout);
        $this->assertSame('err', $stderr);
        $this->assertFalse($timedOut);
        proc_close($proc);
    }

    public function testWindowsFallbackTruncatesWithMarkerPastCap(): void
    {
        // Small-stderr child by design: a child overflowing the unread
        // stderr pipe while stdout is still being read is the documented
        // sequential-read deadlock this leg carries (see docblock + README
        // Windows caveat) — asserting it would be testing the hazard, not
        // the cure.
        [$proc, $out, $err] = $this->spawn([
            'sh', '-c',
            'head -c 300000 /dev/zero | tr "\\0" a; printf e >&2',
        ]);
        $cap   = 1024;
        [$stdout, $stderr, $timedOut] = $this->invokeStatic(
            Program::class,
            'drainCapturedPipes',
            [$out, $err, 15.0, $cap, true],
        );
        $marker = "\n...[capture truncated at {$cap} bytes]";
        $this->assertSame(str_repeat('a', $cap) . $marker, $stdout);
        $this->assertSame('e', $stderr);
        $this->assertFalse($timedOut);
        proc_close($proc);
    }

    public function testWindowsGuardIsPlatformScopedNotStreamScoped(): void
    {
        $proc = proc_open(
            ['sh', '-c', 'true'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        // This box is not Windows: the override must be what opens the CRT
        // leg, and its absence must keep the POSIX loop (the $timedOut arm
        // proves the select path ran: a never-ending child times out there
        // but is simply read to EOF on the blocking leg).
        $this->assertFalse($this->invokeStatic(
            Program::class, 'streamSelectUnsupported', [$pipes[1], $pipes[2], null],
        ));
        $this->assertTrue($this->invokeStatic(
            Program::class, 'streamSelectUnsupported', [$pipes[1], $pipes[2], true],
        ));
        $this->assertFalse($this->invokeStatic(
            Program::class, 'streamSelectUnsupported', [null, null, true],
        )); // nothing to read — no notice, no fallback
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    public function testWindowsDegradationNoticeLocalizes(): void
    {
        $msg = Lang::t('program.exec_select_unsupported');
        $this->assertNotSame('program.exec_select_unsupported', $msg);
        $this->assertStringContainsStringIgnoringCase('stream-select', $msg);
    }

    public function testWindowsLegGatesSourceShape(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Program.php');
        $this->assertSame(
            1,
            preg_match(
                '/streamSelectUnsupported\(\$stdoutPipe, \$stderrPipe, \$onWindows\)\) \{(?<arm>.*?)return \[\$stdout, \$stderr, false\];/s',
                $src,
                $m,
            ),
            'the unsupported-platform arm must exist inside drainCapturedPipes',
        );
        $this->assertStringContainsString('fwrite(STDERR, Lang::t(', $m['arm']);
        $this->assertStringContainsString('blockingReadCapped($stdoutPipe', $m['arm']);
        // The blocking leg must keep draining past the cap, never stop
        // reading (that would re-create the child-parks-on-full-pipe bug).
        $this->assertSame(
            1,
            preg_match(
                '/private static function blockingReadCapped.*?if \(\$truncated\) \{\s*continue;/s',
                $src,
            ),
            'blockingReadCapped must keep draining past the cap',
        );
    }
}
