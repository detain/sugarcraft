<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\Tool;

/**
 * C-AGT-3: a preset's `mcpServers` allowlist scopes the DEFERRED dispatchers,
 * not just the bridges. Before this lane, `McpClient::setAgentPreset()` had
 * zero production callers and `resolveGrantedTools()` handed every agent the
 * launch-wide `mcp_call`/`mcp_list` over the shared unrestricted client — so a
 * preset narrowed to one server could still name any other one through the
 * dispatcher. These tests pin the granted-instance swap on BOTH grant paths
 * (declared and ISS-030 silent), the refusal of a server outside the list, the
 * in-list tool still running (the scope is a narrowing, not a breakage), and
 * the two structural laws of the clone: the shared launch client is never
 * mutated, and started servers travel by handle, not by duplication.
 *
 * Hermetic by construction: the McpServer doubles are 5-line stubs injected
 * into the client's server map by reflection — no config, no handshake, no
 * child. The roster math reads only `name()` and the frozen descriptors.
 */
final class McpDispatcherPresetScopeTest extends TestCase
{
    private static int $serial = 0;

    private function scopeServer(): string
    {
        return 'c-agt-3-' . (++self::$serial) . '-' . getmypid();
    }

    /**
     * A stub server: answers listTools() with the one fat tool it was minted
     * with, and records every callTool() it receives.
     *
     * @return array{McpServer, McpTool, \Closure(): list<array{0:string,1:array<string,mixed>}>}
     */
    private function stubServer(string $server, string $toolName): array
    {
        $calls = [];
        $tool = new McpTool(
            name: $toolName,
            description: str_repeat('wide ', 400),
            inputSchema: ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]],
            serverName: $server,
        );
        $stub = new class ($tool, $toolName, $calls) implements McpServer {
            /** @param list<array{0:string,1:array<string,mixed>}> $calls */
            public function __construct(
                private readonly McpTool $tool,
                private readonly string $toolName,
                public array $calls,
            ) {}

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                $this->calls[] = [$toolName, $args];

                return ['content' => [['type' => 'text', 'text' => 'ran ' . $this->toolName]]];
            }
        };

        return [$stub, $tool, static fn (): array => $stub->calls];
    }

    /**
     * The launch fixture: one client with two STUB servers injected (docs-side
     * allowed, git-side outside every scope list in these tests), a shape
     * whose floor defers both, and the dispatcher pair built from it.
     *
     * @return array{McpClient, string, string, McpCallTool, McpListTool}
     *         client, docs server name, git server name, call dispatcher, list dispatcher
     */
    private function launchFixture(): array
    {
        $docs = $this->scopeServer() . '-docs';
        $git = $this->scopeServer() . '-git';
        [$docsStub, , ] = $this->stubServer($docs, 'note');
        [$gitStub, , ] = $this->stubServer($git, 'push');

        $client = new McpClient('/nonexistent/c-agt-3/.mcp.json', unrestricted: true);
        $servers = new \ReflectionProperty(McpClient::class, 'servers');
        $servers->setValue($client, [$docs => $docsStub, $git => $gitStub]);

        $shape = McpToolShape::derive(
            $client,
            [$docsStub->listTools()[0], $gitStub->listTools()[0]],
            [],
            1,
        );
        $this->assertCount(2, $shape->dispatchedDescriptors, 'both fat servers defer at floor 1 — the instrument is live');

        return [$client, $docs, $git, $shape->callTool(), $shape->listTool()];
    }

    /** @param list<Tool> $registry */
    private function managerWith(array $registry): AgentManager
    {
        return new AgentManager(
            provider: $this->createMock(ProviderInterface::class),
            skillRegistry: new SkillRegistry(),
            toolRegistry: $registry,
        );
    }

    /** @param list<string> $tools @param list<array-key, mixed> $mcpServers */
    private function agentNamed(string $name, array $tools, array $mcpServers): Agent
    {
        return new Agent(
            name: $name,
            description: 'test agent ' . $name,
            prompt: 'You are ' . $name . '.',
            model: 'test-model',
            provider: 'test',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            mcpServers: $mcpServers,
        );
    }

    /** @param list<Tool>|null $granted @param class-string<Tool> $type */
    private function firstGranted(?array $granted, string $type): Tool
    {
        $this->assertNotNull($granted, 'a named mcpServers allowlist resolves to a roster, never null');
        foreach ($granted as $tool) {
            if ($tool instanceof $type) {
                return $tool;
            }
        }

        $this->fail('the granted roster carries no ' . $type);
    }

    public function testTheDeclaredGrantRefusesACallToAServerOutsideTheAllowlist(): void
    {
        [, $docs, $git, $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        $manager->register($this->agentNamed('scoped', ['mcp_call', 'mcp_list'], [$docs]));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('scoped', 'task'));
        $scopedCall = $this->firstGranted($granted, McpCallTool::class);
        $this->assertNotSame($call, $scopedCall, 'the granted dispatcher is the scoped copy, not the launch instance');

        $refusal = $scopedCall->execute(['server' => $git, 'tool' => 'push', 'arguments' => []]);
        $this->assertTrue($refusal->isError());
        $this->assertStringContainsString('limited to the deferred roster', $refusal->content());
    }

    public function testTheScopedDispatcherStillRunsAnAllowedServerLive(): void
    {
        [$client, $docs, , $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        $manager->register($this->agentNamed('scoped', ['mcp_call', 'mcp_list'], [$docs]));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('scoped', 'task'));
        $scopedCall = $this->firstGranted($granted, McpCallTool::class);

        $ran = $scopedCall->execute(['server' => $docs, 'tool' => 'note', 'arguments' => ['note' => 'hi']]);
        $this->assertFalse($ran->isError(), 'the scope narrows, it does not break: ' . $ran->content());
        $this->assertStringContainsString('ran note', $ran->content());

        // The servers ride the clone BY HANDLE (risk pinned): the call
        // reached the SAME stub object the launch client holds.
        $servers = new \ReflectionProperty(McpClient::class, 'servers');
        $launchServers = $servers->getValue($client);
        $scopedShape = (new \ReflectionProperty(McpCallTool::class, 'shape'))->getValue($scopedCall);
        $this->assertSame($launchServers[$docs], $servers->getValue($scopedShape->client)[$docs]);
    }

    public function testTheScopedListToolPrintsOnlyTheAllowedServer(): void
    {
        [, $docs, $git, $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        $manager->register($this->agentNamed('scoped', ['mcp_call', 'mcp_list'], [$docs]));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('scoped', 'task'));
        $scopedList = $this->firstGranted($granted, McpListTool::class);
        $this->assertNotSame($list, $scopedList);

        $printed = $scopedList->execute([]);
        $this->assertFalse($printed->isError());
        $this->assertStringContainsString('Server ' . $docs . ':', $printed->content());
        $this->assertStringNotContainsString('Server ' . $git . ':', $printed->content());
        $this->assertStringNotContainsString('push', $printed->content(), 'the outside server is invisible, not merely filtered from the header');
    }

    public function testTheSilentToolsPathScopesTheDispatchersToo(): void
    {
        [, $docs, $git, $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        // ISS-030 path: NO `tools:` declaration, but `mcpServers` is said.
        $manager->register($this->agentNamed('silent', [], [$docs]));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('silent', 'task'));
        $scopedCall = $this->firstGranted($granted, McpCallTool::class);
        $this->assertNotSame($call, $scopedCall, 'the full-registry-minus-refused path swaps too');

        $refusal = $scopedCall->execute(['server' => $git, 'tool' => 'push', 'arguments' => []]);
        $this->assertTrue($refusal->isError());
        $this->assertStringContainsString('limited to the deferred roster', $refusal->content());
    }

    public function testAnAgentThatNamesNoServersKeepsTheLaunchDispatchersThemselves(): void
    {
        [, , , $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        $manager->register($this->agentNamed('wide', ['mcp_call', 'mcp_list'], []));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('wide', 'task'));
        $this->assertSame([$call, $list], $granted, 'no scope, no copies — the grant is identity');
    }

    public function testTheSharedLaunchClientStaysUnscopedAfterTheScopedGrantResolves(): void
    {
        [$client, $docs, $git, $call, $list] = $this->launchFixture();
        $manager = $this->managerWith([$call, $list]);
        $manager->register($this->agentNamed('scoped', ['mcp_call', 'mcp_list'], [$docs]));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('scoped', 'task'));
        $scopedCall = $this->firstGranted($granted, McpCallTool::class);
        $scopedClient = (new \ReflectionProperty(McpCallTool::class, 'shape'))->getValue($scopedCall)->client;
        $this->assertNotSame($client, $scopedClient);

        // The child is bound; the parent is not. `setAgentPreset()` was the
        // dormant setter this lane woke — a mutation in place would have
        // de-scoped the MAIN agent's own MCP reach by the first delegation.
        $preset = new \ReflectionProperty(McpClient::class, 'agentPreset');
        $this->assertNull($preset->getValue($client), 'the shared launch client carries no preset after the child runs');
        $this->assertNotNull($preset->getValue($scopedClient));

        $this->assertCount(2, $client->listTools(), 'the parent still sees both servers');
        $this->assertSame([['type' => 'text', 'text' => 'ran push']], $client->callTool($git, 'push', [])['content']);

        $this->assertSame(
            ['note'],
            array_map(static fn (McpTool $t): string => $t->name, $scopedClient->listTools()),
        );
        try {
            $scopedClient->callTool($git, 'push', []);
            $this->fail('the scoped client reached a server outside the allowlist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed for this agent', $e->getMessage());
        }
    }
}
