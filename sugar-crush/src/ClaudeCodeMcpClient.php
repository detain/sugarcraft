<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use RuntimeException;

/**
 * MCP client that connects to Claude Code via stdio transport.
 * Sends JSON-RPC 2.0 messages and receives responses using
 * non-blocking I/O so the TUI loop stays responsive.
 *
 * Mirrors the MCP spec stdio transport:
 * https://modelcontextprotocol.io/specification/basic/transports/
 *
 * A DORMANT SEAM, and named so you can tell which one you are reading. This
 * class was `SugarCraft\Crush\McpClient` and shared its basename with
 * {@see \SugarCraft\Crush\MCP\McpClient} — a different class in a
 * different namespace over a different transport: Guzzle HTTP (plus stdio and
 * git server shapes) against servers named in an injected JSON config path,
 * with per-agent-preset allowlists enforced through
 * {@see \SugarCraft\Crush\MCP\McpRouter}. The two never collided under
 * PSR-4 and neither was broken by the sharing; their two TEST files DID share
 * a basename, and readers of this tree have more than once attributed one
 * file's behaviour to the other over it. That is the whole of what the rename
 * fixes, and no call site moved, because there are none.
 *
 * DORMANT IS NOT UNGATED, and dormant is also not deleted. THIS PARAGRAPH USED
 * TO SAY "NEITHER client is constructed by a real run", over a measurement that
 * `grep -rn McpClient src/ bin/ examples/` reported exactly one line — a
 * doc-comment in `src/Providers/Concerns/HttpClientDefaults.php` comparing
 * timeouts, not a construction. THAT IS NO LONGER TRUE OF THE SIBLING, and the
 * half that changed is precisely the half a reader of this file would carry
 * away: crush_code.md Phase 2 item 2 gave {@see \SugarCraft\Crush\MCP\McpClient}
 * a real caller in {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}, which
 * reads `$root/.mcp.json` behind a {@see \SugarCraft\Crush\Support\ContainedPath}
 * compare AND a per-user trust grant (`trustedProjectMcp`, because starting a
 * server is code execution from cloned content), and exposes each discovered tool
 * through {@see \SugarCraft\Crush\Tools\McpToolBridge} — whose CALLS are gated by
 * the PreToolUse chain like every other tool. So "the other one is the live one"
 * IS now a thing that
 * can be said, and it is said about the sibling; THIS class is still constructed
 * by nothing but its own test. Reading the sentence that used to be here as
 * covering both is exactly the basename confusion the rename existed to end.
 *
 * The dormancy test for this class
 * ({@see \SugarCraft\Crush\Tests\ClaudeCodeMcpClientTest::testNothingInSrcBinOrExamplesReachesThisDormantSeam()})
 * separates comments from code with `token_get_all()` rather than grepping
 * bytes: a doc-comment mention reaches nothing, and reporting one as a call site
 * would be this file's own basename confusion in a new costume. The only caller
 * of this class today is
 * {@see \SugarCraft\Crush\Tests\ClaudeCodeMcpClientTest}. It spawns
 * `$command` (default `claude --mcp`) through `proc_open` with no
 * {@see \SugarCraft\Crush\Support\ContainedPath} anchor and no
 * {@see \SugarCraft\Crush\Permissions\PermissionGate} in front of the tool
 * calls it forwards, which is survivable ONLY while it stays unreachable from
 * a run. Wiring it up (crush_code.md Phase 2 item 2, a separate change) has to
 * bring those two gates with it; reaching for it before then is how a
 * process-spawning seam goes live ungated.
 */
final class ClaudeCodeMcpClient
{
    private const READ_CHUNK_SIZE = 8192;

    /** @param array<string, mixed>|null $initialOptions */
    public function __construct(
        public readonly ?string $command = null,
        public readonly array $args = [],
        public readonly ?array $initialOptions = null,
        private mixed $process = null,
        /** @var array<int, resource>|null */
        private ?array $pipes = null,
        private bool $connected = false,
        private int $requestId = 0,
    ) {}

    /**
     * Start the Claude Code MCP process and perform handshake.
     *
     * @param array<string, mixed>|null $options capability options to send in handshake
     * @return list<McpMessage> any handshake messages received during init
     */
    public function connect(?array $options = null): array
    {
        if ($this->connected) {
            return [];
        }

        $command = $this->command ?? 'claude';
        $args = $this->args;

        // Validate the binary up-front. Calling proc_open() with a missing
        // command emits a PHP warning before returning false; under
        // PHPUnit's failOnWarning="true" the test would fail even though
        // we throw a RuntimeException right after. Resolving the binary
        // ourselves means proc_open() only runs against a real executable
        // and never has reason to warn.
        if (self::resolveExecutable($command) === null) {
            throw new RuntimeException("Failed to spawn MCP process: {$command}");
        }

        // stdio transport — Claude Code MCP speaks JSON-RPC over stdin/stdout.
        /** @var array{0: resource, 1: resource, 2: resource} */
        $processHandles = proc_open(
            array_merge([$command], $args),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($processHandles)) {
            throw new RuntimeException("Failed to spawn MCP process: {$command}");
        }

        $this->process = $processHandles;
        $this->pipes = $pipes;
        $this->connected = true;

        // Set non-blocking mode on stdout so we can read without blocking the TUI
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[0], false);

        // Send initialize handshake notification
        /** @var array<string, mixed> $handshakeOptions */
        $handshakeOptions = $this->initialOptions ?? [];
        $handshakeOptions['protocolVersion'] = '2024-11-05';
        $handshakeOptions['capabilities'] = ['tools' => true, 'resources' => null];

        $initMsg = McpMessage::notification('initialize', $handshakeOptions);
        $this->sendMessage($initMsg);

        // Read initial responses (may include server info, capabilities, error)
        return $this->readMessages();
    }

    /**
     * Send a JSON-RPC request and wait for a response.
     *
     * @param array<string, mixed>|null $params
     * @return McpMessage the response message
     * @throws RuntimeException if not connected or request fails
     */
    public function callTool(string $name, ?array $params = null): McpMessage
    {
        if (!$this->connected) {
            throw new RuntimeException('MCP client not connected');
        }

        $id = (string) ++$this->requestId;
        $request = McpMessage::request($id, 'tools/call', ['name' => $name, 'arguments' => $params ?? []]);

        $this->sendMessage($request);

        // Read until we get a response with matching id
        $attempts = 0;
        while ($attempts < 100) {
            $messages = $this->readMessages();
            foreach ($messages as $msg) {
                if ($msg->id === $id) {
                    return $msg;
                }
            }
            usleep(10000); // 10ms
            $attempts++;
        }

        throw new RuntimeException("No response received for request {$id}");
    }

    /**
     * List available tools from the MCP server.
     *
     * @return McpMessage response containing tools list
     * @throws RuntimeException if not connected
     */
    public function listTools(): McpMessage
    {
        if (!$this->connected) {
            throw new RuntimeException('MCP client not connected');
        }

        $id = (string) ++$this->requestId;
        $request = McpMessage::request($id, 'tools/list', null);

        $this->sendMessage($request);

        // Read until we get a response with matching id
        $attempts = 0;
        while ($attempts < 100) {
            $messages = $this->readMessages();
            foreach ($messages as $msg) {
                if ($msg->id === $id) {
                    return $msg;
                }
            }
            usleep(10000);
            $attempts++;
        }

        throw new RuntimeException("No response received for tools/list request");
    }

    /**
     * Send a raw message and flush.
     */
    public function sendMessage(McpMessage $message): void
    {
        if (!$this->connected || $this->process === null) {
            throw new RuntimeException('MCP client not connected');
        }

        /** @var array<int, resource> $pipes */
        $pipes = $this->getPipes();
        $json = $message->toJson() . "\n";
        $written = fwrite($pipes[0], $json);

        if ($written === false || $written !== strlen($json)) {
            throw new RuntimeException('Failed to write to MCP process stdin');
        }

        fflush($pipes[0]);
    }

    /**
     * Read any buffered messages from stdout.
     * Uses newline-delimited JSON parsing.
     *
     * @return list<McpMessage>
     */
    public function readMessages(): array
    {
        if (!$this->connected || $this->process === null) {
            return [];
        }

        /** @var array<int, resource> $pipes */
        $pipes = $this->getPipes();
        $messages = [];
        $buffer = '';

        // Read available bytes from stdout
        while (true) {
            $chunk = fread($pipes[1], self::READ_CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;

            // Process complete newline-delimited JSON messages
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $msg = McpMessage::parse($line);
                if ($msg !== null) {
                    $messages[] = $msg;
                }
            }
        }

        return $messages;
    }

    /**
     * Disconnect and clean up the MCP process.
     */
    public function disconnect(): void
    {
        if (!$this->connected || $this->process === null) {
            return;
        }

        $pipes = $this->getPipes();

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($this->process);

        $this->process = null;
        $this->pipes = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @return array<int, resource>
     */
    private function getPipes(): array
    {
        if ($this->pipes === null) {
            throw new RuntimeException('Process not running');
        }
        /** @var array<int, resource> */
        return $this->pipes;
    }

    /**
     * Locate an executable by PATH search (or accept an absolute / relative
     * path as-is). Returns the resolved absolute path, or null if the
     * command can't be found. Used to pre-validate before proc_open() so
     * that a missing binary throws a clean RuntimeException without
     * emitting a PHP warning that would trip PHPUnit's failOnWarning gate.
     */
    private static function resolveExecutable(string $command): ?string
    {
        if ($command === '') {
            return null;
        }
        if (str_contains($command, DIRECTORY_SEPARATOR) || str_contains($command, '/')) {
            return (is_file($command) && is_executable($command)) ? $command : null;
        }
        $pathEnv = getenv('PATH');
        if (!is_string($pathEnv) || $pathEnv === '') {
            return null;
        }
        $sep = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        foreach (explode($sep, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Create a ClaudeCodeMcpClient with default settings for Claude Code.
     *
     * @param array<string, mixed>|null $options capability options to send in handshake
     */
    public static function forClaudeCode(?array $options = null): self
    {
        return new self(
            command: 'claude',
            args: ['--mcp'],
            initialOptions: $options,
        );
    }
}
