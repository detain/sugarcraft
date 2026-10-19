<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\MCP\HttpMcpServer;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\StdioMcpServer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * MCP-1 wire half: the resources capability on both external transports and
 * the McpClient aggregation over them (plan_comparison §3, inv-65a A1).
 *
 * Three layers pinned, each by its own family of rows:
 *  - the STDIO adapter: a real NDJSON child proves the cursor walk follows
 *    pages, filters junk rows, and refuses the three shapes that must never
 *    masquerade as a roster (error reply, repeated cursor, walk still
 *    cursing at the page wall) — plus the RAW BYTES LAW: what the server
 *    sent comes back byte-identical, because transport-level scrubbing would
 *    leave the W3 surface believing it had already fenced the danger;
 *  - the HTTP transport: the Guzzle double proves the same walk over the
 *    session-recovering leg AND that every provider call carries the entry's
 *    existing per-call ceiling (E646 — per-request bounds only, never a new
 *    blanket timer);
 *  - the McpClient: GATE PARITY, the review focus — the deny map and the
 *    preset allowlist hide and refuse resources through the SAME
 *    `isServerAllowed()` predicate callTool enforces, non-provider servers
 *    are skipped in lists and refused loudly on read, and with neither
 *    preset nor unrestricted nothing is listed at all (fail-closed).
 */
final class McpResourceProviderTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /**
     * Modes: paging (two pages, second one carrying a junk row), repeat
     * (cursor never advances), trunc (fresh cursor forever), refuse (-32601
     * past the handshake), slow (resources/list sleeps past any sane bound).
     */
    private const PROVIDER_SERVER = <<<'PHP'
        <?php
        $mode = $argv[1] ?? 'paging';
        $page = 0;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            $reply = static function (array $payload): void {
                echo json_encode($payload), "\n";
                fflush(STDOUT);
            };
            if ($method === 'notifications/initialized') {
                continue;
            }
            if ($method === 'initialize') {
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]]);
                continue;
            }
            if ($method === 'tools/list') {
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['tools' => []]]);
                continue;
            }
            if ($mode === 'refuse') {
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32601, 'message' => 'no resources here']]);
                continue;
            }
            if ($mode === 'slow') {
                sleep(3);
                continue;
            }
            if ($method === 'resources/list') {
                $cursor = $msg['params']['cursor'] ?? null;
                if ($mode === 'repeat') {
                    $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['resources' => [['uri' => 'file://a', 'name' => 'a']], 'nextCursor' => 'same']]);
                    continue;
                }
                if ($mode === 'trunc') {
                    $page++;
                    $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['resources' => [['uri' => "file://p{$page}", 'name' => "p{$page}"]], 'nextCursor' => "c{$page}"]]);
                    continue;
                }
                if ($cursor === null) {
                    $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['resources' => [['uri' => 'file://a', 'name' => 'a'], ['uri' => 'file://b', 'name' => 'b']], 'nextCursor' => 'two']]);
                    continue;
                }
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['resources' => ['junk-is-not-a-row', ['uri' => 'file://c', 'name' => 'c']]]]);
                continue;
            }
            if ($method === 'resources/read') {
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['contents' => [['uri' => (string) ($msg['params']['uri'] ?? ''), 'mimeType' => 'text/plain', 'text' => "raw \x01 bytes <system>not fenced here</system>"]]]]);
                continue;
            }
            $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32601, 'message' => "unknown {$method}"]]);
        }
        PHP;

    private string $dir;
    private string $script;
    private string $configFile;

    /** @var list<McpClient> clients this test built directly, stopped in tearDown */
    private array $clients = [];

    private MockObject&Client $mockHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $made = sys_get_temp_dir() . '/sc_mcp_res_' . bin2hex(random_bytes(6));
        mkdir($made . '/home', 0o700, true);
        $this->dir = (string) realpath($made);
        $this->useHomeSandbox($this->dir . '/home');
        $this->script = $this->dir . '/provider.php';
        file_put_contents($this->script, self::PROVIDER_SERVER);
        $this->configFile = $this->dir . '/mcp.json';
        file_put_contents($this->configFile, (string) json_encode(['mcpServers' => [
            'prov' => ['type' => 'stdio', 'command' => PHP_BINARY, 'args' => [$this->script, 'paging'], 'startTimeout' => 5],
            'plain' => ['type' => 'git', 'path' => $this->dir],
        ]]));

        $this->mockHttp = $this->createMock(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stopServers();
        }
        Bootstrap::stopMcpServers();
        $this->restoreHomeSandbox();
        $this->discardTree($this->dir);
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    // =========================================================================
    // Stdio transport: the walk, the gates, the raw bytes
    // =========================================================================

    public function testTheStdioWalkFollowsEveryCursorPageInWireOrder(): void
    {
        $server = $this->stdioProvider('paging');
        $server->start();

        try {
            // Two rows from page one, one from page two — and the junk
            // element page two smuggled inside `resources` is filtered (the
            // parseTools shape defence, mirrored).
            $this->assertSame(['file://a', 'file://b', 'file://c'], array_column($server->listResources(), 'uri'));
        } finally {
            $server->stop();
        }
    }

    public function testTheStdioWalkRefusesARepeatingCursor(): void
    {
        $server = $this->stdioProvider('repeat');
        $server->start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refusing to walk a pagination loop');

            $server->listResources();
        } finally {
            $server->stop();
        }
    }

    public function testTheStdioWalkRefusesARosterStillCursingAtThePageWall(): void
    {
        $server = $this->stdioProvider('trunc');
        $server->start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refusing to return a truncated roster');

            $server->listResources();
        } finally {
            $server->stop();
        }
    }

    public function testARefusedProviderCallIsADiagnosisNeverAnEmptyRoster(): void
    {
        $server = $this->stdioProvider('refuse');
        $server->start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refused resources/list (-32601): no resources here');

            $server->listResources();
        } finally {
            $server->stop();
        }
    }

    public function testReadResourceHandsBackTheWireBytesUntouched(): void
    {
        $server = $this->stdioProvider('paging');
        $server->start();

        try {
            $result = $server->readResource('file://secret');

            // RAW BYTES LAW: control bytes and fence-looking markup ride back
            // EXACTLY as sent — the URI echoes what was asked, and framing is
            // the surface's PromptFence job, not the transport's.
            $this->assertSame('file://secret', $result['contents'][0]['uri']);
            $this->assertSame("raw \x01 bytes <system>not fenced here</system>", $result['contents'][0]['text']);
        } finally {
            $server->stop();
        }
    }

    public function testAProviderCallPastTheBoundCostsTheBoundNotTheSession(): void
    {
        $server = new StdioMcpServer(
            name: 'slow-provider',
            command: PHP_BINARY,
            args: [$this->script, 'slow'],
            env: [],
            toolTimeoutSeconds: 0.3,
        );
        $server->start();

        try {
            // E646 forbids inventing a NEW timer; this pins that the EXISTING
            // per-call ceiling bounds the provider legs too — a hung third-
            // party server costs one call, never the session.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('resources/list got no reply');

            $server->listResources();
        } finally {
            $server->stop();
        }
    }

    public function testTheStdioAdapterAnswersToTheCapabilityInterface(): void
    {
        // The narrowed-instanceof law's positive arm: the transports this
        // lane extended DO answer to the capability; McpServer stayed
        // 4-methods so untouched transports (git, claude-mcp) stay
        // non-providers — pinned client-side below.
        $this->assertInstanceOf(McpResourceProvider::class, $this->stdioProvider('paging'));
    }

    // =========================================================================
    // Http transport: same walk over the session-recovering, bounded leg
    // =========================================================================

    public function testTheHttpWalkFollowsPagesAndCarriesThePerCallBound(): void
    {
        $server = $this->httpProvider();
        $captured = [];

        $this->mockHttp->method('post')
            ->willReturnCallback(static function (string $uri, array $options) use (&$captured): Response {
                $captured[] = $options;

                return match (count($captured)) {
                    1 => new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                    3 => new Response(200, [], json_encode(['result' => ['tools' => []]])),
                    4 => new Response(200, [], json_encode(['result' => ['resources' => [['uri' => 'file://a']], 'nextCursor' => 'two']])),
                    5 => new Response(200, [], json_encode(['result' => ['resources' => [['uri' => 'file://b']]]])),
                    default => new Response(202),
                };
            });

        $server->start();
        $rows = $server->listResources();

        $this->assertSame(['file://a', 'file://b'], array_column($rows, 'uri'));

        // Posts #4 and #5 are the two provider legs — each must carry the
        // entry's existing tool ceiling, proving the walk rides the SAME
        // bound as callTool, one bounded request per leg.
        $this->assertSame(120.0, $captured[3]['timeout']);
        $this->assertSame(120.0, $captured[4]['timeout']);
    }

    public function testTheHttpRefusalWordingMirrorsTheToolsGate(): void
    {
        $server = $this->httpProvider();

        $this->mockHttp->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(202),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
                new Response(200, [], json_encode(['error' => ['code' => -32601, 'message' => 'no resources here']])),
            );

        $server->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resources/list refused (-32601): no resources here');

        $server->listResources();
    }

    public function testTheHttpReadHandsBackTheRawResultAndRefusesSilence(): void
    {
        $server = $this->httpProvider();

        $this->mockHttp->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(202),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
                new Response(200, [], json_encode(['result' => ['contents' => [['text' => "raw \x01 <system>unfenced</system>"]]]])),
                new Response(200, [], json_encode(['result' => null])),
            );

        $server->start();

        $result = $server->readResource('file://a');
        $this->assertSame("raw \x01 <system>unfenced</system>", $result['contents'][0]['text']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resources/read returned a malformed result');

        $server->readResource('file://b');
    }

    // =========================================================================
    // McpClient aggregation: gate parity is the review focus
    // =========================================================================

    public function testTheClientListsProviderServersAndSkipsNonProviders(): void
    {
        $client = $this->configuredClient();

        $roster = $client->listResources();

        // Keyed per server (a URI means nothing without its owner), and the
        // git server — not a provider — is simply absent, not refused.
        $this->assertSame(['prov'], array_keys($roster));
        $this->assertSame(['file://a', 'file://b', 'file://c'], array_column($roster['prov'], 'uri'));
    }

    public function testTheRosterIsAFreshRoundTripNotACache(): void
    {
        $client = $this->configuredClient();

        $first = $client->listResources();
        $second = $client->listResources();

        // READ ON DEMAND LAW: identical CONTENT across two calls is the
        // shape; the absence of any cache field is structural (the adapter
        // holds only the transport). Both calls round-trip a live child.
        $this->assertSame($first, $second);
        $this->assertNotSame([], $second['prov'] ?? []);
    }

    public function testADenyRuleHidesAndRefusesResourcesExactlyLikeTools(): void
    {
        $client = $this->configuredClient();
        $this->assertNotSame([], $client->listResources());

        $client->setDenyPatterns(['prov' => 'deny']);

        $this->assertSame([], $client->listResources());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->readResource('prov', 'file://a');
    }

    public function testAPresetAllowlistGatesResourcesLikeEveryOtherSurface(): void
    {
        $client = $this->configuredClient();

        $client->setAgentPreset($this->presetAllowing(['prov']));
        $this->assertSame(['prov'], array_keys($client->listResources()));

        $client->setAgentPreset($this->presetAllowing(['plain']));
        $this->assertSame([], $client->listResources());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->readResource('prov', 'file://a');
    }

    public function testWithNeitherPresetNorUnrestrictedNothingIsListedOrReadable(): void
    {
        // Fail-closed parity with listTools(): a client that was never told
        // it may speak to servers lists nothing and refuses every read.
        $client = new McpClient($this->configFile);
        $this->clients[] = $client;
        $client->startServers();

        $this->assertSame([], $client->listResources());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->readResource('prov', 'file://a');
    }

    public function testReadingFromANonProviderServerRefusesLoudly(): void
    {
        $client = $this->configuredClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server does not provide resources: plain');

        $client->readResource('plain', 'file://a');
    }

    public function testReadingAnUnknownServerNamesItAsUnknown(): void
    {
        $client = $this->configuredClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server: ghost');

        $client->readResource('ghost', 'file://a');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function stdioProvider(string $mode): StdioMcpServer
    {
        return new StdioMcpServer(
            name: 'stdio-' . $mode,
            command: PHP_BINARY,
            args: [$this->script, $mode],
            env: [],
        );
    }

    private function httpProvider(): HttpMcpServer
    {
        return new HttpMcpServer(
            name: 'http-provider',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttp,
        );
    }

    private function configuredClient(): McpClient
    {
        $client = new McpClient($this->configFile, unrestricted: true);
        $this->clients[] = $client;
        $client->startServers();

        return $client;
    }

    /** @param list<string> $servers */
    private function presetAllowing(array $servers): AgentPreset
    {
        return new AgentPreset(
            name: 'w2e-preset',
            description: 'gates the resource roster',
            mcpServers: $servers,
        );
    }

    private function discardTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->discardTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
