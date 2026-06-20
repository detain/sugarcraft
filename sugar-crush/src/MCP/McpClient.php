<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

require_once __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class McpClient
{
    /** @var array<string, McpServer> */
    private array $servers = [];

    private Client $httpClient;

    public function __construct(
        private string $configPath,
    ) {
        $this->httpClient = new Client(['timeout' => 30]);
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
            default => throw new \RuntimeException("Unknown MCP server type: $type"),
        };

        $server->start();
        $this->servers[$name] = $server;
    }

    /**
     * List available tools from all servers.
     *
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->servers as $server) {
            $tools = array_merge($tools, $server->listTools());
        }

        return $tools;
    }

    /**
     * Call a tool on a specific server.
     */
    public function callTool(string $serverName, string $toolName, array $args): array
    {
        $server = $this->servers[$serverName] ?? null;

        if ($server === null) {
            throw new \RuntimeException("Unknown MCP server: $serverName");
        }

        return $server->callTool($toolName, $args);
    }

    /**
     * Call a tool by name across all servers (first match).
     */
    public function callToolByName(string $toolName, array $args): array
    {
        foreach ($this->servers as $server) {
            $tools = $server->listTools();
            foreach ($tools as $tool) {
                if ($tool->name === $toolName) {
                    return $server->callTool($toolName, $args);
                }
            }
        }

        throw new \RuntimeException("Tool not found: $toolName");
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
