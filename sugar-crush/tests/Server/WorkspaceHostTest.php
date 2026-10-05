<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Workspace\Gateway;
use SugarCraft\Crush\Server\Workspace\WorkspaceHostProcess;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * Roadmap O-7: multi-root `serve` — one workspace-host CHILD PROCESS per
 * project root behind the {@see Gateway}, because `Cli\Bootstrap`'s statics
 * make one process serve one root.
 *
 * These run real children (`php -r` → {@see WorkspaceHostProcess::main()}),
 * each with its own sandboxed HOME and the offline echo provider, and talk to
 * them through the gateway exactly as a browser would: the gateway's own
 * `workspace.*` methods, requests routed by `root` and by the sessions a host
 * answered for, a real turn forked inside a workspace host with its events
 * relayed back (tagged with their `root`), the per-client replay shield, a
 * killed host announced as `server.overflow` and restarted on the next
 * request, and closing one.
 */
final class WorkspaceHostTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private string $other;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('workspace hosts run forked turns: needs ext-pcntl and ext-posix');
        }
        $this->fixture = ProtocolFixture::new();
        $this->other = $this->fixture->dir . '/other';
        $home = $this->fixture->dir . '/home';
        \mkdir($this->other, 0o700, true);
        \mkdir($home, 0o700, true);
        $this->other = (string) \realpath($this->other);

        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
            ['HOME' => $home, 'SUGARCRUSH_PROVIDER' => 'echo'],
        );
        $this->gateway->start();
    }

    protected function tearDown(): void
    {
        $this->gateway->stop('test over');
        $this->fixture->tearDown();
    }

    public function testTheGatewaysOwnMethodsNeedHelloAndListThePrimaryRoot(): void
    {
        $client = WireClient::open($this->gateway);
        $early = $client->request('workspace.list');
        self::assertSame(ErrorCode::NotInitialized->value, $early['error']['code']);

        $client->hello();
        $items = $client->call('workspace.list')['items'];

        self::assertCount(1, $items);
        self::assertSame((string) \realpath($this->fixture->root), $items[0]['root']);
        self::assertTrue($items[0]['primary']);
        self::assertSame('root_not_found', $client->request('workspace.open', ['root' => $this->fixture->dir . '/nope'])['error']['data']['kind']);
    }

    public function testARequestWithoutAnotherRootIsTheServersOwn(): void
    {
        $client = $this->client();

        $created = $client->call('session.create', ['root' => $this->fixture->root]);

        self::assertTrue($this->fixture->hub->isOpen($created['id']), 'the primary root\'s dispatcher answered');
        self::assertNull($this->gateway->rootOf($created['id']));
        self::assertSame([], $this->gateway->hostRoots(), 'no workspace host was started');
    }

    public function testAnotherRootRunsInItsOwnProcessAndATurnStreamsBackThroughTheGateway(): void
    {
        $client = $this->client();
        $opened = $this->call($client, 'workspace.open', ['root' => $this->other]);
        self::assertFalse($opened['primary']);
        self::assertIsInt($opened['pid']);
        self::assertNotSame(\getmypid(), $opened['pid'], 'the root runs in a child process');

        $created = $this->call($client, 'session.create', ['root' => $this->other]);
        $sessionId = (string) $created['id'];
        self::assertSame($this->other, $created['root']);
        self::assertSame($this->other, $this->gateway->rootOf($sessionId));
        self::assertFalse($this->fixture->hub->isOpen($sessionId), 'the primary root never saw it');

        $listed = $this->call($client, 'session.list', ['root' => $this->other]);
        self::assertContains($sessionId, \array_column($listed['items'], 'id'));

        $subscribed = $this->call($client, 'session.subscribe', ['sessionId' => $sessionId]);
        self::assertTrue($subscribed['reset']);
        $sent = $this->call($client, 'session.send', ['sessionId' => $sessionId, 'text' => 'hello from the gateway']);
        self::assertSame('started', $sent['admitted']);

        self::assertTrue($this->runUntil(static fn (): bool => $client->events('turn.completed') !== [], 30.0), 'the turn in the workspace host never completed');
        $completed = $client->events('turn.completed')[0];
        self::assertSame($sessionId, $completed['sessionId']);
        self::assertSame($this->other, $completed['root'], 'a relayed event names its root');
        self::assertSame('end_turn', $completed['data']['stopReason']);
        self::assertNotSame([], $client->events('assistant.completed'));

        $listing = \array_column($this->call($client, 'workspace.list')['items'], 'root');
        self::assertSame([(string) \realpath($this->fixture->root), $this->other], $listing);
    }

    public function testASecondSubscriberDoesNotReplayTheFirstOnesEventsToIt(): void
    {
        $a = $this->client('a');
        $sessionId = (string) $this->call($a, 'session.create', ['root' => $this->other])['id'];
        $this->call($a, 'session.subscribe', ['sessionId' => $sessionId]);
        $this->call($a, 'session.send', ['sessionId' => $sessionId, 'text' => 'one']);
        self::assertTrue($this->runUntil(static fn (): bool => $a->events('turn.completed') !== [], 30.0));

        $b = $this->client('b');
        $this->call($b, 'session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 0]);
        self::assertTrue($this->runUntil(static fn (): bool => $b->events('turn.completed') !== [], 10.0), 'the replay from seq 0 reached the new subscriber');
        $this->fixture->run(0.2);

        $seqs = \array_values(\array_filter(\array_column($a->events(), 'seq'), 'is_int'));
        self::assertSame($seqs, \array_values(\array_unique($seqs)), 'the first subscriber saw no durable event twice');
    }

    public function testAKilledHostIsAnnouncedAndTheNextRequestStartsAFreshOne(): void
    {
        $client = $this->client();
        $sessionId = (string) $this->call($client, 'session.create', ['root' => $this->other])['id'];
        $this->call($client, 'session.subscribe', ['sessionId' => $sessionId]);
        $pid = $this->gateway->host($this->other)?->pid();
        self::assertIsInt($pid);

        \posix_kill($pid, 9);
        self::assertTrue($this->runUntil(fn (): bool => $this->gateway->host($this->other) === null, 10.0), 'the gateway never noticed the host died');
        $overflow = $client->events('server.overflow');
        self::assertCount(1, $overflow);
        self::assertSame(['sessionId' => $sessionId, 'dropped' => 0, 'action' => 'resubscribe'], $overflow[0]['data']);

        $again = $this->call($client, 'session.subscribe', ['sessionId' => $sessionId]);
        self::assertTrue($again['reset'], 'the session came back from its store in a fresh host');
        self::assertNotSame($pid, $this->gateway->host($this->other)?->pid());
    }

    public function testClosingAWorkspaceStopsItsHost(): void
    {
        $client = $this->client();
        $this->call($client, 'workspace.open', ['root' => $this->other]);
        $pid = $this->gateway->host($this->other)?->pid();
        self::assertIsInt($pid);

        self::assertTrue($this->call($client, 'workspace.close', ['root' => $this->other])['closed']);

        self::assertSame([], $this->gateway->hostRoots());
        self::assertFalse(\posix_kill($pid, 0), 'the child was reaped');
        self::assertSame('primary_root', $client->request('workspace.close', ['root' => $this->fixture->root])['error']['data']['kind']);
    }

    public function testTheSpawnLineCarriesTheServersSessionSettings(): void
    {
        $config = ServerConfig::new('/state')->withMaxOpenSessions(3)->withMaxConcurrentTurns(2)->withAskTimeoutSeconds(9.5)->withAllowBypass(true);
        $settings = WorkspaceHostProcess::settingsFor($config, '/r', '/s.sock', 'tok', 'v1');

        $back = WorkspaceHostProcess::configFrom($settings);

        self::assertSame('/r', $back->root);
        self::assertSame(3, $back->maxOpenSessions);
        self::assertSame(2, $back->maxConcurrentTurns);
        self::assertSame(9.5, $back->askTimeoutSeconds);
        self::assertTrue($back->allowBypass);
        self::assertSame($config->permissionMode, $back->permissionMode);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function client(string $id = 'c1'): WireClient
    {
        $client = WireClient::open($this->gateway, $id);
        $client->hello();

        return $client;
    }

    /**
     * A call that may be answered later (it waits on a child): run the loop
     * until its response arrives.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(WireClient $client, string $method, array $params = []): array
    {
        $client->raw((string) \json_encode(['jsonrpc' => '2.0', 'id' => $id = 'w' . \bin2hex(\random_bytes(4)), 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]));
        self::assertTrue($this->runUntil(static fn (): bool => $client->response($id) !== null, 40.0), $method . ' was never answered');
        $response = (array) $client->response($id);
        self::assertArrayHasKey('result', $response, $method . ' failed: ' . \json_encode($response));

        return (array) $response['result'];
    }

    private function runUntil(\Closure $condition, float $timeout = 10.0): bool
    {
        $deadline = \microtime(true) + $timeout;
        while (!$condition()) {
            if (\microtime(true) >= $deadline) {
                return false;
            }
            $this->fixture->run(0.02);
        }

        return true;
    }
}
