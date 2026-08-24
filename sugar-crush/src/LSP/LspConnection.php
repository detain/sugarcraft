<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

use SugarCraft\Crush\Support\ProcessReaper;

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
final class LspConnection implements LspConnectionInterface
{
    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    /** Monotonic JSON-RPC request id — avoids collisions that time() causes. */
    private int $nextId = 0;

    /** Persistent read buffer so partial messages survive across reads. */
    private string $readBuffer = '';

    /**
     * Content-Length of a message whose HEADER has been consumed but whose body
     * has not yet fully arrived, or null when the next thing expected is a
     * header.
     *
     * THIS FIELD IS WHAT MAKES A NON-BLOCKING READ SAFE. {@see readMessage()}
     * strips the header block off {@see $readBuffer} before it reads the body,
     * so a body that arrives in pieces used to lose its length on the way out:
     * the method returned null, and the NEXT call went looking for `\r\n\r\n`
     * in a buffer that now held a raw JSON fragment. On a blocking pipe that was
     * latent, because the body `fread()` almost always completed in one pass.
     * Non-blocking makes a short read the normal case, so the framing state has
     * to outlive the call.
     */
    private ?int $pendingContentLength = null;

    /** Whether we have completed the LSP initialization handshake. */
    private bool $initialized = false;

    /** Server capabilities cached after initialize. */
    private ?array $capabilities = null;

    /** Callback for server-initiated notifications. */
    private mixed $notificationCallback = null;

    /** Default request timeout in seconds. */
    private float $requestTimeout = 30.0;

    /**
     * How much of the server's stderr is kept for diagnostics.
     *
     * 64 KiB is one pipe buffer on this host, which is the natural unit: the
     * point at which an undrained fd 2 stops the server dead (see
     * {@see drainStderr()}). Keeping one buffer's worth means a wedge that did
     * happen is fully explained by what was retained.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * The TAIL of whatever the server has written to stderr.
     *
     * Bounded because {@see drainStderr()} runs inside an unbounded poll and a
     * server in a warning loop would otherwise be an unbounded allocation. The
     * TAIL rather than the head because this text answers "why did it stop",
     * and the reason a process gives is the last thing it says.
     */
    private string $stderrTail = '';

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

        $this->process = @proc_open(
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

        // NON-BLOCKING STDOUT, which is what makes $timeout mean anything.
        // {@see readResponse()} bounds itself with a `microtime()` deadline
        // around {@see readMessage()}, and that bound was DEAD CODE while this
        // pipe was blocking: the loop never got back to its own condition
        // because the first `fread()` inside `readMessage()` sat in the kernel
        // until the server wrote or exited. MEASURED on this host (PHP 8.3.6)
        // against a fixture that answers nothing and lives 20s, a `connect()`
        // with $timeout = 2.0 took **20.02s** to return from `disconnect()`.
        //
        // `stream_set_timeout()` is the obvious instrument and does NOT work
        // here — it is documented and measured on
        // {@see \SugarCraft\Crush\MCP\StdioMcpServer::start()} that it returns
        // false for a `proc_open()` pipe, which is an STDIO stream and not a
        // socket. Non-blocking plus the caller's poll is the shape that works.
        stream_set_blocking($this->pipes[1], false);

        // NON-BLOCKING STDERR TOO, and it is DRAINED — see drainStderr(). fd 2
        // is a pipe nothing here ever read, and a pipe nobody reads stops the
        // writer at one buffer.
        stream_set_blocking($this->pipes[2], false);

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

    /**
     * Bring the language server down in BOUNDED time, and make sure it is DEAD.
     *
     * WHAT THIS USED TO BE: `proc_terminate()` immediately followed by
     * `proc_close()`. E366 asked which of the two failure modes that produces —
     * an orphan, or an indefinite block — and the answer is MEASURED, not
     * inferred. On this host (PHP 8.3.6, Linux 6.8), against a direct child that
     * installs a no-op SIGTERM handler and then loops for eight seconds, the
     * pair returned after **7.77s** with the child dead. So: it BLOCKS. It does
     * not orphan, because `proc_close()` waits — it hands the caller's deadline
     * to a language server, which is by definition somebody else's code and is
     * routinely a large one. `gopls`, `rust-analyzer` and `jdtls` all do real
     * work on shutdown.
     *
     * THE OTHER HALF OF THE SAME FINDING is {@see __destruct()}, and it is the
     * opposite failure: this class had none, and MEASURED with the same
     * instrument, dropping a `proc_open()` handle whose child is still RUNNING
     * takes **0.000s** and leaves the child in state `S`. The resource
     * destructor reaps an already-exited child (a zombie goes to `GONE`
     * instantly) but never WAITS for a live one — it abandons it. So an
     * `LspConnection` that simply went out of scope left a language server
     * running, reparented to pid 1, holding every descriptor above 2 that this
     * process had open when it spawned. That is E366's shape exactly, and it is
     * why the destructor below exists.
     *
     * Now: SIGTERM, a bounded poll, signal 9, then `proc_close()` — the one
     * ladder in {@see \SugarCraft\Crush\Support\ProcessReaper}, shared with
     * {@see \SugarCraft\Crush\ClaudeCodeMcpClient::disconnect()} rather than
     * spelled again here.
     *
     * THE SIGNAL REACHES THE SERVER because {@see connect()} passes
     * `proc_open()` an ARGV. Under a shell STRING, `/bin/sh` on this host is
     * dash, which does NOT apply the `-c` exec optimisation — MEASURED, the
     * direct child's `comm` is `(sh)` and the program is a GRANDCHILD, so the
     * escalation would land on a wrapper. What remains outside this method's
     * reach is a server that spawns children OF ITS OWN; those are not in this
     * process's control group and are a property of the server's own process
     * handling.
     */
    private function stopProcess(): void
    {
        // ONE LAST DRAIN, BEFORE THE SIGNAL. A server blocked in write(2) on a
        // full stderr pipe cannot run its own SIGTERM handler to shut down
        // cleanly, so it would take the ladder's escalation to signal 9 every
        // time. Emptying the pipe first gives it the chance to exit on the
        // polite signal — and leaves {@see stderrTail()} holding whatever it
        // said on the way out.
        $this->drainStderr();

        ProcessReaper::terminateAndClose($this->process);

        $this->process = null;
        $this->pipes = null;
        $this->drainBuffer();
        $this->pendingContentLength = null;
    }

    /**
     * DELIBERATELY `stopProcess()` AND NOT `disconnect()`.
     *
     * `disconnect()` speaks the LSP shutdown protocol: a `shutdown` REQUEST,
     * which blocks in {@see readResponse()} for up to `$this->requestTimeout`
     * (30s by default), then an `exit` notification. That is the right sequence
     * when a caller chooses to close the connection, and the wrong thing
     * entirely in a destructor — it would put a 30s protocol round trip at
     * whatever arbitrary point the last reference is dropped, including during
     * PHP's shutdown sequence, to a server that may already be gone.
     *
     * The destructor's job is narrower and unconditional: do not leak the
     * process. A caller that wants the graceful handshake calls
     * {@see disconnect()} itself, and this then finds nothing to do — the
     * ladder is a no-op on a null handle.
     */
    public function __destruct()
    {
        $this->stopProcess();
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
        if ($this->pipes === null) {
            return null;
        }

        // HEADER PHASE. Skipped entirely when a previous call already consumed a
        // header and is still waiting on its body — see {@see $pendingContentLength}.
        if ($this->pendingContentLength === null) {
            while (($separator = strpos($this->readBuffer, "\r\n\r\n")) === false) {
                if (!$this->refill()) {
                    // Nothing more available RIGHT NOW. Return null and keep the
                    // partial header buffered; readResponse() sleeps and retries
                    // until its deadline. The old code drained the buffer here
                    // and handed the fragment to parseMessage(), which discarded
                    // a header that had merely not finished arriving.
                    return null;
                }
            }

            $headerBlock = substr($this->readBuffer, 0, $separator);
            $this->readBuffer = substr($this->readBuffer, $separator + 4);

            $contentLength = null;
            foreach (explode("\r\n", $headerBlock) as $header) {
                if (str_starts_with($header, 'Content-Length:')) {
                    $contentLength = (int) trim(substr($header, 15));
                    break;
                }
            }

            if ($contentLength === null) {
                throw new LspProtocolException(
                    'Missing Content-Length header in LSP message: ' . substr($headerBlock, 0, 200)
                );
            }

            $this->pendingContentLength = $contentLength;
        }

        // BODY PHASE.
        while (strlen($this->readBuffer) < $this->pendingContentLength) {
            if (!$this->refill()) {
                return null;
            }
        }

        $body = substr($this->readBuffer, 0, $this->pendingContentLength);
        $this->readBuffer = substr($this->readBuffer, $this->pendingContentLength);
        $this->pendingContentLength = null;

        return $this->parseMessage($body);
    }

    /**
     * Pull whatever is available from the server's stdout into
     * {@see $readBuffer}; false when nothing was.
     *
     * On a NON-BLOCKING pipe an empty string means "not yet", not "never" — it
     * is the same answer at EOF, and the two are deliberately not distinguished
     * here: {@see readResponse()} checks the process handle on every pass and
     * gives up at its deadline either way, so a finer distinction would have no
     * reader.
     */
    private function refill(): bool
    {
        if ($this->pipes === null) {
            return false;
        }

        // BEFORE stdout, every pass. See drainStderr() for why this is not
        // optional bookkeeping.
        $this->drainStderr();

        // Same TypeError exposure as drainStderr() — see the note there. This
        // read was reachable with a closed pipe before that method existed;
        // nothing had exercised it, which is not the same as it being safe.
        if (!is_resource($this->pipes[1])) {
            return false;
        }

        $chunk = @fread($this->pipes[1], 8192);
        if ($chunk === false || $chunk === '') {
            return false;
        }

        $this->readBuffer .= $chunk;

        return true;
    }

    /**
     * Take whatever the server has written to stderr and keep the tail.
     *
     * ⚠️ THIS IS NOT DIAGNOSTICS PLUMBING; IT IS WHAT STOPS THE SERVER WEDGING.
     * {@see connect()} gives the child fd 2 as a `['pipe', 'w']`, and until this
     * existed nothing in this class ever read it. A pipe whose reader never
     * reads holds at most one kernel buffer, after which the WRITER blocks in
     * `write(2)` — so a server that logged more than that never got to write
     * its next response, and could not exit either.
     *
     * MEASURED on this host (PHP 8.3.6, Linux 6.8, 64 KiB pipe buffer) with a
     * child that writes N bytes to stderr and then a well-formed
     * `Content-Length` header to stdout, using this exact three-pipe descriptor
     * spec, fd 1 non-blocking, fd 2 never read, 5.0s deadline / 5ms poll —
     * three consecutive takes, identical: N = 1000 and N = 60000 both deliver
     * the header in 0.04s; N = 100000 never delivers it at all.
     *
     * THE SYMPTOM IS NOT A HANG HERE, WHICH IS WHY IT SURVIVED. Every read path
     * in this class is deadline-bounded, so the CALLER returns on time with an
     * empty answer while the SERVER is permanently stuck — and
     * {@see isConnected()} goes on reporting true, because the process is alive
     * and the handle is a resource. A `rust-analyzer`/`gopls`/`jdtls` log storm
     * is an ordinary amount of stderr, not a pathological one.
     */
    private function drainStderr(): void
    {
        if ($this->pipes === null) {
            return;
        }

        // `is_resource()`, NOT `@`. A pipe belonging to a process that has been
        // `proc_close()`d is a CLOSED resource, and `fread()` on one raises a
        // TypeError — which `@` does NOT suppress, because it is an exception
        // and not a diagnostic. That is the same mistake E367 found one file
        // over, where an `@stream_get_contents()` on an fclose'd pipe meant the
        // RuntimeException it was building was never constructed at all. Found
        // here by `LspConnectionTest::testProcessDiesMidRead`, which kills the
        // server and then reads.
        if (!is_resource($this->pipes[2])) {
            return;
        }

        // SET HERE TOO, NOT ONLY IN connect() — see the identical note on
        // ClaudeCodeMcpClient::drainStderr(). `fread()` on a BLOCKING pipe
        // waits for a child that may have nothing more to say, so without this
        // the method's correctness depends on a line in another method, and
        // losing that line turns a test failure into a hung CI job.
        stream_set_blocking($this->pipes[2], false);

        // Bounded per pass rather than "until EOF": a server writing faster
        // than this loop reads must not be able to hold the poll here forever.
        for ($i = 0; $i < 16; $i++) {
            $chunk = @fread($this->pipes[2], 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $this->stderrTail = substr($this->stderrTail . $chunk, -self::MAX_STDERR_BYTES);
        }
    }

    /**
     * The tail of the server's stderr, for a caller trying to explain a
     * connection that went quiet. Empty when the server has said nothing.
     */
    public function stderrTail(): string
    {
        return $this->stderrTail;
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

    /**
     * Consume and return the entire pending buffer as one trimmed line.
     *
     * WHAT THIS USED TO BE FOR: {@see readMessage()}'s header loop called it
     * when a `fread()` came back empty with bytes already buffered, handing the
     * fragment to {@see parseMessage()} as though it were a whole message.
     *
     * WHAT IS TRUE NOW: on a NON-BLOCKING pipe an empty read means "not yet",
     * not "never", so treating a partial header as a complete message would
     * discard messages that had simply not finished arriving. `readMessage()`
     * keeps the partial buffered instead and returns null, and this method has
     * no caller on the read path any more.
     *
     * WHY IT STILL EARNS ITS PLACE: consuming whatever partial remains is
     * exactly what teardown wants, and {@see stopProcess()} now calls it for
     * that — a connection must not carry half a message from a dead server into
     * whatever reads {@see $readBuffer} next. It is one method with one meaning
     * rather than a `$this->readBuffer = ''` open-coded beside it.
     */
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
