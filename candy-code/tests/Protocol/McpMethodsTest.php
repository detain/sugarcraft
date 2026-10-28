<?php

/**
 * C-MCP-2.b (GAP-MCP-07): `mcp.resources` answers the launch-frozen per-server
 * resource rows the surface pass remembered — bounded at the surface per the
 * RAW BYTES LAW — reading the process-memoised client ONLY, so the readout can
 * never start a server, and a cold root answers `not-started` with nothing.
 *
 * The rows ride a real McpClient over provider fakes (the
 * SessionHostMcpSurfaceTest idiom) seeded into the launch memo, so what is
 * pinned here is the whole peek: decision key, memo hit, roster bounding,
 * the deny gate, BOTH legs of the `status` enum, and the panel contract that
 * `startedSnapshot()` still answers an int COUNT now that the roster itself
 * stores rows.
 *
 * Round 2 (reviewer NIT-1): the class arms McpLaunchEnabledTrait like its
 * sibling CommandMethodsMcpPromptsTest. The peek is memo-only, so the E737
 * hatch never bit this file through the pipe, but the gate rewrites the memo
 * key (the user tier drops out of it) — armed, the key this suite seeds and
 * reads is the operator's shape under every runner, not the shard's.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

final class McpMethodsTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private string $sandbox = '';

    private string $project = '';

    private string $coldProject = '';

    private string $emptyProject = '';

    private EnhancedSessionStore $store;

    private string $seededKey = '';

    private string $emptyKey = '';

    protected function setUp(): void
    {
        $this->armMcpLaunchEnabled();
        $this->sandbox = \sys_get_temp_dir() . '/crush-mcpmeth-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        $this->coldProject = $this->sandbox . '/cold-project';
        $this->emptyProject = $this->sandbox . '/empty-project';
        \mkdir($this->project, 0o700, true);
        \mkdir($this->coldProject, 0o700, true);
        \mkdir($this->emptyProject, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
        $this->store->createSession('c', 'p', 'm');
        $this->store->createSession('e', 'p', 'm');

        $client = $this->resourceSurfaceClient();
        $this->seededKey = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($this->project));
        LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->seededKey, $client);
    }

    protected function tearDown(): void
    {
        if ($this->seededKey !== '') {
            LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->seededKey, null);
        }
        if ($this->emptyKey !== '') {
            LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->emptyKey, null);
        }
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testResourcesAnswerTheBoundedRosterOfTheMemoisedClient(): void
    {
        $answer = $this->resourcesAnswer($this->project, 's');

        self::assertSame('started', $answer['status']);
        $names = \array_column((array) $answer['servers'], 'server');
        self::assertSame(['srv', 'bulk'], $names, 'roster order follows the server map; the denied server is absent');

        $srvRows = (array) ((array) $answer['servers'][0])['resources'];
        self::assertCount(3, $srvRows);

        [$clean, $minimal, $hostile] = $srvRows;
        self::assertSame('file://log', $clean['uri']);
        self::assertSame('log', $clean['name']);
        self::assertSame('text/plain', $clean['mimeType']);
        self::assertSame(400, \mb_strlen((string) $clean['description']), 'the long description is clipped to the cap');
        self::assertStringEndsWith('…', (string) $clean['description']);

        self::assertSame('notes://today', $minimal['uri']);
        self::assertSame('today', $minimal['name']);
        self::assertNull($minimal['mimeType'], 'an absent optional member answers null');
        self::assertNull($minimal['description']);

        self::assertSame('42', $hostile['uri'], 'a non-textual required member is still answered as text');
        self::assertSame('con tr ol', $hostile['name'], 'control bytes flatten to spaces');
    }

    public function testThePerServerRosterIsSlicedToTheKeptRows(): void
    {
        $answer = $this->resourcesAnswer($this->project, 's');

        $bulkRows = (array) ((array) $answer['servers'][1])['resources'];
        self::assertCount(200, $bulkRows, 'the frozen roster keeps a bounded slice, not the whole page walk');
    }

    public function testAColdPeekAnswersNotStartedWithoutBuildingAClient(): void
    {
        // The cold root's decision key was never seeded, and nothing here
        // writes an .mcp.json — the answer must come from the memo miss alone.
        $answer = $this->resourcesAnswer($this->coldProject, 'c');

        self::assertSame('not-started', $answer['status']);
        self::assertSame([], $answer['servers']);
    }

    /**
     * C-MCP-2.b round 2: the schema's `status` enum is the ONLY thing that
     * tells a memoised-but-resource-less client apart from the cold miss —
     * both answer `servers: []`. The started leg must not collapse into the
     * not-started shape: a popup may only read "no servers have resources"
     * when a client really is up.
     */
    public function testAStartedClientWithNoResourceAnswerStillAnswersStarted(): void
    {
        $plain = new class implements McpServer {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }
        };
        $client = new McpClient($this->sandbox . '/absent-' . \bin2hex(\random_bytes(4)) . '.json', unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['plain' => $plain]);

        $this->emptyKey = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($this->emptyProject));
        LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->emptyKey, $client);

        $answer = $this->resourcesAnswer($this->emptyProject, 'e');

        self::assertSame('started', $answer['status'], 'a memoised client is started even with no rows');
        self::assertSame([], $answer['servers'], 'no provider answered, so no server rows surface');
    }

    public function testThePanelSnapshotStillAnswersAnIntCountAfterTheRosterWidened(): void
    {
        $client = $this->resourceSurfaceClient();
        // The launch pass primeSurfaceRosters() runs before the panel renders:
        // roster access primes, then the snapshot derives its counts from it.
        $client->resourcesRoster();

        $snapshot = $client->startedSnapshot();

        $count = $snapshot['srv']['resources'] ?? null;
        self::assertIsInt($count, 'the panel contract: a count, derived from the stored rows');
        self::assertSame(3, $count);
    }

    // ── harness ────────────────────────────────────────────────────────

    /**
     * A real McpClient over three provider servers: clean/minimal/hostile
     * rows, a bulk server for the kept-rows slice, and a denied one that must
     * never surface. Fresh instance per call — the count pin primes its own.
     */
    private function resourceSurfaceClient(): McpClient
    {
        $srv = new class implements McpServer, McpPromptProvider, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                return ['messages' => []];
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                return [
                    ['uri' => 'file://log', 'name' => 'log', 'mimeType' => 'text/plain', 'description' => \str_repeat('x', 900)],
                    ['uri' => 'notes://today', 'name' => 'today'],
                    ['uri' => 42, 'name' => "con\x07tr\x09ol\x07 "],
                ];
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                return ['contents' => [['uri' => $uri, 'text' => 'CMCP2B RES BODY']]];
            }
        };
        $bulk = new class implements McpServer, McpPromptProvider, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                return ['messages' => []];
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                $rows = [];
                // One past the client's SURFACE_RESOURCE_ROWS_KEPT slice.
                for ($i = 0; $i < 205; $i++) {
                    $rows[] = ['uri' => 'bulk://' . $i, 'name' => 'row' . $i];
                }

                return $rows;
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                return ['contents' => []];
            }
        };
        $blocked = new class implements McpServer, McpPromptProvider, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                return ['messages' => []];
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                return [['uri' => 'file://forbidden', 'name' => 'forbidden']];
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                throw new \RuntimeException('the denied server never reads');
            }
        };

        $client = new McpClient($this->sandbox . '/absent-' . \bin2hex(\random_bytes(4)) . '.json', unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['srv' => $srv, 'bulk' => $bulk, 'blocked' => $blocked]);
        $client->setDenyPatterns(['blocked' => 'deny']);

        return $client;
    }

    /**
     * `mcp.resources` over the real dispatcher for a hub-built host rooted at
     * $root. A host built for the cold root falls through to the builder
     * memo-miss and answers from its (empty) map — the wire never sees a
     * started client it did not seed.
     *
     * @return array<string, mixed>
     */
    private function resourcesAnswer(string $root, string $sessionId): array
    {
        $workspace = WorkspaceContext::new(root: $root, sessionStore: $this->store);
        $hub = SessionHub::new($workspace);
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($root), 'cmcp2b-mcp', Loop::get());
        $dispatcher = Dispatcher::new($context);
        $client = WireClient::open($dispatcher, 'cmcp2b-roster-client');
        $client->hello();

        $answer = (array) $client->call('mcp.resources', ['sessionId' => $sessionId]);

        $dispatcher->stop('test over');
        $hub->closeAll();
        $settle = Loop::addTimer(0.001, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($settle);

        return $answer;
    }
}
