<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\MCP\McpRouter;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\McpTool;

/**
 * @see McpRouter
 */
final class McpRouterTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a simple in-memory McpServer double that returns pre-configured tools.
     *
     * @param array<McpTool> $tools
     */
    private function makeServer(string $name, array $tools): McpServer
    {
        return new class($name, $tools) implements McpServer {
            /** @var array<McpTool> */
            private array $tools;

            public function __construct(
                public readonly string $name,
                array $tools,
            ) {
                $this->tools = $tools;
            }

            public function start(): void {}
            public function stop(): void {}

            /** @return array<McpTool> */
            public function listTools(): array
            {
                return $this->tools;
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return ['error' => 'not implemented in test double'];
            }
        };
    }

    /**
     * Build an AgentPreset with only the fields relevant to routing tests.
     *
     * @param array<string> $mcpServers
     */
    private function makePreset(array $mcpServers = []): AgentPreset
    {
        return new AgentPreset(
            name: 'test-preset',
            description: 'Test preset for routing',
            tools: [],
            disallowedTools: [],
            model: 'inherit',
            permissionMode: \SugarCraft\Crush\Permissions\PermissionMode::Default,
            maxTurns: null,
            skills: [],
            mcpServers: $mcpServers,
            memory: \SugarCraft\Crush\Agents\MemoryScope::User,
            background: false,
            effort: \SugarCraft\Crush\Agents\Effort::Medium,
            isolation: null,
            color: null,
            initialPrompt: null,
        );
    }

    // =========================================================================
    // resolveAllowedTools — basic cases
    // =========================================================================

    public function testEmptyPresetSeesAllServers(): void
    {
        $servers = [
            'docs-search' => $this->makeServer('docs-search', [
                new McpTool('search', 'Search docs', [], 'docs-search'),
            ]),
            'db' => $this->makeServer('db', [
                new McpTool('query', 'Run SQL', [], 'db'),
            ]),
        ];

        $router = new McpRouter($servers);
        $preset = $this->makePreset([]);

        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(2, $tools);
        $names = array_column($tools, 'name');
        $this->assertContains('search', $names);
        $this->assertContains('query', $names);
    }

    public function testPresetAllowlistRestrictsServers(): void
    {
        $servers = [
            'docs-search' => $this->makeServer('docs-search', [
                new McpTool('search', 'Search docs', [], 'docs-search'),
            ]),
            'db' => $this->makeServer('db', [
                new McpTool('query', 'Run SQL', [], 'db'),
            ]),
            'files' => $this->makeServer('files', [
                new McpTool('read', 'Read file', [], 'files'),
            ]),
        ];

        $router = new McpRouter($servers);

        // Architect preset gets only docs-search
        $architectPreset = $this->makePreset(['docs-search']);
        $architectTools = $router->resolveAllowedTools($architectPreset);
        $this->assertCount(1, $architectTools);
        $this->assertSame('search', $architectTools[0]->name);
        $this->assertSame('docs-search', $architectTools[0]->serverName);

        // Coder preset gets db + files
        $coderPreset = $this->makePreset(['db', 'files']);
        $coderTools = $router->resolveAllowedTools($coderPreset);
        $this->assertCount(2, $coderTools);
        $names = array_column($coderTools, 'name');
        $this->assertContains('query', $names);
        $this->assertContains('read', $names);
    }

    public function testPresetAllowlistWithGlobPatterns(): void
    {
        $servers = [
            'untrusted-fetch' => $this->makeServer('untrusted-fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted-fetch'),
            ]),
            'untrusted-shell' => $this->makeServer('untrusted-shell', [
                new McpTool('shell', 'Run shell', [], 'untrusted-shell'),
            ]),
            'trusted-docs' => $this->makeServer('trusted-docs', [
                new McpTool('search', 'Search docs', [], 'trusted-docs'),
            ]),
        ];

        $router = new McpRouter($servers);

        // Allowlist with wildcard: allow all trusted-* servers
        $preset = $this->makePreset(['trusted-*']);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('search', $tools[0]->name);
    }

    public function testServerNotInAllowlistReturnsEmptyArray(): void
    {
        $servers = [
            'docs-search' => $this->makeServer('docs-search', [
                new McpTool('search', 'Search docs', [], 'docs-search'),
            ]),
        ];

        $router = new McpRouter($servers);

        // Preset allowlist names a server that doesn't exist
        $preset = $this->makePreset(['nonexistent-server']);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertSame([], $tools);
    }

    // =========================================================================
    // resolveAllowedTools — deny patterns
    // =========================================================================

    public function testDenyPatternBlocksMatchingServers(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted_fetch'),
            ]),
            'trusted_docs' => $this->makeServer('trusted_docs', [
                new McpTool('search', 'Search docs', [], 'trusted_docs'),
            ]),
        ];

        $router = new McpRouter($servers, ['untrusted_*' => 'deny']);

        // No allowlist — deny pattern should block untrusted_fetch
        $preset = $this->makePreset([]);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('search', $tools[0]->name);
    }

    public function testDenyPatternAppliesBeforeAllowlist(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted_fetch'),
            ]),
            'untrusted_shell' => $this->makeServer('untrusted_shell', [
                new McpTool('shell', 'Run shell', [], 'untrusted_shell'),
            ]),
            'trusted_docs' => $this->makeServer('trusted_docs', [
                new McpTool('search', 'Search docs', [], 'trusted_docs'),
            ]),
        ];

        $router = new McpRouter($servers, ['untrusted_*' => 'deny']);

        // Preset explicitly names untrusted_fetch — but deny pattern should still block it
        $preset = $this->makePreset(['untrusted_fetch', 'trusted_docs']);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('search', $tools[0]->name);
        $this->assertSame('trusted_docs', $tools[0]->serverName);
    }

    public function testMultipleDenyPatterns(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted_fetch'),
            ]),
            'untrusted_shell' => $this->makeServer('untrusted_shell', [
                new McpTool('shell', 'Run shell', [], 'untrusted_shell'),
            ]),
            'private_db' => $this->makeServer('private_db', [
                new McpTool('query', 'Run SQL', [], 'private_db'),
            ]),
            'trusted_docs' => $this->makeServer('trusted_docs', [
                new McpTool('search', 'Search docs', [], 'trusted_docs'),
            ]),
        ];

        $router = new McpRouter($servers, [
            'untrusted_*' => 'deny',
            'private_*' => 'deny',
        ]);

        $preset = $this->makePreset([]);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('search', $tools[0]->name);
    }

    public function testDenyPatternWithNonMatchingGlob(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted_fetch'),
            ]),
            'safe_fetch' => $this->makeServer('safe_fetch', [
                new McpTool('fetch_safe', 'Safe fetch', [], 'safe_fetch'),
            ]),
        ];

        // Deny pattern "untrusted_*" should NOT block "safe_fetch"
        $router = new McpRouter($servers, ['untrusted_*' => 'deny']);

        $preset = $this->makePreset([]);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('safe_fetch', $tools[0]->serverName);
    }

    public function testDenyPatternActionOtherThanDenyIsIgnored(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', [
                new McpTool('fetch', 'Fetch URL', [], 'untrusted_fetch'),
            ]),
        ];

        // "warn" is not "deny" — should not block
        $router = new McpRouter($servers, ['untrusted_fetch' => 'warn']);

        $preset = $this->makePreset([]);
        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
    }

    // =========================================================================
    // resolveAllowedServers
    // =========================================================================

    public function testResolveAllowedServersReturnsFilteredNames(): void
    {
        $servers = [
            'docs-search' => $this->makeServer('docs-search', []),
            'db' => $this->makeServer('db', []),
        ];

        $router = new McpRouter($servers, ['untrusted_*' => 'deny']);
        $preset = $this->makePreset(['docs-search']);

        $allowed = $router->resolveAllowedServers($preset);

        $this->assertSame(['docs-search'], $allowed);
    }

    public function testResolveAllowedServersWithEmptyAllowlistReturnsAllAfterDeny(): void
    {
        $servers = [
            'untrusted_fetch' => $this->makeServer('untrusted_fetch', []),
            'trusted_docs' => $this->makeServer('trusted_docs', []),
        ];

        $router = new McpRouter($servers, ['untrusted_*' => 'deny']);
        $preset = $this->makePreset([]);

        $allowed = $router->resolveAllowedServers($preset);

        $this->assertSame(['trusted_docs'], $allowed);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyServerMapReturnsEmptyTools(): void
    {
        $router = new McpRouter([]);
        $preset = $this->makePreset([]);

        $tools = $router->resolveAllowedTools($preset);

        $this->assertSame([], $tools);
    }

    public function testPresetWithCompletelyUnknownAllowlistReturnsEmpty(): void
    {
        $servers = [
            'docs-search' => $this->makeServer('docs-search', []),
        ];

        $router = new McpRouter($servers);
        $preset = $this->makePreset(['nonexistent', 'also-unknown']);

        $tools = $router->resolveAllowedTools($preset);

        $this->assertSame([], $tools);
    }

    public function testServerWithNoToolsReturnsEmptyForThatServer(): void
    {
        $servers = [
            'empty-server' => $this->makeServer('empty-server', []),
            'docs-search' => $this->makeServer('docs-search', [
                new McpTool('search', 'Search docs', [], 'docs-search'),
            ]),
        ];

        $router = new McpRouter($servers);
        $preset = $this->makePreset([]);

        $tools = $router->resolveAllowedTools($preset);

        $this->assertCount(1, $tools);
        $this->assertSame('search', $tools[0]->name);
    }
}
