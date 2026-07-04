<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Subscription;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Git\GitDaemon;
use SugarCraft\Serve\Repo;

/**
 * Async (event-loop) mode of the Git daemon — plan item 6.1.
 *
 * These tests drive real TCP sockets through a StreamSelectLoop with a
 * safety timer, mirroring how the sync tests use real repos/paths.
 *
 * @covers \SugarCraft\Serve\Git\GitDaemon
 */
final class GitDaemonAsyncTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/git-daemon-async-test-' . \uniqid();
        \mkdir($this->tmpDir . '/repositories', 0755, true);

        // Port 0 = kernel-assigned ephemeral port, read back via listenAddress().
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: \"Test\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, max_connections: 8 }\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    /** Create a real git repo with one commit so refs()/branches() work. */
    private function createGitRepo(string $name): Repo
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path, 0755, true);
        $escaped = \escapeshellarg($path);
        \exec("git -C {$escaped} -c init.defaultBranch=main init 2>&1");
        \file_put_contents($path . '/hello.txt', "hello\n");
        \exec("git -C {$escaped} add hello.txt 2>&1");
        \exec("git -C {$escaped} -c user.email=test@example.com -c user.name=Test commit -m init 2>&1");

        return Repo::new($name, $path)->withPublic(true);
    }

    // -------------------------------------------------------------------------
    // End-to-end request over the loop
    // -------------------------------------------------------------------------

    public function testAsyncAcceptLoopServesUploadPackRefAdvertisement(): void
    {
        $repo = $this->createGitRepo('test-repo');
        $head = $repo->refs()['refs/heads/main'] ?? '';
        $this->assertSame(40, \strlen($head), 'fixture repo must have a commit on main');

        $daemon = new GitDaemon($this->config);
        $daemon->registerRepo($repo);

        $loop = new StreamSelectLoop();
        $promise = $daemon->serveAsync($loop);
        $promise->then(null, static fn () => null);

        $addr = $daemon->listenAddress();
        $this->assertNotNull($addr);
        $this->assertStringStartsWith('127.0.0.1:', $addr);
        $this->assertTrue($daemon->isRunning());

        $errno = 0;
        $errstr = '';
        $client = \stream_socket_client("tcp://{$addr}", $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect failed: {$errstr}");
        \stream_set_blocking($client, false);
        \fwrite($client, "git-upload-pack /test-repo\n");

        $response = '';
        $loop->addReadStream($client, static function () use (&$response, $client, $loop): void {
            $chunk = \fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;

                return;
            }
            if ($chunk === false || \feof($client)) {
                $loop->removeReadStream($client);
                $loop->stop();
            }
        });

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);
        \fclose($client);

        // Ref advertisement pkt-line + flush, then the daemon closes (no wants).
        $this->assertStringContainsString("{$head} refs/heads/main", $response);
        $this->assertStringContainsString("\x00\x00\x00\x00", $response);

        // Tear down so nothing leaks into other tests.
        $daemon->shutdown();
        $loop->run();
        $this->assertFalse($daemon->isRunning());
    }

    // -------------------------------------------------------------------------
    // Graceful shutdown
    // -------------------------------------------------------------------------

    public function testShutdownInAsyncModeResolvesPromiseAndUnsubscribes(): void
    {
        $daemon = new GitDaemon($this->config);

        $sub = new class implements Subscription {
            public bool $active = true;

            public function unsubscribe(): void
            {
                $this->active = false;
            }

            public function isActive(): bool
            {
                return $this->active;
            }
        };
        $daemon->addSubscription($sub);

        $loop = new StreamSelectLoop();
        $pidFile = $this->tmpDir . '/daemon.pid';
        $promise = $daemon->serveAsync($loop, $pidFile);

        $this->assertFileExists($pidFile);
        $this->assertTrue($daemon->isRunning());

        $exitCode = null;
        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $promise->then(static function (int $code) use (&$exitCode, $loop, $safety): void {
            $exitCode = $code;
            $loop->cancelTimer($safety);
        });

        $loop->futureTick(static fn () => $daemon->shutdown());
        $loop->run();

        $this->assertSame(0, $exitCode);
        $this->assertFalse($daemon->isRunning());
        $this->assertFalse($sub->isActive(), 'graceful shutdown must unsubscribe registered subscriptions');
        $this->assertFileDoesNotExist($pidFile);
        $this->assertNull($daemon->listenAddress());
    }

    // -------------------------------------------------------------------------
    // Loop-resource cleanup
    // -------------------------------------------------------------------------

    public function testStopDeregistersAllLoopResources(): void
    {
        $daemon = new GitDaemon($this->config);
        $loop = new StreamSelectLoop();
        $promise = $daemon->serveAsync($loop);

        // Open a connection with a partial request so a per-client read
        // stream is registered and stays registered until teardown.
        $errno = 0;
        $errstr = '';
        $client = \stream_socket_client('tcp://' . $daemon->listenAddress(), $errno, $errstr, 2.0);
        $this->assertIsResource($client);
        \fwrite($client, 'git-upload-pack /incomplete');

        $connectionsBeforeStop = null;
        $loop->addTimer(0.2, static function () use ($daemon, &$connectionsBeforeStop): void {
            $connectionsBeforeStop = $daemon->activeConnections();
            $daemon->shutdown();
        });

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $promise->then(static fn () => $loop->cancelTimer($safety));
        $loop->run();
        \fclose($client);

        $this->assertSame(1, $connectionsBeforeStop, 'client connection must be accepted before stop');
        $this->assertSame(0, $daemon->activeConnections());

        // No leaked read/write streams inside the loop after stop.
        $readStreams = (new \ReflectionProperty(StreamSelectLoop::class, 'readStreams'))->getValue($loop);
        $writeStreams = (new \ReflectionProperty(StreamSelectLoop::class, 'writeStreams'))->getValue($loop);
        $this->assertSame([], $readStreams);
        $this->assertSame([], $writeStreams);
    }

    // -------------------------------------------------------------------------
    // Guard rails
    // -------------------------------------------------------------------------

    public function testServeAsyncWhileRunningThrows(): void
    {
        $daemon = new GitDaemon($this->config);
        $loop = new StreamSelectLoop();
        $daemon->serveAsync($loop);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already running');
            $daemon->serveAsync($loop);
        } finally {
            $daemon->shutdown();
            $loop->run();
        }
    }

    public function testServeAsyncThrowsWhenPortUnavailable(): void
    {
        $daemon1 = new GitDaemon($this->config);
        $loop = new StreamSelectLoop();
        $daemon1->serveAsync($loop);
        $addr = $daemon1->listenAddress();
        $this->assertNotNull($addr);

        // Second config pinned to the exact port daemon1 already holds.
        $dir2 = $this->tmpDir . '/second';
        \mkdir($dir2, 0755, true);
        \file_put_contents($dir2 . '/config.yaml', "git: { listen_addr: \"{$addr}\" }\n");
        $daemon2 = new GitDaemon(Config::load($dir2 . '/config.yaml'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to create server socket');
            $daemon2->serveAsync($loop);
        } finally {
            $daemon1->shutdown();
            $loop->run();
        }
    }
}
