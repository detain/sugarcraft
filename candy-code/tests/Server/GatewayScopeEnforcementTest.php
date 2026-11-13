<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * S-O01: the gateway answers or relays `workspace.*` and every request that
 * routes to another root WITHOUT the Dispatcher, and a workspace host trusts
 * its parent as the owner - so a pinned read-only viewer token could
 * `workspace.open` any directory, learn its sessions and `session.send`.
 * These pin the whole chain: each owner-scope entry point is refused at the
 * gateway's own door, and no host is ever started for a viewer.
 */
final class GatewayScopeEnforcementTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private string $other;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->other = $this->fixture->dir . '/other';
        \mkdir($this->other, 0o700, true);
        $this->other = (string) \realpath($this->other);
        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
        );
    }

    protected function tearDown(): void
    {
        $this->gateway->stop('test over');
        $this->fixture->tearDown();
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function ownerEntryPoints(): array
    {
        return [
            'workspace.open' => ['workspace.open', ['root' => '/tmp']],
            'workspace.list' => ['workspace.list', []],
            'workspace.close' => ['workspace.close', ['root' => '/tmp', 'force' => true]],
        ];
    }

    /**
     * @dataProvider ownerEntryPoints
     *
     * @param array<string, mixed> $params
     */
    public function testAViewerNeverReachesTheGatewaysOwnMethods(string $method, array $params): void
    {
        foreach ([['read'], ['read', 'write', 'approve', 'admin']] as $i => $scopes) {
            $viewer = $this->viewer('v' . $i, $scopes);
            $reply = $viewer->request($method, $params);
            self::assertSame(-32003, $reply['error']['code'] ?? null, $method . ' must be refused: ' . \json_encode($reply));
        }
        self::assertSame([], $this->gateway->hostRoots(), 'a viewer never starts a host');
    }

    public function testAViewerWorkspaceListLeaksNeitherRootsNorPids(): void
    {
        $reply = $this->viewer('v', ['read'])->request('workspace.list');
        self::assertArrayNotHasKey('result', $reply);
        self::assertStringNotContainsString((string) \getmypid(), (string) \json_encode($reply));
    }

    public function testTheOwnerStillListsWorkspaces(): void
    {
        $owner = WireClient::open($this->gateway, 'owner');
        $owner->hello();
        $items = $owner->call('workspace.list')['items'];
        self::assertTrue($items[0]['primary']);
    }

    public function testAViewerCannotStartAHostByRoutingARequestAtADirectory(): void
    {
        $viewer = $this->viewer('v', ['read']);
        foreach (['session.list', 'session.get'] as $method) {
            $reply = $viewer->request($method, ['root' => $this->other, 'sessionId' => 's1']);
            self::assertSame(-32003, $reply['error']['code'] ?? null, $method);
        }
        self::assertSame([], $this->gateway->hostRoots());
        self::assertNull($this->gateway->rootOf('s1'));
    }

    public function testAViewerWithoutWriteIsRefusedSessionSendAndCreateOnARoutedRoot(): void
    {
        $viewer = $this->viewer('v', ['read']);
        foreach (['session.send' => ['sessionId' => 's1', 'text' => 'rm -rf'], 'session.create' => []] as $method => $params) {
            $reply = $viewer->request($method, ['root' => $this->other] + $params);
            self::assertSame(-32003, $reply['error']['code'] ?? null, $method);
            self::assertStringContainsString('write scope', $reply['error']['message']);
        }
        self::assertSame([], $this->gateway->hostRoots());
    }

    public function testAWriteScopedViewerStillCannotStartAHost(): void
    {
        $viewer = $this->viewer('v', ['read', 'write']);
        $reply = $viewer->request('session.create', ['root' => $this->other]);
        self::assertSame(-32003, $reply['error']['code'] ?? null);
        self::assertSame('owner_only', $reply['error']['data']['kind'] ?? null);
        self::assertSame([], $this->gateway->hostRoots());
    }

    public function testAnUnknownMethodFromAViewerIsNotRelayedToAHost(): void
    {
        $reply = $this->viewer('v', ['read'])->request('shell.exec', ['root' => $this->other]);
        self::assertSame(-32601, $reply['error']['code'] ?? null);
        self::assertSame([], $this->gateway->hostRoots());
    }

    public function testAPinnedViewerMustNameAPinnedSessionOnARoutedCall(): void
    {
        $viewer = $this->viewer('v', ['read'], ['pinned']);
        $other = $viewer->request('session.get', ['root' => $this->other, 'sessionId' => 'not-pinned']);
        self::assertSame('viewer_session_denied', $other['error']['data']['kind'] ?? null);
        $none = $viewer->request('session.list', ['root' => $this->other]);
        self::assertSame('viewer_session_denied', $none['error']['data']['kind'] ?? null, 'a root-wide call has no pinned session to scope it');
        self::assertSame([], $this->gateway->hostRoots());
    }

    public function testAPinnedViewerIsNotFannedAWorkspacesServerScopeEvents(): void
    {
        $pinned = $this->viewer('pin', ['read'], ['pinned']);
        $owner = WireClient::open($this->gateway, 'own');
        $owner->hello();
        $pinned->clear();
        $owner->clear();

        $clients = new \ReflectionProperty(Gateway::class, 'rootClients');
        $clients->setValue($this->gateway, [$this->other => ['pin' => $pinned->connection, 'own' => $owner->connection]]);
        $payload = (string) \json_encode(['jsonrpc' => '2.0', 'method' => 'event', 'params' => ['type' => 'server.notice', 'durable' => true, 'seq' => 1, 'data' => []]]);
        $relay = new \ReflectionMethod(Gateway::class, 'relay');
        $relay->invoke($this->gateway, $this->other, ['type' => 'server.notice', 'durable' => true, 'seq' => 1], $payload);
        $this->fixture->run(0.05);

        self::assertCount(1, $owner->events('server.notice'), 'the owner still hears it');
        self::assertSame([], $pinned->events(), 'the pinned viewer hears nothing root-wide');
    }

    /**
     * @param list<string> $scopes
     * @param list<string>|null $pins
     */
    private function viewer(string $id, array $scopes, ?array $pins = null): WireClient
    {
        $client = WireClient::open($this->gateway, $id, 'viewer', $scopes, 'tok-' . $id, $pins);
        $client->hello();

        return $client;
    }
}
