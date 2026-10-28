<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-SEC-4: trust prompts behind the multi-root gateway. The prompt is minted
 * inside the workspace-host CHILD that measured the root (TrustAsks statics
 * are per-process), so `context.trust` must carry the `sessionId` that routes
 * the answer to that same child — and the child, not the primary, must be the
 * one whose record settles.
 *
 * Before this step the params had no session at all: the gateway routed the
 * answer to the primary process, whose statics never held the child's ask, so
 * every non-primary root answered `ask_not_found` and the prompt could never
 * be closed. These tests run a real child (ext-pcntl/posix, like
 * {@see WorkspaceHostTest}) and drive the wire exactly as a browser would.
 */
final class GatewayTrustRoutingTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private string $other;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('workspace hosts run forked turns: needs ext-pcntl and ext-posix');
        }
        TrustAsks::resetForTesting();
        $this->fixture = ProtocolFixture::new();
        $this->other = $this->fixture->dir . '/other';
        $home = $this->fixture->dir . '/home';
        \mkdir($this->other, 0o700, true);
        \mkdir($home, 0o700, true);
        $this->other = (string) \realpath($this->other);
        \file_put_contents($this->other . '/.mcp.json', "{\"mcpServers\":{\"local\":{\"command\":\"true\",\"args\":[]}}}\n");

        // The CHILD must have the MCP launch path enabled (shards set
        // CANDY_CODE_MCP_DISABLE=1; the overlay below re-arms it for hosts)
        // and a sandbox HOME whose user tier trusts nothing — the exact shape
        // the census calls `untrusted`.
        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
            ['HOME' => $home, 'CANDY_CODE_PROVIDER' => 'echo', 'CANDY_CODE_MCP_DISABLE' => '0'],
        );
        $this->gateway->start();
    }

    protected function tearDown(): void
    {
        $this->gateway->stop('test over');
        $this->fixture->tearDown();
        TrustAsks::resetForTesting();
    }

    public function testAPromptMintedByAWorkspaceHostRoutesItsAnswerBackToThatHost(): void
    {
        $client = $this->client();
        $sessionId = (string) $this->call($client, 'session.create', ['root' => $this->other])['id'];
        self::assertSame($this->other, $this->gateway->rootOf($sessionId));
        self::assertFalse($this->fixture->hub->isOpen($sessionId), 'the primary root never saw it');

        $this->call($client, 'session.subscribe', ['sessionId' => $sessionId]);

        self::assertTrue($this->runUntil(static fn (): bool => self::trustEvents($client) !== [], 40.0), 'the host never put its trust prompt');
        $data = self::trustEvents($client)[0]['data'];
        self::assertSame('trust', $data['kind']);
        self::assertSame($sessionId, $data['sessionId'], 'the prompt names the session that subscribed');
        self::assertSame($this->other, $data['root']);

        // The ask lives in the child's statics and nowhere else: the primary
        // process never minted (nor can it settle) this prompt.
        self::assertSame([], TrustAsks::records($this->other), 'the primary root never held this prompt');

        $askId = (string) $data['askId'];
        $settled = $this->call($client, 'context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant']);

        // The gateway stamps provenance on EVERY routed result — Gateway::forward
        // annotates `$result->root ??= $root` — so the answer that came back is
        // four keys. Keeping full-shape strictness (the same posture as the
        // prompt-event leg above asserting $data['root']) is what pins the
        // routing: the child that owns this root answered it, not the primary.
        self::assertSame(
            ['askId' => $askId, 'status' => 'granted', 'persisted' => false, 'root' => $this->other],
            $settled,
            'the answer reached the child that asked',
        );

        // The CHILD settled its own record: a second answer is refused as
        // already-resolved there, and a later measurement reads the settled
        // truth back — routing, not luck, is what made both possible. (The
        // refusal is a CHILD answer, so it must be waited for like every
        // other routed reply.)
        $again = $this->reply($client, 'context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant']);
        self::assertSame(-32009, $again['error']['code']);
        self::assertSame('already_resolved', $again['error']['data']['kind']);
        $trusts = $this->call($client, 'context.sources', ['sessionId' => $sessionId])['trusts'];
        self::assertSame('granted', $trusts[0]['status']);
        self::assertSame('mcp', $trusts[0]['target']);
    }

    public function testAnAnswerOmittingTheSessionIsRefusedInsteadOfMisrouted(): void
    {
        $client = $this->client();
        $sessionId = (string) $this->call($client, 'session.create', ['root' => $this->other])['id'];
        $this->call($client, 'session.subscribe', ['sessionId' => $sessionId]);
        self::assertTrue($this->runUntil(static fn (): bool => self::trustEvents($client) !== [], 40.0), 'the host never put its trust prompt');
        $askId = (string) self::trustEvents($client)[0]['data']['askId'];

        // Pre-fix shape: no sessionId exists on the params, so the schema
        // refuses HERE. Without the refusal the same call would be answered by
        // the primary dispatcher — an `ask_not_found` that silently orphaned
        // the child's prompt and left the panel looping.
        $reply = $client->request('context.trust', ['askId' => $askId, 'decision' => 'grant']);
        self::assertSame(-32602, $reply['error']['code']);
        self::assertSame('invalid_params', $reply['error']['data']['kind']);

        // The child's prompt is untouched and answerable once routed properly.
        $settled = $this->call($client, 'context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'reject']);
        self::assertSame('rejected', $settled['status']);
    }

    /** @return list<array<string, mixed>> */
    private static function trustEvents(WireClient $client): array
    {
        return \array_values(\array_filter(
            $client->events('permission.asked'),
            static fn (array $envelope): bool => ($envelope['data']['kind'] ?? null) === 'trust',
        ));
    }

    private function client(string $id = 'c1'): WireClient
    {
        $client = WireClient::open($this->gateway, $id);
        $client->hello();

        return $client;
    }

    /**
     * Send a call whose answer may be an error (a routed child settles its
     * own asks, and the gateway's replies are async by nature), run the loop
     * until that response arrives, and return the WHOLE reply envelope.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function reply(WireClient $client, string $method, array $params = []): array
    {
        $client->raw((string) \json_encode(['jsonrpc' => '2.0', 'id' => $id = 'w' . \bin2hex(\random_bytes(4)), 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]));
        self::assertTrue($this->runUntil(static fn (): bool => $client->response($id) !== null, 40.0), $method . ' was never answered');

        return (array) $client->response($id);
    }

    /**
     * A call that must succeed: run the loop until its answer arrives and
     * return its `result` arm.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(WireClient $client, string $method, array $params = []): array
    {
        $response = $this->reply($client, $method, $params);
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
