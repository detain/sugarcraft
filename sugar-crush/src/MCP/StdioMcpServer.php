<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

require_once __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Crush\McpMessage;

final class StdioMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    /** Monotonic JSON-RPC request id — avoids collisions that `time()` causes. */
    private int $nextId = 0;

    /**
     * Persistent read buffer so a partial NDJSON line survives across reads:
     * one fgets() may return less than a full line, and a server may emit
     * several messages in one burst.
     */
    private string $readBuffer = '';

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

        // Handshake: `initialize` is a REQUEST expecting a response, followed by
        // the `initialized` NOTIFICATION per the MCP spec. proc_open succeeds for
        // a bogus binary too (the shell launches), so a missing/invalid initialize
        // response is how we detect a server that never really came up.
        $response = $this->request('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
        ]);

        if ($response === null || ($response->result === null && $response->error === null)) {
            $this->stop();
            throw new \RuntimeException("Failed to start MCP server: {$this->name}");
        }

        $this->notify('initialized');

        $listResponse = $this->request('tools/list', []);
        $this->tools = $listResponse === null ? [] : $this->parseTools($listResponse->toArray());
    }

    public function stop(): void
    {
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = null;
        $this->readBuffer = '';
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
        $response = $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => $args,
        ]);

        if ($response === null || $response->result === null) {
            return ['error' => 'Tool call failed'];
        }

        return $response->result;
    }

    /**
     * Send a JSON-RPC request and read the matching response (by id).
     *
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params): ?McpMessage
    {
        $id = (string) $this->nextId++;
        if (!$this->writeLine(McpMessage::request($id, $method, $params)->toJson())) {
            return null;
        }

        return $this->readResponse($id);
    }

    /**
     * Send a JSON-RPC notification (no id, no response expected).
     *
     * @param array<string, mixed>|null $params
     */
    private function notify(string $method, ?array $params = null): void
    {
        $this->writeLine(McpMessage::notification($method, $params)->toJson());
    }

    /**
     * Low-level write-then-read for a single raw JSON-RPC message. Retained as a
     * private primitive (exercised directly via reflection): returns the decoded
     * response array, or [] when the process is down / no response arrives.
     *
     * @param array<mixed> $message
     * @return array<mixed>
     */
    private function send(array $message): array
    {
        $json = json_encode($message);
        if ($json === false || !$this->writeLine($json)) {
            return [];
        }

        $line = $this->readLine();
        if ($line === null) {
            return [];
        }

        $response = json_decode($line, true);

        return is_array($response) ? $response : [];
    }

    /**
     * Write one newline-framed message to the child's stdin.
     */
    private function writeLine(string $json): bool
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return false;
        }

        // A dead child (e.g. a bogus command that already exited) closes the pipe;
        // writing then raises a "broken pipe" notice. Suppress it — the missing
        // response is what signals start() that the server failed, not the write.
        if (@fwrite($this->pipes[0], $json . "\n") === false) {
            return false;
        }
        fflush($this->pipes[0]);

        return true;
    }

    /**
     * Read NDJSON lines until one parses into the response for $id, skipping
     * server-initiated notifications and stale responses. Returns null on EOF
     * before a match.
     */
    private function readResponse(string $id): ?McpMessage
    {
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                return null;
            }

            $message = McpMessage::parse($line);
            if ($message === null) {
                // Not JSON-RPC at all (e.g. `echo`/`cat` plumbing echoing our own
                // text): treat as a failed exchange rather than looping forever.
                return null;
            }

            // Skip server-initiated notifications and stale responses for other ids.
            if ($message->isNotification() || ($message->id !== null && $message->id !== $id)) {
                continue;
            }

            return $message;
        }
    }

    /**
     * Pull one newline-terminated line from the persistent buffer, refilling
     * from the stdout pipe as needed.
     */
    private function readLine(): ?string
    {
        while (($newline = strpos($this->readBuffer, "\n")) === false) {
            // No pipe to refill from (process down) — flush any trailing bytes.
            if ($this->pipes === null) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $chunk = fgets($this->pipes[1]);
            if ($chunk === false || $chunk === '') {
                // EOF with leftover buffered bytes: emit them as the final line.
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }
            $this->readBuffer .= $chunk;
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    /** Consume and return the entire pending buffer as one trimmed line. */
    private function drainBuffer(): string
    {
        $line = $this->readBuffer;
        $this->readBuffer = '';

        return trim($line);
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
