<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Message;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * ISS-058, client side of the paging: an attachment that opens a session
 * with a page size subscribes to only the newest rows and stitches the older
 * pages itself (one frame never carries a long transcript, and the TUI still
 * sees the whole history). Once the walk reaches the first row the stitching
 * drops the paging meta, so a fully-stitched snapshot has the pre-paging
 * shape byte-for-byte.
 */
final class AttachPagingTest extends TestCase
{
    private const PAGE_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private ProtocolFixture $fixture;

    private Server $server;

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->server = Server::new(
            ServerConfig::new($this->fixture->dir)->withPort(0),
            AuthContext::new(TokenStore::new($this->fixture->dir)->withOverride(self::PAGE_TOKEN)),
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->close();
        }
        $this->server->stop();
        $this->fixture->tearDown();
    }

    public function testAnAttachmentStitchesOlderPagesIntoItsHistory(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        for ($i = 0; $i < 3; $i++) {
            $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'prompt ' . $i]);
            $this->fixture->backend->settle(Message::assistant('reply ' . $i));
            $this->fixture->run();
        }
        $whole = $client->call('session.get', ['sessionId' => $sessionId]);
        $client->call('session.unsubscribe', ['sessionId' => $sessionId]);

        // Two rows a page against six: the reset snapshot alone would leave
        // the newest page, so the stitch must fetch two more older pages.
        $host = $this->attach($sessionId, 2);

        $contents = \array_map(static fn (Message $m): string => (string) $m->content, $host->history());
        self::assertSame(\array_map(static fn (array $r): string => (string) ($r['content'] ?? ''), (array) $whole['messages']), $contents, 'the paged attachment still ends up with the whole transcript, oldest-first');
        self::assertSame((int) $whole['lastSeq'], $host->lastSeq(), 'the stitch anchors the cursor on the snapshot sequence');
        foreach (['totalMessages', 'oldestIndex', 'hasMoreOlder'] as $meta) {
            self::assertArrayNotHasKey($meta, $host->snapshot(), 'a fully-stitched snapshot carries no paging meta');
        }
    }

    public function testAnAttachmentWithoutPagingTakesTheWholeSnapshotDirectly(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'only prompt']);
        $this->fixture->backend->settle(Message::assistant('only reply'));
        $this->fixture->run();
        $client->call('session.unsubscribe', ['sessionId' => $sessionId]);

        $host = $this->attach($sessionId);

        self::assertCount(2, $host->history());
        self::assertArrayNotHasKey('hasMoreOlder', $host->snapshot());
    }

    private function attach(string $sessionId, int $pageRows = RemoteSessionHost::SNAPSHOT_PAGE_ROWS): RemoteSessionHost
    {
        $url = 'ws://127.0.0.1:' . $this->server->port() . '/ws';
        $host = $this->fixture->await(Attach::connect($url, self::PAGE_TOKEN)->then(
            static fn (RemoteSessionHost $host): PromiseInterface => $host->hello()->then(static fn (): PromiseInterface => $host->open($sessionId, $pageRows)),
        ));
        self::assertInstanceOf(RemoteSessionHost::class, $host, $host instanceof \Throwable ? $host->getMessage() : 'no answer');
        $this->hosts[] = $host;
        $this->fixture->run(0.05);

        return $host;
    }
}
