<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit B6: the turn's frame stream must fail LOUDLY.
 *
 * Before the fix the child's writeFrame() returned silently on a short write
 * (a stalled parent past `default_socket_timeout`), leaving half a frame on the
 * wire and running on; and the parent's drainFrames() answered a bad header by
 * dropping its buffer and parsing on, so every later frame — the result frame
 * included — was read at a meaningless offset and the turn ended as "exited
 * without a result" even when the child had finished its work.
 */
final class EngineBackendFrameCorruptionTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dir = '';

    private string $savedSocketTimeout = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/frame_corrupt_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
        $this->savedSocketTimeout = (string) \ini_get('default_socket_timeout');
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        \ini_set('default_socket_timeout', $this->savedSocketTimeout);
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);

        parent::tearDown();
    }

    public function testABadHeaderIsDeclaredCorruptAndTheFramesBeforeItSurvive(): void
    {
        $good = self::frame(['kind' => 'token', 'text' => 'before']);
        $buffer = $good . \pack('N', 0) . self::frame(['kind' => 'result', 'ok' => true]);

        $corrupt = false;
        $frames = self::drain($buffer, $corrupt);

        self::assertTrue($corrupt, 'a zero-length header must be reported, not silently skipped');
        self::assertSame([['kind' => 'token', 'text' => 'before']], $frames);
        self::assertSame('', $buffer);
    }

    public function testATruncatedFrameFollowedByAValidOneIsDeclaredCorrupt(): void
    {
        // The B6 repro's wire, in miniature: a frame cut short by a give-up
        // write, then the child's next frame. The valid frame lands INSIDE the
        // truncated one's declared length, so its body is not a frame.
        $big = self::frame(['kind' => 'token', 'text' => \str_repeat('x', 200)]);
        $buffer = \substr($big, 0, 60) . self::frame(['kind' => 'result', 'ok' => true])
            . \str_repeat("\0", \strlen($big));

        $corrupt = false;
        $frames = self::drain($buffer, $corrupt);

        self::assertTrue($corrupt, 'an undecodable body must be reported as a broken stream');
        self::assertSame([], $frames);
    }

    public function testAWholeValidStreamIsNotCorrupt(): void
    {
        $buffer = self::frame(['kind' => 'token', 'text' => 'a']) . \substr(self::frame(['kind' => 'token', 'text' => 'b']), 0, 3);

        $corrupt = false;
        $frames = self::drain($buffer, $corrupt);

        self::assertFalse($corrupt, 'a trailing PARTIAL frame is just the next read\'s job');
        self::assertCount(1, $frames);
        self::assertSame(3, \strlen($buffer));
    }

    public function testAChildThatEndsMidFrameRejectsAsACorruptedStream(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill') || !\is_dir('/proc/self/fd')) {
            self::markTestSkipped('needs a forked turn child and /proc/self/fd to reach its frame socket.');
        }

        $before = self::socketInodes();
        // The tool runs in the turn child, where the only socket the test
        // process did not already hold is the child's end of the frame pair.
        // It writes a header promising 1000 bytes, delivers ten, and dies —
        // exactly the wire a give-up write leaves behind.
        $midFrame = new class ($before) implements Tool {
            /** @param list<string> $before */
            public function __construct(private array $before)
            {
            }

            public function name(): string
            {
                return 'cut';
            }

            public function description(): string
            {
                return 'writes half a frame to the turn socket, then dies';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                foreach (\scandir('/proc/self/fd') ?: [] as $entry) {
                    if (!\ctype_digit($entry) || !\preg_match('/socket:\[(\d+)\]/', (string) @\readlink('/proc/self/fd/' . $entry), $m)) {
                        continue;
                    }
                    if (\in_array($m[1], $this->before, true)) {
                        continue;
                    }
                    $out = @\fopen('php://fd/' . $entry, 'w');
                    if ($out !== false) {
                        @\fwrite($out, \pack('N', 1000) . \str_repeat('y', 10));
                        @\fflush($out);
                    }
                }
                \posix_kill(\posix_getpid(), 9);

                return new ToolResult(toolCallId: 'unreachable', content: '', isError: true, durationMs: 0);
            }
        };

        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'cut', [])]),
            new CompleteResponse(content: 'unreachable'),
        ]);
        $backend = EngineBackend::new($provider, 'm')->withTools([$midFrame]);

        [$error] = $this->settle($backend->completeAsync([Message::user('go')]));

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('frame stream corrupted', $error->getMessage());
    }

    public function testAnOversizedFrameTearsTheTurnDownAsCorrupted(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks with ext-pcntl + ext-posix.');
        }

        // A reply one byte past the frame cap: its token frame's header is the
        // first thing the parent reads, and it is a header no valid frame can
        // carry. Before B6 the parent dropped it and parsed the 64 MiB body
        // that followed as headers, losing the result frame with it.
        $provider = new ScriptedProvider([new CompleteResponse(content: \str_repeat('z', EngineBackend::MAX_FRAME_BYTES + 1))]);
        $backend = EngineBackend::new($provider, 'm');

        [$error] = $this->settle($backend->completeAsync([Message::user('go')]));

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('frame stream corrupted', $error->getMessage());
    }

    public function testAParentStalledPastTheSocketTimeoutStillGetsEveryFrame(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks with ext-pcntl + ext-posix.');
        }

        // The socket pair takes its timeout from this ini AT CREATION, so it
        // is set before completeAsync() builds it. 4 MB cannot fit the socket
        // buffer, so the child's write blocks for as long as the parent below
        // is not reading — three times the timeout.
        \ini_set('default_socket_timeout', '1');
        $reply = \str_repeat('w', 4_000_000);
        $provider = new ScriptedProvider([new CompleteResponse(content: $reply)]);
        $backend = EngineBackend::new($provider, 'm');

        $promise = $backend->completeAsync([Message::user('go')]);
        \usleep(3_000_000);
        [$error, $message] = $this->settle($promise);

        self::assertNull($error, 'the stalled turn failed: ' . ($error?->getMessage() ?? ''));
        self::assertInstanceOf(Message::class, $message);
        self::assertSame(\strlen($reply), \strlen($message->content));
    }

    public function testAWriteToADeadPeerEndsTheWriterInsteadOfReturning(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-pcntl + ext-posix to fork the writer.');
        }

        $marker = $this->dir . '/returned';
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$parentEnd, $childEnd] = $pair;
        // Closed before the fork, so the writer holds the ONLY remaining end
        // and its first write fails (EPIPE) — the dead-parent case.
        \fclose($parentEnd);

        $pid = $this->forkTracked();
        if ($pid === 0) {
            $write = new \ReflectionMethod(EngineBackend::class, 'writeFrame');
            $write->invoke(null, $childEnd, ['kind' => 'token', 'text' => 'nobody is listening']);
            // Reached only if writeFrame() gave up silently and returned.
            \file_put_contents($marker, 'returned');
            ForkedChild::exitNow(0);
        }
        \fclose($childEnd);

        $status = 0;
        $deadline = \microtime(true) + 10.0;
        while (\pcntl_waitpid($pid, $status, \WNOHANG) === 0 && \microtime(true) < $deadline) {
            \usleep(10_000);
        }

        self::assertFileDoesNotExist($marker, 'writeFrame() returned after a failed write and the child ran on');
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function frame(array $frame): string
    {
        $body = \serialize($frame);

        return \pack('N', \strlen($body)) . $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function drain(string &$buffer, bool &$corrupt): array
    {
        $drain = new \ReflectionMethod(EngineBackend::class, 'drainFrames');

        return $drain->invokeArgs(null, [&$buffer, &$corrupt]);
    }

    /**
     * @return list<string>
     */
    private static function socketInodes(): array
    {
        $inodes = [];
        foreach (\scandir('/proc/self/fd') ?: [] as $entry) {
            if (\preg_match('/socket:\[(\d+)\]/', (string) @\readlink('/proc/self/fd/' . $entry), $m)) {
                $inodes[] = $m[1];
            }
        }

        return $inodes;
    }

    /**
     * Run the loop until $promise settles (bounded).
     *
     * @return array{0: ?\Throwable, 1: mixed}
     */
    private function settle(\React\Promise\PromiseInterface $promise): array
    {
        $loop = Loop::get();
        $done = false;
        $error = null;
        $value = null;
        $promise->then(
            static function ($resolved) use (&$done, &$value, $loop): void {
                $done = true;
                $value = $resolved;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$done, &$error, $loop): void {
                $done = true;
                $error = $e;
                $loop->stop();
            },
        );
        if (!$done) {
            $guard = $loop->addTimer(30.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }
        self::assertTrue($done, 'the turn never settled');

        return [$error, $value];
    }
}
