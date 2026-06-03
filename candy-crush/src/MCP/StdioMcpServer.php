declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class StdioMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    public function __construct(
        public readonly string $name,
        private string $command,
        private array $args,
        private array $env,
    ) {}

    public function start(): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', [$this->command, ...$this->args]));

        $this->process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            null,
            $this->env
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start MCP server: {$this->name}");
        }

        // Initialize - send capabilities request
        $this->send(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'candy-crush', 'version' => '1.0.0'],
        ]]);

        // List tools
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $this->tools = $this->parseTools($response);
    }

    public function stop(): void
    {
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = null;
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
        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => time(),
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $args,
            ],
        ]);

        return $response['result'] ?? ['error' => 'Tool call failed'];
    }

    /**
     * @param array<mixed> $message
     * @return array<mixed>
     */
    private function send(array $message): array
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return [];
        }

        $json = json_encode($message);
        if ($json === false) {
            return [];
        }

        fwrite($this->pipes[0], $json . "\n");
        fflush($this->pipes[0]);

        $line = fgets($this->pipes[1]);
        if ($line === false) {
            return [];
        }

        $response = json_decode(trim($line), true);
        return is_array($response) ? $response : [];
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
