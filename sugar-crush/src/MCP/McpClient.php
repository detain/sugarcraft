<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Crush\Agents\AgentPreset;

final class McpClient
{
    /** @var array<string, McpServer> */
    private array $servers = [];

    private Client $httpClient;

    /**
     * The preset of the agent currently driving this client, if any. When set,
     * listTools()/callTool()/callToolByName() are filtered through McpRouter
     * against $agentPreset->mcpServers.
     */
    private ?AgentPreset $agentPreset;

    /**
     * Escape hatch for callers that are genuinely not agent-scoped (e.g.
     * system/admin tooling). Fails CLOSED by default: with no agent preset
     * attached, listTools()/callTool() see and reach nothing at all, so a
     * caller that forgets to call setAgentPreset() is denied rather than
     * silently handed every configured server's every tool.
     */
    private bool $unrestricted;

    /** @var array<string, string> */
    private array $denyPatterns;

    public function __construct(
        private string $configPath,
        ?Client $httpClient = null,
        ?AgentPreset $agentPreset = null,
        bool $unrestricted = false,
        array $denyPatterns = [],
    ) {
        // Injectable so tests can supply a MockHandler-backed client; defaults to
        // a real client for production use.
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
        $this->agentPreset = $agentPreset;
        $this->unrestricted = $unrestricted;
        $this->denyPatterns = $denyPatterns;
    }

    /**
     * Set (or clear) the preset of the agent driving this client, so that
     * subsequent listTools()/callTool()/callToolByName() calls are routed
     * through McpRouter::resolveAllowedTools() against the preset's mcpServers
     * allowlist rather than exposing every configured server unconditionally.
     */
    public function setAgentPreset(?AgentPreset $agentPreset): void
    {
        $this->agentPreset = $agentPreset;
    }

    /**
     * Set the global wildcard deny-pattern map (server-name pattern => "deny")
     * forwarded to McpRouter on every routed call, alongside the active agent
     * preset's allowlist.
     *
     * @param array<string, string> $denyPatterns
     */
    public function setDenyPatterns(array $denyPatterns): void
    {
        $this->denyPatterns = $denyPatterns;
    }

    /**
     * Load and start MCP servers from config.
     */
    public function startServers(): void
    {
        $config = $this->loadConfig();

        foreach ($config['mcpServers'] ?? [] as $name => $serverConfig) {
            $this->startServer($name, $serverConfig);
        }
    }

    /**
     * Stop all MCP servers.
     */
    public function stopServers(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    /**
     * Start a single server.
     */
    private function startServer(string $name, array $config): void
    {
        $type = $config['type'] ?? 'stdio';

        $server = match ($type) {
            'stdio' => new StdioMcpServer(
                name: $name,
                command: $config['command'] ?? '',
                args: $config['args'] ?? [],
                env: $this->resolveEnv($config['env'] ?? []),
            ),
            'http' => new HttpMcpServer(
                name: $name,
                url: $config['url'] ?? '',
                headers: $this->resolveEnv($config['headers'] ?? []),
                httpClient: $this->httpClient,
            ),
            'git' => new GitMcpServer(
                name: $name,
                handlers: new GitCommandHandlers(
                    cwd: $config['path'] ?? null,
                ),
            ),
            default => throw new \RuntimeException("Unknown MCP server type: $type"),
        };

        // A single unreachable/misbehaving server must not abort loading the rest.
        // An unknown type is a config error and is thrown above, before we get here.
        try {
            $server->start();
        } catch (\RuntimeException) {
            return;
        }

        $this->servers[$name] = $server;
    }

    /**
     * List available tools, restricted to what the current agent preset (if
     * any) is allowed to see per McpRouter::resolveAllowedTools(). With no
     * preset attached, returns nothing unless $unrestricted was explicitly
     * set at construction time.
     *
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        if ($this->agentPreset !== null) {
            return $this->router()->resolveAllowedTools($this->agentPreset);
        }

        if (!$this->unrestricted) {
            return [];
        }

        $tools = [];

        foreach ($this->servers as $server) {
            $tools = array_merge($tools, $server->listTools());
        }

        return $tools;
    }

    /**
     * Call a tool on a specific server. Rejected when the current agent
     * preset's mcpServers allowlist does not cover $serverName, so a caller
     * cannot bypass listTools() filtering by naming a denied server directly.
     * With no preset attached, rejected unconditionally unless $unrestricted
     * was explicitly set at construction time.
     */
    public function callTool(string $serverName, string $toolName, array $args): array
    {
        $server = $this->servers[$serverName] ?? null;

        if ($server === null) {
            throw new \RuntimeException("Unknown MCP server: $serverName");
        }

        if (!$this->isServerAllowed($serverName)) {
            throw new \RuntimeException("MCP server not allowed for this agent: $serverName");
        }

        return $server->callTool($toolName, $args);
    }

    /**
     * Whether $serverName is reachable under the current routing mode: via
     * the active agent preset's allowlist (and any deny patterns), or via the
     * explicit $unrestricted escape hatch when no preset is attached.
     */
    private function isServerAllowed(string $serverName): bool
    {
        if ($this->agentPreset !== null) {
            return in_array($serverName, $this->router()->resolveAllowedServers($this->agentPreset), true);
        }

        return $this->unrestricted;
    }

    /**
     * Call a tool by name across all servers the current agent preset is
     * allowed to see (first match). Tools belonging to servers outside the
     * preset's allowlist are invisible here, not merely unlisted.
     */
    public function callToolByName(string $toolName, array $args): array
    {
        foreach ($this->listTools() as $tool) {
            if ($tool->name !== $toolName) {
                continue;
            }

            $server = $this->servers[$tool->serverName] ?? null;
            if ($server !== null) {
                return $server->callTool($toolName, $args);
            }
        }

        throw new \RuntimeException("Tool not found: $toolName");
    }

    /**
     * Build an McpRouter over this client's currently-started servers,
     * forwarding the configured global deny patterns alongside the servers
     * so both halves of McpRouter's enforcement (allowlist + deny patterns)
     * are actually wired through, not just the allowlist.
     */
    private function router(): McpRouter
    {
        return new McpRouter($this->servers, $this->denyPatterns);
    }

    private function loadConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function resolveEnv(array $env): array
    {
        $resolved = [];

        foreach ($env as $key => $value) {
            if (is_string($value) && preg_match('/^\$\{(.*?)(?::-(.*))?\}$/', $value, $matches)) {
                $resolved[$key] = getenv($matches[1]) ?: ($matches[2] ?? '');
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
