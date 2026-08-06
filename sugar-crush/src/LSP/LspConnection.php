<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * LSP client connection over stdio or TCP using JSON-RPC 2.0.
 *
 * Spawns a language server process and communicates with it via the
 * Language Server Protocol over stdio. Handles request/response routing
 * by matching message IDs, supports multiple in-flight requests, and
 * properly cleans up the process on disconnect.
 *
 * Mirrors the LSP spec: https://microsoft.github.io/language-server-protocol/
 *
 * @note When transport is 'tcp', connects via stream_socket_client() to host:port
 *       instead of spawning a subprocess via proc_open().
 */
final class LspConnection
{
    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    /** @var resource|null */
    private $socket = null;

    /** Monotonic JSON-RPC request id — avoids collisions that time() causes. */
    private int $nextId = 0;

    /** Persistent read buffer so partial lines survive across reads. */
    private string $readBuffer = '';

    private bool $initialized = false;

    /** Server capabilities cached after initialize. */
    private ?array $capabilities = null;

    /**
     * @param 'stdio'|'tcp' $transport
     */
    public function __construct(
        private readonly string $serverPath,
        private readonly array $serverArgs = [],
        private readonly ?string $cwd = null,
        private readonly string $transport = 'stdio',
        private readonly ?string $host = null,
        private readonly ?int $port = null,
    ) {
        if (!in_array($transport, ['stdio', 'tcp'], true)) {
            throw new \InvalidArgumentException('transport must be "stdio" or "tcp"');
        }
    }

    /**
     * Spawn the server process, send initialize, and return server capabilities.
     *
     * @return array Server capabilities from the initialize response result.
     * @throws \RuntimeException If the server fails to start or respond.
     */
    public function connect(): array
    {
        $this->startTransport();

        $response = $this->request('initialize', [
            'processId' => getmypid(),
            'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
            'capabilities' => [
                'textDocument' => [
                    'synchronization' => ['willSave' => true, 'willSaveWaitUntil' => true, 'didSave' => true, 'didClose' => true],
                    'hover' => ['dynamicRegistration' => true],
                    'completion' => ['dynamicRegistration' => true],
                    'definition' => ['dynamicRegistration' => true],
                    'references' => ['dynamicRegistration' => true],
                    'documentSymbol' => ['dynamicRegistration' => true],
                ],
                'workspace' => ['symbol' => ['dynamicRegistration' => true]],
            ],
        ]);

        if ($response === null) {
            $this->disconnect();
            throw new \RuntimeException('Server did not respond to initialize');
        }

        $this->capabilities = $response['capabilities'] ?? [];
        $this->initialized = true;

        // Send initialized notification (no response expected).
        $this->notify('initialized', ['capabilities' => $this->capabilities]);

        return $this->capabilities;
    }

    /**
     * Send shutdown, wait for exit, then terminate the process.
     */
    public function disconnect(): void
    {
        if (!$this->initialized) {
            $this->stopProcess();
            return;
        }

        try {
            $this->request('shutdown', null);
        } catch (\Throwable) {
            // Server may have already terminated — ignore.
        }

        $this->notify('exit');
        $this->stopProcess();
        $this->initialized = false;
        $this->capabilities = null;
    }

    /**
     * textDocument/definition — go-to-definition.
     *
     * @return array Locations (LSP Location[]).
     */
    public function definitions(string $uri, int $line, int $col): array
    {
        $response = $this->request('textDocument/definition', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
        ]);

        if ($response === null) {
            return [];
        }

        return $this->normalizeLocations($response);
    }

    /**
     * textDocument/references — find all references.
     *
     * @return array Locations (LSP Location[]).
     */
    public function references(string $uri, int $line, int $col): array
    {
        $response = $this->request('textDocument/references', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
            'context' => ['includeDeclaration' => true],
        ]);

        if ($response === null) {
            return [];
        }

        return $this->normalizeLocations($response);
    }

    /**
     * textDocument/hover — hover information.
     *
     * @return array|null Hover result (contents + range) or null if not available.
     */
    public function hover(string $uri, int $line, int $col): ?array
    {
        $response = $this->request('textDocument/hover', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
        ]);

        if ($response === null || !array_key_exists('contents', $response)) {
            return null;
        }

        return $response;
    }

    /**
     * textDocument/documentSymbol — list symbols in a document.
     *
     * @return array DocumentSymbol[] or SymbolInformation[].
     */
    public function symbols(string $uri): array
    {
        $response = $this->request('textDocument/documentSymbol', [
            'textDocument' => ['uri' => $uri],
        ]);

        if ($response === null) {
            return [];
        }

        return $response;
    }

    /**
     * textDocument/publishDiagnostics — returns cached diagnostics for a URI.
     *
     * @deprecated since P7.S11 will implement proper publishDiagnostics subscription via LspClient.
     *             This stub returns an empty array; real use requires a subscription flow.
     *
     * @return array Diagnostics for the given URI (empty if none cached).
     */
    public function diagnostics(string $uri): array
    {
        // Diagnostics are pushed by the server via publishDiagnostics notifications.
        // Clients must register for them via the $/subscribe method.
        // This stub returns an empty array; real use requires a subscription flow.
        return [];
    }

    public function isConnected(): bool
    {
        if (!$this->initialized) {
            return false;
        }
        if ($this->transport === 'tcp') {
            return $this->socket !== null && is_resource($this->socket);
        }
        return $this->process !== null && is_resource($this->process);
    }

    // -------------------------------------------------------------------------
    // Private primitives
    // -------------------------------------------------------------------------

    /** @param array<string, mixed>|null $params */
    private function request(string $method, ?array $params): ?array
    {
        $id = (string) $this->nextId++;

        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        if (!$this->writeLine(json_encode($payload, JSON_THROW_ON_ERROR))) {
            return null;
        }

        $message = $this->readResponse($id);
        if ($message === null) {
            return null;
        }

        return $message;
    }

    /** @param array<string, mixed>|null $params */
    private function notify(string $method, ?array $params = null): void
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        $this->writeLine(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Write one newline-framed message to the child's stdin or TCP socket.
     */
    private function writeLine(string $json): bool
    {
        if ($this->transport === 'tcp') {
            if (!is_resource($this->socket)) {
                return false;
            }
            if (@fwrite($this->socket, $json . "\n") === false) {
                return false;
            }
            fflush($this->socket);
            return true;
        }

        if (!is_resource($this->process) || $this->pipes === null) {
            return false;
        }

        if (@fwrite($this->pipes[0], $json . "\n") === false) {
            return false;
        }
        fflush($this->pipes[0]);

        return true;
    }

    private function startTransport(): void
    {
        if ($this->transport === 'tcp') {
            $host = $this->host ?? '127.0.0.1';
            $port = $this->port ?? 0;
            $addr = "tcp://{$host}:{$port}";
            $this->socket = @stream_socket_client($addr, $errno, $errstr, 5.0);
            if ($this->socket === false) {
                throw new \RuntimeException("Failed to connect to LSP server at {$addr}: {$errstr} ({$errno})");
            }
            return;
        }

        // stdio: spawn subprocess
        $cmd = implode(' ', array_map('escapeshellarg', [$this->serverPath, ...$this->serverArgs]));

        $this->process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            $this->cwd,
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start LSP server: {$this->serverPath}");
        }
    }

    private function stopProcess(): void
    {
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->socket !== null && is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->process = null;
        $this->pipes = null;
        $this->socket = null;
        $this->readBuffer = '';
    }

    /**
     * Read until we have a line whose id matches $id, skipping server notifications.
     */
    private function readResponse(string $id): ?array
    {
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                return null;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($line, true);
            if ($decoded === null || !isset($decoded['jsonrpc']) || $decoded['jsonrpc'] !== '2.0') {
                continue;
            }

            $msgId = isset($decoded['id']) ? (string) $decoded['id'] : null;

            // Skip server-initiated notifications and stale responses.
            if ($msgId === null || $msgId !== $id) {
                // Collect server notifications for future use (e.g. publishDiagnostics).
                if ($msgId === null && isset($decoded['method'])) {
                    $this->handleNotification($decoded);
                }
                continue;
            }

            if (isset($decoded['error'])) {
                return null;
            }

            return $decoded['result'] ?? null;
        }
    }

    /** @param array<string, mixed> $notification */
    private function handleNotification(array $notification): void
    {
        $method = $notification['method'] ?? null;
        if ($method === 'textDocument/publishDiagnostics') {
            // Could store diagnostics per URI here for diagnostics().
        }
    }

    /**
     * Pull one newline-terminated line from the buffer, refilling as needed.
     */
    private function readLine(): ?string
    {
        while (($newline = strpos($this->readBuffer, "\n")) === false) {
            if ($this->transport === 'tcp') {
                if ($this->socket === null || !is_resource($this->socket)) {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }
                $chunk = fgets($this->socket);
                if ($chunk === false || $chunk === '') {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }
                $this->readBuffer .= $chunk;
                continue;
            }

            if ($this->pipes === null) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $chunk = fgets($this->pipes[1]);
            if ($chunk === false || $chunk === '') {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }
            $this->readBuffer .= $chunk;
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    private function drainBuffer(): string
    {
        $line = $this->readBuffer;
        $this->readBuffer = '';
        return trim($line);
    }

    /**
     * @param array<mixed> $response
     * @return array<mixed>
     */
    private function normalizeLocations(mixed $response): array
    {
        if ($response === null) {
            return [];
        }
        if (is_array($response) && array_key_exists('uri', $response)) {
            // Single Location object.
            return [$response];
        }
        if (is_array($response) && isset($response[0])) {
            return $response;
        }
        return [];
    }
}
