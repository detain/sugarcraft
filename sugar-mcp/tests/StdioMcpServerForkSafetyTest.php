<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\ExchangeLock;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * One server, many processes (audit B1 / AG-1): the embedder starts the server
 * and then uses the SAME object from pcntl_fork()ed children, exactly as
 * sugar-crush's forked turns and parallel sub-agents do.
 *
 * Every child reports through its own temp file and ends with SIGKILL — never
 * exit(), which would run PHPUnit's shutdown machinery in the copy. Every wait
 * is bounded and only this test's own children are ever signalled.
 */
final class StdioMcpServerForkSafetyTest extends TestCase
{
    private const CHILD_DEADLINE_SECONDS = 15.0;

    private ?StdioMcpServer $server = null;

    /** @var array<int, string> pid => result file */
    private array $children = [];

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-pcntl and ext-posix to fork');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        foreach ($this->children as $file) {
            @unlink($file);
        }
        $this->children = [];

        $this->server?->stop();
        $this->server = null;
    }

    public function testAKilledCallsLateReplyIsNeverHandedToTheNextProcess(): void
    {
        $server = $this->startServer();

        // Turn 1: calls `slow`, and is SIGKILLed mid-call (Esc / watchdog).
        $turn1 = $this->fork(static fn (): array => $server->callTool('slow', []));
        usleep(300_000);
        posix_kill($turn1, SIGKILL);
        $this->reapKilled($turn1);

        // Turn 2 is forked from the same parent while `slow` is still running:
        // its stale reply arrives first on the shared stdout.
        $turn2 = $this->fork(static fn (): array => $server->callTool('fast', []));
        $result = $this->reap($turn2);

        self::assertSame('result of fast', $result['content'][0]['text'] ?? null, 'turn 2 must get the tool it asked for');

        // And the stream is still in step for the owner afterwards.
        self::assertSame('echo:parent', $server->callTool('echo', ['n' => 'parent'])['content'][0]['text'] ?? null);
    }

    public function testConcurrentForkedCallersEachGetTheirOwnReply(): void
    {
        $server = $this->startServer();

        // Several rounds: which racing reader wins a shared pipe is up to the
        // scheduler, so one round of a broken transport can pass by luck
        // (measured: ~2 in 5); three rounds of four almost never do.
        for ($round = 0; $round < 3; $round++) {
            $pids = [];
            foreach (['a', 'b', 'c', 'd'] as $tag) {
                $n = "r{$round}-{$tag}";
                $pids[$n] = $this->fork(static fn (): array => $server->callTool('echo', ['n' => $n]));
            }

            foreach ($pids as $n => $pid) {
                $result = $this->reap($pid);
                self::assertSame("echo:{$n}", $result['content'][0]['text'] ?? null, "{$n} must receive its own reply");
            }
        }

        self::assertSame('echo:after', $server->callTool('echo', ['n' => 'after'])['content'][0]['text'] ?? null);
    }

    public function testANonOwnerStopOrDestructLeavesTheSharedServerRunning(): void
    {
        $server = $this->startServer();
        $lockPath = $this->lockPath($server);

        $child = $this->fork(static function () use ($server): array {
            $upInChild = $server->isUp();
            $server->stop();
            $server->__destruct();

            return ['upInChild' => $upInChild];
        });
        $report = $this->reap($child);

        self::assertTrue($report['upInChild'] ?? null, 'a forked process must see the shared server as up');
        self::assertTrue($server->isUp(), 'a non-owner stop must not signal the server');
        self::assertFileExists($lockPath, 'a non-owner stop must not unlink the shared lock');
        self::assertSame('echo:still', $server->callTool('echo', ['n' => 'still'])['content'][0]['text'] ?? null);

        $server->stop();
        self::assertFileDoesNotExist($lockPath, 'the owner stop removes the lock file');
        self::assertFalse($server->isUp());
    }

    public function testAHalfWrittenRequestFromADeadHolderIsTerminatedBeforeTheNextLine(): void
    {
        $server = $this->startServer();

        // What a process SIGKILLed mid-write leaves: half a line in the
        // server's stdin and the W marker in the lock file.
        fwrite($this->pipes($server)[0], '{"jsonrpc":"2.0","id":"dead-1","method":"tools/ca');
        file_put_contents($this->lockPath($server), ExchangeLock::PHASE_WRITING);

        $reply = $server->request('tools/call', ['name' => 'echo', 'arguments' => ['n' => 'next']], self::deadline());

        self::assertNotNull($reply, 'the request must not be glued onto the fragment');
        self::assertSame('echo:next', $reply->result['content'][0]['text'] ?? null);
        self::assertSame(ExchangeLock::PHASE_CLEAN, file_get_contents($this->lockPath($server))[0] ?? null);
    }

    public function testAHalfConsumedReplyFromADeadHolderIsSkippedNotFatal(): void
    {
        $server = $this->startServer();
        $pipes = $this->pipes($server);

        // A dead holder's exchange: its request went out whole, and it had read
        // the first bytes of the reply when it was killed.
        fwrite($pipes[0], '{"jsonrpc":"2.0","id":"dead-2","method":"tools/call","params":{"name":"fast","arguments":{}}}' . "\n");
        $read = [$pipes[1]];
        $write = $except = [];
        self::assertSame(1, stream_select($read, $write, $except, 5), 'the fixture must answer');
        self::assertSame(12, strlen((string) fread($pipes[1], 12)));
        file_put_contents($this->lockPath($server), ExchangeLock::PHASE_READING . 'stale-bytes');

        $reply = $server->request('tools/call', ['name' => 'echo', 'arguments' => ['n' => 'resync']], self::deadline());

        self::assertNotNull($reply, 'the fragment of the dead reply must be skipped during recovery');
        self::assertSame('echo:resync', $reply->result['content'][0]['text'] ?? null);
    }

    public function testAFailedExchangeLeavesItsMarkerForTheNextHolder(): void
    {
        $server = $this->startServer();

        // A deadline that expires while the reply is still being produced.
        $reply = $server->request('tools/call', ['name' => 'slow', 'arguments' => new \stdClass()], self::deadline(0.2));

        self::assertNull($reply);
        self::assertSame(ExchangeLock::PHASE_READING, file_get_contents($this->lockPath($server))[0] ?? null);

        // The late `slow` reply is skipped by id; the next call is answered.
        self::assertSame('echo:later', $server->callTool('echo', ['n' => 'later'])['content'][0]['text'] ?? null);
    }

    public function testExchangesAreMutuallyExclusiveAcrossProcesses(): void
    {
        $server = $this->startServer();
        $lockPath = $this->lockPath($server);

        // A child holds the lock (as a long exchange would) for a while.
        $child = $this->fork(static function () use ($lockPath): array {
            $handle = fopen($lockPath, 'r+');
            flock($handle, LOCK_EX);
            usleep(1_500_000);

            return ['held' => true];
        });
        usleep(300_000);

        $started = hrtime(true);
        $reply = $server->request('tools/call', ['name' => 'fast', 'arguments' => new \stdClass()], self::deadline(0.4));
        self::assertNull($reply, 'the owner must wait for the holder, not interleave');
        self::assertGreaterThanOrEqual(0.35, (hrtime(true) - $started) / 1e9);

        $this->reap($child);

        self::assertSame('result of fast', $server->callTool('fast', [])['content'][0]['text'] ?? null);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function startServer(): StdioMcpServer
    {
        $this->server = new StdioMcpServer(
            'fork-fixture',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/fork_server.php'],
            startTimeoutSeconds: 10.0,
        );
        $this->server->start();
        self::assertCount(3, $this->server->listTools());

        return $this->server;
    }

    private static function deadline(float $seconds = 5.0): float
    {
        return hrtime(true) / 1_000_000_000.0 + $seconds;
    }

    private function lockPath(StdioMcpServer $server): string
    {
        $lock = (new \ReflectionProperty(StdioMcpServer::class, 'lock'))->getValue($server);
        self::assertInstanceOf(ExchangeLock::class, $lock);

        return $lock->path;
    }

    /** @return array<int, resource> */
    private function pipes(StdioMcpServer $server): array
    {
        /** @var array<int, resource> $pipes */
        $pipes = (new \ReflectionProperty(StdioMcpServer::class, 'pipes'))->getValue($server);

        return $pipes;
    }

    /** @param \Closure(): array<string, mixed> $work */
    private function fork(\Closure $work): int
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'mcp-fork-test-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            try {
                file_put_contents($file, json_encode(['ok' => $work()]));
            } catch (\Throwable $thrown) {
                file_put_contents($file, json_encode(['thrown' => $thrown::class . ': ' . $thrown->getMessage()]));
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $this->children[$pid] = $file;

        return $pid;
    }

    private function reapKilled(int $pid): void
    {
        pcntl_waitpid($pid, $status);
        @unlink($this->children[$pid]);
        unset($this->children[$pid]);
        self::assertTrue(pcntl_wifsignaled($status), 'the child was meant to die mid-call');
    }

    /** @return array<string, mixed> what the child's work returned ([] if it reported nothing) */
    private function reap(int $pid): array
    {
        $deadline = hrtime(true) + (int) (self::CHILD_DEADLINE_SECONDS * 1e9);
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (hrtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                unset($this->children[$pid]);
                self::fail("child {$pid} did not finish within " . self::CHILD_DEADLINE_SECONDS . 's');
            }
            usleep(10_000);
        }

        $file = $this->children[$pid];
        unset($this->children[$pid]);
        $raw = (string) @file_get_contents($file);
        @unlink($file);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "child {$pid} reported nothing");
        self::assertArrayNotHasKey('thrown', $decoded, (string) ($decoded['thrown'] ?? ''));

        /** @var array<string, mixed> */
        return is_array($decoded['ok'] ?? null) ? $decoded['ok'] : [];
    }
}
