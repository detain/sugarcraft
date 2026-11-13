<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\HttpListener;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Support\WorkerFork;

/**
 * The 8b redesign as the client sees it: a pool-backed listener serves a
 * real HTTP request from a forked worker — bytes on the wire identical to
 * the serial loop's answer — and the parent never blocks in dispatch.
 * Refusal under pool pressure keeps the established-worker law, and a
 * silent seat dies on the IDLE deadline inside the worker (the parent's
 * sweep does not police worker-owned connections).
 */
final class HttpListenerWorkerTest extends TestCase
{
    private string $tmpDir;

    private ?StreamSelectLoop $loop = null;

    protected function setUp(): void
    {
        if (!WorkerFork::supported()) {
            $this->markTestSkipped('the fork pool needs POSIX with pcntl_*');
        }
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-worker-' . \uniqid('', true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->loop = null;
        $this->removeDirectory($this->tmpDir);
    }

    public function testARequestIsServedByAWorkerAndTheWireIsIdentical(): void
    {
        $pool = new WorkerFork();
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener($this->makeServer(), '127.0.0.1', 0, $loop, workers: $pool);
        $listener->start();

        $address = (string) $listener->listenAddress();
        $client = @\stream_socket_client('tcp://' . $address, $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect {$address}: {$errstr}");

        // The loop runs until the WORKER'S answer lands (the accept tick
        // forks and returns; nothing else in the parent has to happen).
        $response = '';
        \stream_set_blocking($client, false);
        $sent = false;
        $deadline = \microtime(true) + 8.0;
        $loop->addPeriodicTimer(0.02, function () use ($loop, $client, &$response, &$sent, $deadline, $pool): void {
            if (!$sent) {
                \fwrite($client, "GET /unknown-repo.git/info/refs?service=git-upload-pack HTTP/1.1\r\nHost: x\r\n\r\n");
                $sent = true;
            }
            $chunk = \fread($client, 8192);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
            }
            if (\feof($client) || \str_contains($response, "\r\n\r\n")) {
                $pool->reap(); // parent-side bookkeeping tick, as sweepDeadlines does
                $loop->stop();
            }
            if (\microtime(true) > $deadline) {
                $loop->stop();
            }
        });
        $loop->run();

        $this->assertStringContainsString('HTTP/1.1 ', $response, 'the worker answer must reach the wire');
        $this->assertStringContainsString('404', $response, 'an unknown repo is a 404 from either concurrency mode');
        \fclose($client);

        // The worker ran, answered, and exited: the seat frees itself.
        $until = \microtime(true) + 6.0;
        while ($pool->live() > 0 && \microtime(true) < $until) {
            \usleep(20_000);
        }
        $this->assertSame(0, $pool->live(), 'the finished worker must leave the pool');
        $this->assertSame(1, $pool->totalSpawned());
        $this->assertSame(0, $listener->activeConnectionCount());

        $listener->stop();
    }

    public function testPoolPressureRefusesTheNewcomerAndKeepsTheEstablishedWorker(): void
    {
        $pool = new WorkerFork(maxWorkers: 1);
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        $listener = new HttpListener($this->makeServer(), '127.0.0.1', 0, $loop, workers: $pool);
        $listener->start();
        $address = (string) $listener->listenAddress();

        // Seat one: a SILENT connection (no request bytes) — its worker
        // lives until the idle deadline; it must not be cut.
        $first = @\stream_socket_client('tcp://' . $address, $errno, $errstr, 2.0);
        $this->assertIsResource($first);
        $this->runLoopFor(0.4);
        $this->assertSame(1, $pool->live(), 'the silent seat is a live worker');

        // Newcomer while the house is full: refused (its socket dies),
        // established worker untouched.
        $second = @\stream_socket_client('tcp://' . $address, $errno, $errstr, 2.0);
        $this->assertIsResource($second);
        $this->runLoopFor(0.4);
        $this->assertTrue($this->peerClosed($second), 'the newcomer must be closed by the capacity arm');
        $this->assertSame(1, $pool->live(), 'refusing a newcomer never cuts an established worker');

        \fclose($first);
        \fclose($second);
        $listener->stop();
        $this->assertSame(0, $pool->live(), 'stop() terminates the pool');
    }

    public function testSilentSeatDiesOnTheIdleDeadlineInsideTheWorker(): void
    {
        $pool = new WorkerFork();
        $loop = new StreamSelectLoop();
        $this->loop = $loop;
        // The idle deadline is the WORKER's own clock now (the parent's
        // sweep only reaps exited workers); short enough for a test, the
        // ceiling alarm is the backstop above it.
        $listener = new HttpListener(
            $this->makeServer(),
            '127.0.0.1',
            0,
            $loop,
            idleTimeoutSeconds: 0.3,
            absoluteDeadlineSeconds: 60.0,
            workers: $pool
        );
        $listener->start();

        $client = @\stream_socket_client('tcp://' . $listener->listenAddress(), $errno, $errstr, 2.0);
        $this->assertIsResource($client);
        $this->runLoopFor(1.2);

        $this->assertTrue($this->peerClosed($client), 'the worker must drop a silent seat on its idle deadline');
        $until = \microtime(true) + 6.0;
        while ($pool->live() > 0 && \microtime(true) < $until) {
            \usleep(20_000);
        }
        $this->assertSame(0, $pool->live(), 'an idle-dropped seat exits the pool');

        \fclose($client);
        $listener->stop();
    }

    private function makeServer(): Server
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: \"Worker\"\ngit: { max_timeout: 1, allow_anonymous_read: true }\n"
        );

        return new Server(Config::load($this->tmpDir . '/config.yaml'));
    }

    /** @param resource $client */
    private function peerClosed($client): bool
    {
        \stream_set_blocking($client, false);
        $data = \fread($client, 1);

        return $data === false || ($data === '' && \feof($client));
    }

    private function runLoopFor(float $seconds): void
    {
        $loop = $this->loop ?? new StreamSelectLoop();
        $loop->addTimer($seconds, static fn () => $loop->stop());
        $loop->run();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
