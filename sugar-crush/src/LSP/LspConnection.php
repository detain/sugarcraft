<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * LSP client connection over stdio using JSON-RPC 2.0.
 *
 * Spawns a language server process and communicates with it via the
 * Language Server Protocol over stdio. Handles request/response routing
 * by matching message IDs, supports multiple in-flight requests, and
 * properly cleans up the process on disconnect.
 *
 * Mirrors sugar-crush design.
 */
final class LspConnection
{
    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    /** Monotonic JSON-RPC request id — avoids collisions that time() causes. */
    private int $nextId = 0;

    /** Persistent read buffer so partial messages survive across reads. */
    private string $readBuffer = '';

    /** Whether we have completed the LSP initialization handshake. */
    private bool $initialized = false;

    /** Server capabilities cached after initialize. */
    private ?array $capabilities = null;

    /** Callback for server-initiated notifications. */
    private mixed $notificationCallback = null;

    /** Default request timeout in seconds. */
    private float $requestTimeout = 30.0;

    public function __construct(
        private readonly string $serverPath,
        private readonly array $serverArgs = [],
    ) {}

    /**
     * Start the LSP server process with the given command and environment.
     *
     * @param string $command The server executable path
     * @param array<string, string> $env Environment variables to pass to the server
     * @param string|null $cwd Working directory for the subprocess (null = inherited)
     * @param float $timeout Request timeout in seconds
     */
    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
    {
        $this->requestTimeout = $timeout;

        $this->process = proc_open(
            [$command, ...array_values($this->serverArgs)],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            $cwd,
            $env,
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start LSP server: {$command}");
        }

        // Mark as initialized so isConnected returns true.
        // Caller is responsible for calling initialize() to complete LSP handshake.
        $this->initialized = true;
    }

    /**
     * Complete the LSP initialization handshake and return server capabilities.
     *
     * @return array Server capabilities from the initialize response result.
     * @throws \RuntimeException If the server fails to start or respond.
     * @throws LspResponseException If the server returns a JSON-RPC error.
     */
    public function initialize(): array
    {
        if (!$this->initialized || !is_resource($this->process)) {
            throw new \RuntimeException('Not connected to LSP server');
        }

        $response = $this->sendRequest('initialize', [
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

        if ($response->isTimeout()) {
            $this->disconnect();
            throw new \RuntimeException('Server did not respond to initialize');
        }

        if ($response->isError) {
            $this->disconnect();
            throw new LspResponseException($response->errorMessage ?? 'Server returned error on initialize');
        }

        $this->capabilities = $response->result['capabilities'] ?? [];

        // Send initialized notification (no response expected).
        $this->sendNotification('initialized', []);

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

        if (is_resource($this->process)) {
            try {
                $this->sendRequest('shutdown', null);
            } catch (\Throwable) {
                // Server may have already terminated — ignore.
            }

            $this->sendNotification('exit', null);
        }

        $this->stopProcess();
        $this->initialized = false;
        $this->capabilities = null;
    }

    /**
     * Send a JSON-RPC request and wait for the response.
     *
     * @param string $method The LSP method name
     * @param array<string, mixed>|null $params The method parameters
     * @return LspResponse Wrapped response — use $response->isError to check for errors
     */
    public function sendRequest(string $method, ?array $params): LspResponse
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return LspResponse::ioError('Not connected to LSP server');
        }

        $id = (string) $this->nextId++;
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        if (!$this->writeMessage($payload)) {
            return LspResponse::ioError('Failed to write message');
        }

        $deadline = microtime(true) + $this->requestTimeout;

        return $this->readResponse($id, $deadline);
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     *
     * @param string $method The LSP method name
     * @param array<string, mixed>|null $params The method parameters
     */
    public function sendNotification(string $method, ?array $params): void
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return;
        }

        $payload = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        $this->writeMessage($payload);
    }

    /**
     * Register a callback for server-initiated notifications.
     *
     * @param callable $callback Called with (method: string, params: array|null) when server sends a notification
     */
    public function onNotification(callable $callback): void
    {
        $this->notificationCallback = $callback;
    }

    public function isConnected(): bool
    {
        if (!$this->initialized) {
            return false;
        }

        return $this->process !== null && is_resource($this->process);
    }

    /**
     * Get cached server capabilities.
     *
     * @return array|null Server capabilities or null if not yet initialized
     */
    public function capabilities(): ?array
    {
        return $this->capabilities;
    }

    // -------------------------------------------------------------------------
    // Domain convenience methods
    // -------------------------------------------------------------------------

    /**
     * textDocument/definition — go-to-definition.
     *
     * @return array<mixed> Location[]
     */
    public function definitions(string $uri, int $line, int $col): array
    {
        $response = $this->sendRequest('textDocument/definition', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
        ]);

        if ($response->isError) {
            return [];
        }

        return $response->result ?? [];
    }

    /**
     * textDocument/references — find all references.
     *
     * @return array<mixed> Location[]
     */
    public function references(string $uri, int $line, int $col): array
    {
        $response = $this->sendRequest('textDocument/references', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
            'context' => ['includeDeclaration' => true],
        ]);

        if ($response->isError) {
            return [];
        }

        return $response->result ?? [];
    }

    /**
     * textDocument/hover — hover information.
     *
     * @return array|null Hover result or null if not available
     */
    public function hover(string $uri, int $line, int $col): ?array
    {
        $response = $this->sendRequest('textDocument/hover', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
        ]);

        if ($response->isError) {
            return null;
        }

        if ($response->result === null || $response->result === []) {
            return null;
        }

        return $response->result;
    }

    /**
     * textDocument/documentSymbol — list symbols in a document.
     *
     * @return array<mixed> DocumentSymbol[] or SymbolInformation[]
     */
    public function symbols(string $uri): array
    {
        $response = $this->sendRequest('textDocument/documentSymbol', [
            'textDocument' => ['uri' => $uri],
        ]);

        if ($response->isError) {
            return [];
        }

        return $response->result ?? [];
    }

    /**
     * textDocument/codeAction — get code actions (quick fixes, refactorings, etc.).
     *
     * @return array<mixed> CodeAction[]
     */
    public function codeActions(string $uri, int $line, int $col, array $context = []): array
    {
        $response = $this->sendRequest('textDocument/codeAction', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $col],
            'context' => $context ?: ['diagnostics' => []],
        ]);

        if ($response->isError) {
            return [];
        }

        return $response->result ?? [];
    }

    /**
     * textDocument/diagnostics — pull current diagnostics for a document.
     *
     * Note: Diagnostics are primarily delivered via publishDiagnostics notifications.
     * This method sends a textDocument/diagnostic request if the server supports it.
     *
     * @return array<mixed> Diagnostic[]
     */
    public function diagnostics(string $uri): array
    {
        $response = $this->sendRequest('textDocument/diagnostic', [
            'textDocument' => ['uri' => $uri],
        ]);

        if ($response->isError) {
            return [];
        }

        return $response->result['items'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Private primitives
    // -------------------------------------------------------------------------

    /**
     * Write a message with LSP Content-Length header framing.
     *
     * @param array<string, mixed> $payload
     */
    private function writeMessage(array $payload): bool
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return false;
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $contentLength = strlen($json);

        $header = "Content-Length: {$contentLength}\r\n\r\n";
        $message = $header . $json;

        if (@fwrite($this->pipes[0], $message) === false) {
            return false;
        }
        fflush($this->pipes[0]);

        return true;
    }

    private function stopProcess(): void
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
     * Read until we have a response whose id matches $id, skipping server notifications.
     *
     * @return LspResponse Wrapped response
     */
    private function readResponse(string $id, float $deadline): LspResponse
    {
        while (microtime(true) < $deadline) {
            $message = $this->readMessage();
            if ($message === null) {
                // No complete message available yet, check if process is still alive.
                if (!is_resource($this->process)) {
                    return LspResponse::ioError('Process ended while reading response');
                }
                // Brief sleep to avoid busy-waiting.
                usleep(10000); // 10ms
                continue;
            }

            $msgId = isset($message['id']) ? (string) $message['id'] : null;

            // Server-initiated notification.
            if ($msgId === null && isset($message['method'])) {
                $this->handleNotification($message['method'], $message['params'] ?? null);
                continue;
            }

            // Not our response — skip it.
            if ($msgId === null || $msgId !== $id) {
                continue;
            }

            if (isset($message['error'])) {
                return LspResponse::error($message['error']);
            }

            return LspResponse::ok($message['result'] ?? null);
        }

        // Timeout reached.
        return LspResponse::timeout();
    }

    /**
     * Read one LSP message (header + body) from the server.
     *
     * @return array<string, mixed>|null Decoded message or null if no complete message
     * @throws LspProtocolException When Content-Length header is missing
     */
    private function readMessage(): ?array
    {
        // Read headers byte-by-byte until we find the header-body separator \r\n\r\n.
        // fgets() is line-oriented and would consume the JSON body if it has no newline,
        // so we use fread() with a small chunk size to read precisely.
        while (strpos($this->readBuffer, "\r\n\r\n") === false) {
            if ($this->pipes === null) {
                return null;
            }
            $chunk = fread($this->pipes[1], 1024);
            if ($chunk === false || $chunk === '') {
                if ($this->readBuffer === '') {
                    return null;
                }
                return $this->parseMessage($this->drainBuffer());
            }
            $this->readBuffer .= $chunk;
        }

        // Parse headers.
        $newline = strpos($this->readBuffer, "\r\n\r\n");
        $headerBlock = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 4);

        $contentLength = null;
        foreach (explode("\r\n", $headerBlock) as $header) {
            if (str_starts_with($header, 'Content-Length:')) {
                $contentLength = (int) trim(substr($header, 15));
                break;
            }
        }

        if ($contentLength === null) {
            throw new LspProtocolException(
                'Missing Content-Length header in LSP message: ' . substr($this->readBuffer, 0, 200)
            );
        }

        // Read body until we have contentLength bytes.
        // Use fread() (not fgets()) because the JSON body may span multiple lines.
        // If readBuffer already has enough bytes (fgets consumed body during header phase),
        // skip the read loop.
        if (strlen($this->readBuffer) >= $contentLength) {
            $body = substr($this->readBuffer, 0, $contentLength);
            $this->readBuffer = substr($this->readBuffer, $contentLength);
            return $this->parseMessage($body);
        }

        while (strlen($this->readBuffer) < $contentLength) {
            if ($this->pipes === null) {
                return null;
            }
            $remaining = $contentLength - strlen($this->readBuffer);
            $chunk = fread($this->pipes[1], $remaining);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->readBuffer .= $chunk;
        }

        $body = substr($this->readBuffer, 0, $contentLength);
        $this->readBuffer = substr($this->readBuffer, $contentLength);

        return $this->parseMessage($body);
    }

    /**
     * Parse a JSON-RPC message from a string.
     *
     * @param string|null $data Raw JSON string
     * @return array<string, mixed>|null Decoded message or null on parse error
     */
    private function parseMessage(?string $data): ?array
    {
        if ($data === null || $data === '') {
            return null;
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['jsonrpc']) || $decoded['jsonrpc'] !== '2.0') {
            return null;
        }

        return $decoded;
    }

    /** Consume and return the entire pending buffer as one trimmed line. */
    private function drainBuffer(): string
    {
        $line = $this->readBuffer;
        $this->readBuffer = '';

        return trim($line);
    }

    /** @param array<string, mixed>|null $params */
    private function handleNotification(string $method, ?array $params): void
    {
        if ($this->notificationCallback !== null) {
            ($this->notificationCallback)($method, $params);
        }
    }
}
