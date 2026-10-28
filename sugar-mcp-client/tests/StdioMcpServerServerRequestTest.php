<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * C-MCP-3: server-initiated traffic on the stdio transport.
 *
 * Before this seam the read loop skipped every method-bearing frame "however
 * chatty" — a server pinging mid-call, asking for roots, or eliciting the
 * human was waved past, and the call that triggered it could sit waiting for
 * a reply the protocol says must come, until the deadline burned. The
 * fixture server_request_server.php pushes that exact traffic during its
 * `talk` call and records, in the call's own result, what the client
 * answered. These tests read the witness back: the default ping answer is
 * the empty object, an unknown request earns -32601, integer wire ids echo
 * as integers, configured capabilities ride the initialize handshake, a
 * per-call handler replaces the defaults — and the call deadline is PAUSED
 * while a handler runs (the E646-spirit fix: a thinking human never costs
 * the request its budget), proven by a handler that sleeps longer than the
 * whole timeout while the call still succeeds.
 *
 * Harness law inherited from the round-trip suite: never fail() inside a
 * `catch (\RuntimeException)` — capture, exit the try, assert after.
 */
final class StdioMcpServerServerRequestTest extends TestCase
{
    /** Generous handshake/call budget; the fixture's own waits are bounded shorter. */
    private const BUDGET_SECONDS = 8.0;

    /** @var list<StdioMcpServer> */
    private array $live = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param (\Closure(string, ?array<mixed>, bool): ?array)|null $onServerMessage
     */
    private function talkServer(array $capabilities = [], ?\Closure $onServerMessage = null): StdioMcpServer
    {
        $server = new StdioMcpServer(
            'requests',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/server_request_server.php'],
            startTimeoutSeconds: self::BUDGET_SECONDS,
            capabilities: $capabilities,
            onServerMessage: $onServerMessage,
        );
        $this->live[] = $server;
        $server->start();

        return $server;
    }

    /**
     * Decode the fixture's witness out of a successful `talk` result.
     *
     * @param array<mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function observations(array $result): array
    {
        $text = (string) ($result['content'][0]['text'] ?? '');
        $decoded = json_decode($text, true);

        self::assertIsArray($decoded, 'fixture witness must arrive as JSON text');

        return $decoded;
    }

    public function testDefaultAnswersAreEmptyObjectPingAndMethodNotFound(): void
    {
        $result = $this->talkServer()->callTool('talk', [], null, self::BUDGET_SECONDS);

        $obs = self::observations($result);
        $ping = $obs['ping'];
        $audit = $obs['audit'];

        self::assertTrue($ping['answered'], 'the mid-call ping must be answered, not skipped');
        self::assertTrue($ping['resultWasEmptyObject'], 'the default ping answer is the empty object');
        self::assertTrue($audit['answered'], 'the unknown request must be answered too');
        self::assertSame(-32601, $audit['errorCode'], 'an unknown request earns method-not-found');
        self::assertFalse($audit['hadResult']);
    }

    public function testIntegerWireIdOfServerRequestIsEchoedAsInteger(): void
    {
        $obs = self::observations(
            $this->talkServer()->callTool('talk', [], null, self::BUDGET_SECONDS)
        );

        // The fixture only counts a match on id 101 when the echoed id came
        // back as the NUMBER it sent — json_encode of the parsed string id
        // would have been "101" and failed the match.
        self::assertTrue($obs['ping']['idWasInt']);
    }

    public function testConfiguredCapabilitiesRideTheInitializeHandshake(): void
    {
        $obs = self::observations(
            $this->talkServer(
                capabilities: ['roots' => ['listChanged' => false]],
            )->callTool('talk', [], null, self::BUDGET_SECONDS)
        );

        self::assertSame(['roots' => ['listChanged' => false]], $obs['initializeCapabilities']);
    }

    public function testDefaultInitializeCapabilitiesStayTheEmptyObject(): void
    {
        $obs = self::observations(
            $this->talkServer()->callTool('talk', [], null, self::BUDGET_SECONDS)
        );

        // `{}` on the wire — the strict-SDK law survives configuration: an
        // unconfigured client still declares the empty OBJECT, never `[]`.
        self::assertSame([], $obs['initializeCapabilities']);
    }

    public function testPerCallHandlerAnswersUnknownRequestAndSeesProgress(): void
    {
        /** @var list<array{method: string, isNotification: bool}> $seen */
        $seen = [];

        $handler = static function (string $method, ?array $params, bool $isNotification) use (&$seen): ?array {
            $seen[] = ['method' => $method, 'isNotification' => $isNotification];

            return $method === 'audit/nope' ? ['result' => ['custom' => true]] : null;
        };

        $result = $this->talkServer()->callTool('talk', [], null, self::BUDGET_SECONDS, $handler);

        $obs = self::observations($result);
        self::assertTrue($obs['audit']['hadResult'], 'the per-call handler replaced the -32601 default');

        $methods = array_column($seen, 'method');
        self::assertContains('ping', $methods);
        self::assertContains('audit/nope', $methods);
        self::assertContains('notifications/progress', $methods);

        foreach ($seen as $frame) {
            self::assertSame(
                str_starts_with($frame['method'], 'notifications/'),
                $frame['isNotification'],
                'the notification flag must tell answers from traffic'
            );
        }
    }

    public function testConstructedHandlerIsTheDefaultAnswererWithoutPerCallOverride(): void
    {
        $pinged = 0;

        $server = $this->talkServer(
            onServerMessage: static function (string $method, ?array $params, bool $isNotification) use (&$pinged): ?array {
                if ($method === 'ping' && !$isNotification) {
                    $pinged++;
                }

                return null;
            }
        );

        $obs = self::observations($server->callTool('talk', [], null, self::BUDGET_SECONDS));

        self::assertSame(1, $pinged, 'the constructed handler hears the traffic first');
        self::assertTrue($obs['ping']['answered'], 'and null from it still lands the empty-object default');
    }

    public function testCallDeadlineIsPausedWhileAHandlerRuns(): void
    {
        // The handler spends LONGER than the entire call timeout thinking —
        // exactly what a human facing an elicitation does. The seam bills
        // the call only for wire time: the ping answer still goes out, the
        // tools/call reply (which the fixture sends immediately after the
        // ping answer) still lands inside the extended deadline, and the
        // call succeeds. Pre-seam this shape times out: nothing answers the
        // ping, the fixture waits, and the abandoned-call rails fire.
        $slowThinker = static function (string $method, ?array $params, bool $isNotification): ?array {
            if ($method === 'ping' && !$isNotification) {
                usleep(1_200_000);
            }

            return null;
        };

        $result = $this->talkServer()->callTool('talk', [], null, 0.8, $slowThinker);

        self::assertArrayNotHasKey('error', $result, 'a thinking handler must not cost the call its budget');
        self::assertTrue(self::observations($result)['ping']['answered']);
    }
}
