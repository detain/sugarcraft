declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

require_once __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;

final class HttpMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    private bool $initialized = false;

    public function __construct(
        public readonly string $name,
        private string $url,
        private array $headers,
        private Client $httpClient,
    ) {}

    public function start(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize
            $this->httpClient->post($this->url, [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 0,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => [],
                        'clientInfo' => ['name' => 'candy-crush', 'version' => '1.0.0'],
                    ],
                ],
                'headers' => $this->headers,
            ]);

            $this->initialized = true;

            // List tools
            $response = $this->httpClient->post($this->url, [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/list',
                    'params' => [],
                ],
                'headers' => $this->headers,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (is_array($data)) {
                $this->tools = $this->parseTools($data);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to start MCP server {$this->name}: {$e->getMessage()}");
        }
    }

    public function stop(): void
    {
        // HTTP servers don't need stopping
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
            $response = $this->httpClient->post($this->url, [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => time(),
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $toolName,
                        'arguments' => $args,
                    ],
                ],
                'headers' => $this->headers,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return is_array($data) ? ($data['result'] ?? ['error' => 'Tool call failed']) : ['error' => 'Invalid response'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
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
