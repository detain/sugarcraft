<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Subscription;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Stats;

/**
 * Async (event-loop) mode of the Git daemon — plan item 6.1.
 *
 * These tests drive real TCP sockets through a StreamSelectLoop with a
 * safety timer, mirroring how the sync tests use real repos/paths.
 *
 * @covers \SugarCraft\GitServer\Git\GitDaemon
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
            "name: \"Test\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, max_connections: 8, allow_anonymous_read: true }\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');

        // This whole fixture file models the published-repo world (repos
        // created withPublic); the C-13 default-off keystone lives in
        // AnonymousReadLeverTest.
        \SugarCraft\GitServer\AccessControl::getInstance()->setAllowAnonymousRead(true);
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

        // Published repo: the fixture world arms git.allow_anonymous_read
        // in config.yaml (the C-13 default-off keystone lives in
        // AnonymousReadLeverTest and the daemon unit gate pins).
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
        // Half-close after the request line: the delegated `git upload-pack`
        // child advertises then sees EOF on its stdin and exits, so the pump
        // releases the client promptly instead of idling to its deadline.
        \stream_socket_shutdown($client, \STREAM_SHUT_WR);

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
        // Changed 2026-10-24 (the smart-HTTP transport round): this pin used to
        // require the daemon's binary pack('N',0) flush (\x00\x00\x00\x00) —
        // a wire shape no git client accepts. The daemon now frames through
        // the shared PktLine codec, so the flush is the ASCII 0000 pkt.
        // Daemon transport behavior is otherwise unchanged (still refused
        // in cmdServe); framing correctness is the whole point of the flip.
        $this->assertStringContainsString("0000", $response);
        $this->assertStringNotContainsString("\x00\x00\x00\x00", $response);

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

    // -------------------------------------------------------------------------
    // Capacity accounting (n14)
    // -------------------------------------------------------------------------

    /**
     * Structural pin: BOTH accept paths must run the capacity gate before
     * recording the connection in Stats. The blocking transport's e2e runs
     * in a child process whose counters the parent cannot read, so the
     * ordering law for both paths is pinned at the source level; the async
     * path additionally gets the behavioral pin below.
     */
    public function testBothAcceptPathsGateCapacityBeforeRecordingTheConnection(): void
    {
        $source = (string) \file_get_contents((new \ReflectionClass(GitDaemon::class))->getFileName());
        foreach (['acceptConnection', 'acceptAsyncConnection'] as $method) {
            $start = \strpos($source, "private function {$method}(): void");
            $this->assertIsInt($start, "{$method} must keep its private declaration");
            $next = \strpos($source, 'private function', $start + 1);
            $body = \substr($source, $start, $next === false ? null : $next - $start);

            $this->assertSame(
                1,
                \substr_count($body, 'recordConnection'),
                "{$method} must carry exactly one connection record",
            );
            $gate = \strpos($body, 'gitMaxConnections');
            $record = \strpos($body, 'recordConnection');
            $this->assertIsInt($gate, "{$method} must consult the capacity limit");
            $this->assertLessThan(
                $record,
                $gate,
                "{$method} must refuse at capacity BEFORE recording: an over-capacity probe must not bump the handled-connections figure",
            );
        }
    }

    public function testACapacityRefusedNewcomerIsNotCountedAsHandled(): void
    {
        $dir = $this->tmpDir . '/cap';
        \mkdir($dir, 0755, true);
        \file_put_contents(
            $dir . '/config.yaml',
            "git: { listen_addr: \"127.0.0.1:0\", idle_timeout: 30, max_connections: 1 }\n"
        );

        $daemon = new GitDaemon(Config::load($dir . '/config.yaml'));
        $stats = new Stats();
        $daemon->setStats($stats);

        $loop = new StreamSelectLoop();
        $promise = $daemon->serveAsync($loop);
        $promise->then(null, static fn () => null);
        $addr = $daemon->listenAddress();
        $this->assertNotNull($addr);

        // All socket work rides loop timers so the accept ordering (seat A,
        // then refuse B) is driven by the very loop under test.
        $a = null;
        $b = null;
        $refused = null;
        $loop->addTimer(0.2, static function () use ($addr, &$a): void {
            $a = @\stream_socket_client("tcp://{$addr}", $errno, $errstr, 2.0);
        });
        $loop->addTimer(0.5, static function () use ($addr, &$b): void {
            $b = @\stream_socket_client("tcp://{$addr}", $errno, $errstr, 2.0);
        });
        $loop->addTimer(1.0, static function () use (&$b, &$refused, $loop): void {
            $chunk = $b === null ? null : @\fread($b, 16);
            $refused = ($chunk === false || ($chunk === '' && \feof($b)));
            $loop->stop();
        });
        $safety = $loop->addTimer(4.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);

        $daemon->shutdown();
        $loop->run();
        if (\is_resource($a)) {
            \fclose($a);
        }
        if (\is_resource($b)) {
            \fclose($b);
        }

        $this->assertTrue($refused === true, 'the over-capacity newcomer must be closed unseated');
        $this->assertSame(
            1,
            $stats->connections(),
            'only the seated connection counts as handled; the refused newcomer must not bump the counter',
        );
    }
}
