<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;

final class HttpMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    private bool $initialized = false;

    /** Monotonic JSON-RPC request id — never reuse an id within a session. */
    private int $nextId = 0;

    public function __construct(
        public readonly string $name,
        private string $url,
        private array $headers,
        private Client $httpClient,
    ) {}

    public function start(): void
    {
        // Idempotent: a started server must not re-issue the HTTP handshake.
        if ($this->initialized) {
            return;
        }

        try {
            $this->rpc('initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
            ]);

            $response = $this->rpc('tools/list', []);

            $data = json_decode($response->getBody()->getContents(), true);
            // A non-JSON / non-object body (e.g. an HTTP 5xx error page) means the
            // tools/list leg of the handshake failed — surface it as a start failure.
            if (!is_array($data)) {
                throw new \RuntimeException('tools/list returned an invalid response');
            }
            $this->tools = $this->parseTools($data);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to start MCP server {$this->name}: {$e->getMessage()}");
        }

        // Mark initialized only after the full handshake succeeds, so a failed
        // start can be retried rather than wedging the server half-open.
        $this->initialized = true;
    }

    public function stop(): void
    {
        // HTTP servers are stateless per request — nothing to tear down.
    }

    /**
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args): array
    {
        try {
            $response = $this->rpc('tools/call', [
                'name' => $toolName,
                'arguments' => $args,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return is_array($data) ? ($data['result'] ?? ['error' => 'Tool call failed']) : ['error' => 'Invalid response'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Issue a single JSON-RPC request over HTTP with the configured headers.
     *
     * @param array<string, mixed> $params
     */
    private function rpc(string $method, array $params): \Psr\Http\Message\ResponseInterface
    {
        return $this->httpClient->post($this->url, [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => $this->nextId++,
                'method' => $method,
                'params' => $params,
            ],
            'headers' => $this->headers,
        ]);
    }

    /**
     * @param array<mixed> $response
     * @return array<McpTool>
     */
    private function parseTools(array $response): array
    {
        $tools = [];
        $toolDefs = $response['result']['tools'] ?? [];

        foreach ($toolDefs as $def) {
            if (is_array($def)) {
                $tools[] = McpTool::fromArray($def, $this->name);
            }
        }

        return $tools;
    }
}
