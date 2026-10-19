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
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\StdioMcpServer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * MCP-2 wire half: the prompts capability on both external transports and
 * the McpClient aggregation over them (plan_comparison §3, inv-65a A2).
 *
 * The sibling of {@see McpResourceProviderTest} for the other capability:
 * same walk, same gates, same GATE PARITY focus at the client — pinned
 * independently rather than parameterised away, because a mutation that
 * breaks only the prompts arm must still land on a red row by name.
 *
 * The call shape is pinned end to end, including the `arguments`-encodes-as-
 * OBJECT law: the fixture child reports the JSON type it actually received,
 * so `"arguments": []` on the wire fails the suite exactly where it hurts —
 * the SDK servers that reject it.
 */
final class McpPromptProviderTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /**
     * Modes: paging (two prompt pages, the second carrying a junk row),
     * refuse (-32601 past the handshake), wire (prompts/get reports the JSON
     * shape of `arguments` as RECEIVED plus the echoed name).
     */
    private const PROMPT_SERVER = <<<'PHP'
        <?php
        $mode = $argv[1] ?? 'paging';
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
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32601, 'message' => 'no prompts here']]);
                continue;
            }
            if ($method === 'prompts/list') {
                $cursor = $msg['params']['cursor'] ?? null;
                if ($cursor === null) {
                    $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['prompts' => [['name' => 'p-a', 'description' => 'first'], ['name' => 'p-b', 'description' => 'second']], 'nextCursor' => 'two']]);
                    continue;
                }
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['prompts' => ['junk-is-not-a-row', ['name' => 'p-c', 'description' => 'third']]]]);
                continue;
            }
            if ($method === 'prompts/get') {
                $raw = json_decode($line);
                $arguments = $raw->params->arguments ?? null;
                $shape = $arguments === null ? 'missing' : ($arguments instanceof \stdClass ? 'object' : (is_array($arguments) ? 'array' : get_debug_type($arguments)));
                $reply(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => [
                    'description' => 'wire report',
                    'argsShape' => $shape,
                    'echo' => json_decode(json_encode($arguments), true),
                    'asked' => (string) ($msg['params']['name'] ?? ''),
                ]]);
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

    private MockObject&Client $mockPromptHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $made = sys_get_temp_dir() . '/sc_mcp_prompt_' . bin2hex(random_bytes(6));
        mkdir($made . '/home', 0o700, true);
        $this->dir = (string) realpath($made);
        $this->useHomeSandbox($this->dir . '/home');
        $this->script = $this->dir . '/prompts.php';
        file_put_contents($this->script, self::PROMPT_SERVER);
        $this->configFile = $this->dir . '/mcp.json';
        file_put_contents($this->configFile, (string) json_encode(['mcpServers' => [
            'prov' => ['type' => 'stdio', 'command' => PHP_BINARY, 'args' => [$this->script, 'paging'], 'startTimeout' => 5],
            'plain' => ['type' => 'git', 'path' => $this->dir],
        ]]));

        $this->mockPromptHttp = $this->createMock(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stopServers();
        }
        Bootstrap::stopMcpServers();
        $this->restoreHomeSandbox();
        $this->discardPromptTree($this->dir);
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    // =========================================================================
    // Stdio transport
    // =========================================================================

    public function testTheStdioPromptWalkFollowsEveryPageInWireOrder(): void
    {
        $server = $this->stdioPrompter('paging');
        $server->start();

        try {
            $this->assertSame(['p-a', 'p-b', 'p-c'], array_column($server->listPrompts(), 'name'));
        } finally {
            $server->stop();
        }
    }

    public function testARefusedPromptListIsADiagnosisNeverAnEmptyRoster(): void
    {
        $server = $this->stdioPrompter('refuse');
        $server->start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refused prompts/list (-32601): no prompts here');

            $server->listPrompts();
        } finally {
            $server->stop();
        }
    }

    public function testEmptyArgumentsReachTheWireAsAnObjectAndPopulatedOnesAsMaps(): void
    {
        $server = $this->stdioPrompter('wire');
        $server->start();

        try {
            // The tools/call object law, re-pinned for prompts/get: an empty
            // PHP array would encode as `[]` and SDK servers reject it.
            $bare = $server->getPrompt('greet');
            $this->assertSame('object', $bare['argsShape']);
            $this->assertSame('greet', $bare['asked']);
            $this->assertSame([], $bare['echo']);

            $fed = $server->getPrompt('greet', ['who' => 'world']);
            $this->assertSame('object', $fed['argsShape']);
            $this->assertSame(['who' => 'world'], $fed['echo']);
        } finally {
            $server->stop();
        }
    }

    public function testTheStdioAdapterAnswersToThePromptInterface(): void
    {
        $this->assertInstanceOf(McpPromptProvider::class, $this->stdioPrompter('paging'));
    }

    // =========================================================================
    // Http transport
    // =========================================================================

    public function testTheHttpPromptWalkFollowsPagesAndCarriesThePerCallBound(): void
    {
        $server = $this->httpPrompter();
        $captured = [];

        $this->mockPromptHttp->method('post')
            ->willReturnCallback(static function (string $uri, array $options) use (&$captured): Response {
                $captured[] = $options;

                return match (count($captured)) {
                    1 => new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                    3 => new Response(200, [], json_encode(['result' => ['tools' => []]])),
                    4 => new Response(200, [], json_encode(['result' => ['prompts' => [['name' => 'p-a']], 'nextCursor' => 'two']])),
                    5 => new Response(200, [], json_encode(['result' => ['prompts' => [['name' => 'p-b']]]])),
                    default => new Response(202),
                };
            });

        $server->start();

        $this->assertSame(['p-a', 'p-b'], array_column($server->listPrompts(), 'name'));
        $this->assertSame(120.0, $captured[3]['timeout']);
        $this->assertSame(120.0, $captured[4]['timeout']);
    }

    public function testTheHttpGetPromptRidesTheObjectLegAndRefusesErrorEnvelopes(): void
    {
        $server = $this->httpPrompter();
        $sentPayloads = [];

        $this->mockPromptHttp->method('post')
            ->willReturnCallback(static function (string $uri, array $options) use (&$sentPayloads): Response {
                $sentPayloads[] = $options['json'];

                return match (count($sentPayloads)) {
                    1 => new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                    2 => new Response(202),
                    3 => new Response(200, [], json_encode(['result' => ['tools' => []]])),
                    4 => new Response(200, [], json_encode(['result' => ['description' => 'hi', 'messages' => []]])),
                    default => new Response(200, [], json_encode(['error' => ['code' => -32602, 'message' => 'no such prompt']])),
                };
            });

        $server->start();

        $result = $server->getPrompt('greet');
        $this->assertSame('hi', $result['description']);
        // Guzzle receives the payload under `json`, so the OBJECT form must
        // already be a stdClass in the params this transport hands it —
        // `[]` here would encode `"arguments":[]` and the SDK servers reject
        // that (the tools/call law, re-pinned on the prompts leg).
        $this->assertInstanceOf(\stdClass::class, $sentPayloads[3]['params']['arguments']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prompts/get refused (-32602): no such prompt');

        $server->getPrompt('ghost');
    }

    // =========================================================================
    // McpClient aggregation: gate parity, pinned by its own name
    // =========================================================================

    public function testTheClientListsPromptProvidersAndSkipsNonProviders(): void
    {
        $client = $this->promptConfiguredClient();

        $roster = $client->listPrompts();

        $this->assertSame(['prov'], array_keys($roster));
        $this->assertSame(['p-a', 'p-b', 'p-c'], array_column($roster['prov'], 'name'));
    }

    public function testADenyRuleHidesAndRefusesPromptsExactlyLikeTools(): void
    {
        $client = $this->promptConfiguredClient();
        $this->assertNotSame([], $client->listPrompts());

        $client->setDenyPatterns(['prov' => 'deny']);

        $this->assertSame([], $client->listPrompts());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->getPrompt('prov', 'p-a');
    }

    public function testAPresetAllowlistGatesPromptsLikeEveryOtherSurface(): void
    {
        $client = $this->promptConfiguredClient();

        $client->setAgentPreset($this->promptPresetAllowing(['prov']));
        $this->assertSame(['prov'], array_keys($client->listPrompts()));

        $client->setAgentPreset($this->promptPresetAllowing(['plain']));
        $this->assertSame([], $client->listPrompts());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->getPrompt('prov', 'p-a');
    }

    public function testPromptCallsWithNeitherPresetNorUnrestrictedRefuseClosed(): void
    {
        $client = new McpClient($this->configFile);
        $this->clients[] = $client;
        $client->startServers();

        $this->assertSame([], $client->listPrompts());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: prov');

        $client->getPrompt('prov', 'p-a');
    }

    public function testPromptingANonProviderServerRefusesLoudly(): void
    {
        $client = $this->promptConfiguredClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server does not provide prompts: plain');

        $client->getPrompt('plain', 'p-a');
    }

    public function testPromptingAnUnknownServerNamesItAsUnknown(): void
    {
        $client = $this->promptConfiguredClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server: ghost');

        $client->getPrompt('ghost', 'p-a');
    }

    public function testPromptRosterReadsOnDemandLikeItsResourceSibling(): void
    {
        $client = $this->promptConfiguredClient();

        $this->assertSame($client->listPrompts(), $client->listPrompts());
    }

    // =========================================================================
    // Helpers — names unique against the resource sibling by design
    // (DuplicatedTestHelperDrift discipline)
    // =========================================================================

    private function stdioPrompter(string $mode): StdioMcpServer
    {
        return new StdioMcpServer(
            name: 'stdio-' . $mode,
            command: PHP_BINARY,
            args: [$this->script, $mode],
            env: [],
        );
    }

    private function httpPrompter(): HttpMcpServer
    {
        return new HttpMcpServer(
            name: 'http-prompter',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockPromptHttp,
        );
    }

    private function promptConfiguredClient(): McpClient
    {
        $client = new McpClient($this->configFile, unrestricted: true);
        $this->clients[] = $client;
        $client->startServers();

        return $client;
    }

    /** @param list<string> $servers */
    private function promptPresetAllowing(array $servers): AgentPreset
    {
        return new AgentPreset(
            name: 'w2e-prompt-preset',
            description: 'gates the prompt roster',
            mcpServers: $servers,
        );
    }

    private function discardPromptTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->discardPromptTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
