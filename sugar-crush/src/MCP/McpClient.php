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
     * against $agentPreset->mcpServers; when null, routing is unrestricted
     * (equivalent to a preset with an empty mcpServers allowlist).
     */
    private ?AgentPreset $agentPreset;

    public function __construct(
        private string $configPath,
        ?Client $httpClient = null,
        ?AgentPreset $agentPreset = null,
    ) {
        // Injectable so tests can supply a MockHandler-backed client; defaults to
        // a real client for production use.
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
        $this->agentPreset = $agentPreset;
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
     * any) is allowed to see per McpRouter::resolveAllowedTools().
     *
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        if ($this->agentPreset === null) {
            $tools = [];

            foreach ($this->servers as $server) {
                $tools = array_merge($tools, $server->listTools());
            }

            return $tools;
        }

        return $this->router()->resolveAllowedTools($this->agentPreset);
    }

    /**
     * Call a tool on a specific server. Rejected when the current agent
     * preset's mcpServers allowlist does not cover $serverName, so a caller
     * cannot bypass listTools() filtering by naming a denied server directly.
     */
    public function callTool(string $serverName, string $toolName, array $args): array
    {
        $server = $this->servers[$serverName] ?? null;

        if ($server === null) {
            throw new \RuntimeException("Unknown MCP server: $serverName");
        }

        if ($this->agentPreset !== null && !in_array($serverName, $this->router()->resolveAllowedServers($this->agentPreset), true)) {
            throw new \RuntimeException("MCP server not allowed for this agent: $serverName");
        }

        return $server->callTool($toolName, $args);
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
     * Build an McpRouter over this client's currently-started servers.
     */
    private function router(): McpRouter
    {
        return new McpRouter($this->servers);
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
