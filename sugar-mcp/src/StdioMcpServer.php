<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * An MCP server reached over a child process' stdin/stdout, speaking
 * newline-delimited JSON-RPC 2.0.
 *
 * Extracted from the sugar-crush MCP stack (src/MCP/StdioMcpServer.php); the
 * framing, handshake and lifecycle algorithms are faithful, with two
 * deliberate deltas documented below.
 *
 * DELTA — containment policy stays downstream: sugar-crush launches through
 * ProcessContainment (setsid-wrapped detached spawn, PATH pre-check, curated
 * environment). That is product policy about WHO may be spawned from the TUI,
 * not part of the wire protocol, so this library calls proc_open directly with
 * the plain argv array and inherits the ambient environment unless overrides
 * are given. Embedders that need containment wrap it themselves.
 *
 * DELTA — teardown goes through candy-core's BoundedShutdown instead of the
 * product's ProcessReaper+ProcessContainment::groupId pair.
 *
 * WHY the argv-ARRAY form of proc_open and never a shell string: with a shell
 * the child is /bin/sh and the MCP server is its grandchild; stop() signals
 * the shell and the server survives, orphaned — measured in the product. The
 * array form execs the server as the direct child, so the ladder below can
 * actually reach it.
 *
 * WHY stderr is absorbed during every read and write wait: kernel pipes carry
 * ~64KiB. A chatty server that fills stderr BLOCKS on its own write before it
 * ever answers on stdout — a deadlock that looks exactly like an unresponsive
 * server. Both poll sets therefore include fd 2, and anything ready there is
 * drained (tail-kept, capped at MAX_STDERR_BYTES) before stdout is consulted.
 *
 * WHY the start deadline is ONE wall clock across the whole handshake:
 * a server that streams notifications forever starves a per-read timeout —
 * every individual read arrives in time, so the budget never expires, and the
 * process hangs (product-measured, rc=124). One deadline checked at the top of
 * each leg bounds the exchange as a unit instead.
 *
 * E646 discipline: DEFAULT_START_TIMEOUT_SECONDS bounds the HANDSHAKE only.
 * callTool() carries NO deadline on purpose — a tool call is somebody's real
 * work (a build, a fetch) and minutes are legitimate; it is bounded by child
 * liveness (a dead child fails the write or the read) rather than by a
 * wall-clock kill of in-flight work.
 */
final class StdioMcpServer implements McpServer
{
    /** Protocol version advertised in the initialize handshake. */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Wall-clock ceiling for the whole handshake (initialize → initialized →
     * tools/list). Sized for cold package fetches: an `npx`-style launcher
     * downloading its server takes 30–60s on first run, and failing that boot
     * faster is not failing better. Bounds the handshake ONLY — never a tool
     * call. Embedders with local servers pass a tighter startTimeoutSeconds.
     */
    public const DEFAULT_START_TIMEOUT_SECONDS = 60.0;

    /**
     * Tail budget for captured stderr. The cap exists so a server flooding
     * stderr cannot grow our memory without bound; keeping the TAIL (not the
     * head) is the point — the lines nearest the failure are the ones worth
     * reading.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * Ceiling on a single unread frame. A stream with no newline for 64MiB is
     * not speaking NDJSON no matter what it eventually says, and letting the
     * buffer grow toward it trades a protocol error for memory pressure.
     * The product family pins this same value via EngineBackend::MAX_FRAME_BYTES;
     * the library restates the constant so its ceiling never silently widens
     * or narrows when a sibling changes.
     */
    private const MAX_FRAME_BYTES = 67108864; // 64 * 1024 * 1024

    /** Poll slice when no deadline constrains a wait — the select timeout. */
    private const READ_POLL_SECONDS = 1;

    /**
     * Escape hatch for a stream_select() that keeps failing while the child
     * lives. Signal storms make select return false at thousands of calls per
     * second (product-measured ~2800/s under a 300µs SIGUSR1 storm); without a
     * ceiling the 1ms sleep per pass becomes a livelock. 10000 keeps genuine
     * EINTR tolerance (the loop retries) while bounding worst-case spinning.
     */
    private const MAX_CONSECUTIVE_SELECT_FAILURES = 10000;

    /** Number of bounded non-blocking drains one pumpStderr() pass performs. */
    private const PUMP_STDERR_PASSES = 16;

    /** @var list<McpTool> captured by start(), empty until then */
    private array $tools = [];

    /** @var resource|\ProcOpen|null */
    private $process = null;

    /** @var array<int,resource>|null */
    private ?array $pipes = null;

    /** Monotonic request id source; ids travel as decimal strings. */
    private int $nextId = 0;

    /** Bytes read from stdout but not yet terminated by a newline. */
    private string $readBuffer = '';

    /** Tail-bounded capture of everything the server wrote to stderr. */
    private string $stderrTail = '';

    private bool $stderrOpen = false;

    private float $startTimeoutSeconds;

    /**
     * @param string $name    server identity, tagged onto every McpTool
     * @param string $command program to exec (argv[0])
     * @param list<string> $args argv after the program
     * @param array<string,string> $env extra environment; [] means inherit
     * @param float|null $startTimeoutSeconds handshake ceiling; null or <= 0
     *        falls back to DEFAULT_START_TIMEOUT_SECONDS so a bad setting can
     *        never disable the bound entirely
     */
    public function __construct(
        public readonly string $name,
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
        ?float $startTimeoutSeconds = null,
    ) {
        $this->startTimeoutSeconds = $startTimeoutSeconds !== null && $startTimeoutSeconds > 0
            ? $startTimeoutSeconds
            : self::DEFAULT_START_TIMEOUT_SECONDS;
    }

    /**
     * Orphan-proofing: a server the embedder forgot to stop dies with this
     * object. stop() is idempotent, so an already-stopped instance is a no-op.
     */
    public function __destruct()
    {
        $this->stop();
    }

    public function start(): void
    {
        $this->process = @proc_open(
            [$this->command, ...array_map(static fn (mixed $arg): string => (string) $arg, array_values($this->args))],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            null,
            $this->env === [] ? null : array_merge(getenv(), $this->env),
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start MCP server: {$this->name}");
        }

        // stream_set_timeout does not work on proc_open pipes (measured: it
        // returns false and sets nothing), and blocking reads would ignore any
        // deadline we hold. Non-blocking + stream_select is the bound.
        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->stderrOpen = true;
        $this->stderrTail = '';

        $deadline = microtime(true) + $this->startTimeoutSeconds;

        $response = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'sugar-mcp', 'version' => '0.1.0'],
        ], $deadline);

        // A successful initialize answers with a result object; an error reply
        // is at least a server that speaks our protocol badly. Anything else —
        // silence, garbage, a null-shaped non-response — means we cannot trust
        // a single byte from this process. Diagnostics are read BEFORE stop()
        // because stop() clears the stderr capture.
        if ($response === null || (!$response->resultSet && $response->error === null)) {
            $diagnostics = $this->stderrTailForDiagnostics();
            $this->stop();

            throw new \RuntimeException("Failed to start MCP server: {$this->name}" . $diagnostics);
        }

        $this->notify('initialized', null, $deadline);

        $listResponse = $this->request('tools/list', [], $deadline);
        $this->tools = $listResponse === null ? [] : $this->parseTools($listResponse->toArray());
    }

    public function stop(): void
    {
        if ($this->process !== null && is_resource($this->process)) {
            // Close pipes BEFORE signalling: on stdin EOF a well-behaved server
            // exits on its own, and the ladder below never has to pay for a
            // signal round-trip (measured in the product: 1.05s → 0.010s, and
            // exit status 9 → 0 — graceful, not killed).
            $this->closePipes();

            // groupId must be read while the process is alive; terminateBounded
            // runs TERM → poll → KILL → poll → proc_close and returns the
            // captured exit code (proc_close after a reaped exit reports -1, so
            // the ladder harvests the code from the status poll instead).
            BoundedShutdown::terminateBounded(
                $this->process,
                BoundedShutdown::TERMINATE_GRACE_SECONDS,
                BoundedShutdown::KILL_GRACE_SECONDS,
                BoundedShutdown::groupId($this->process),
            );
        }

        // Idempotent second pass covers the never-started and double-stop paths;
        // closed resources fail is_resource() and are skipped.
        $this->closePipes();

        $this->process = null;
        $this->pipes = null;
        $this->readBuffer = '';
        $this->stderrTail = '';
        $this->stderrOpen = false;
    }

    /**
     * Drain pending stderr without blocking, for callers that idle between
     * exchanges and want diagnostics warm. This is a CALLER-PUMPED seam on
     * purpose: mounting it on an event loop would put two readers on one pipe,
     * and pipe reads are destructive — whichever reader wins eats bytes the
     * other was meant to see (the product's E537 ruling; same law here).
     */
    public function pumpStderr(): void
    {
        if ($this->pipes === null) {
            return;
        }

        // Re-assert non-blocking: nothing here may ever wait on a chatty server.
        stream_set_blocking($this->pipes[2], false);

        for ($pass = 0; $pass < self::PUMP_STDERR_PASSES && $this->stderrOpen; $pass++) {
            $this->absorbStderr();
        }
    }

    /** Is the child process still alive? */
    public function isUp(): bool
    {
        return self::childIsRunning($this->process);
    }

    /** @return list<McpTool> */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * Invoke a tool. No deadline by design (E646): see the class docblock.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function callTool(string $toolName, array $args): array
    {
        $response = $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => $args,
        ]);

        if ($response === null || !$response->resultSet) {
            return ['error' => 'Tool call failed'];
        }

        if (!is_array($response->result)) {
            // A scalar result is not an MCP content envelope; render it into
            // one so callers see a uniform shape. Strings travel verbatim —
            // json_encode would add quotes they never asked for. Everything
            // else travels as its JSON form; false from json_encode is the
            // un-encodable case (INF/NAN) and yields empty text rather than
            // the word "false" standing in for it. NOTE: a `?:` chain here was
            // measured to destroy a legitimate result of `0`.
            $encoded = json_encode($response->result);

            return ['content' => [[
                'type' => 'text',
                'text' => is_string($response->result)
                    ? $response->result
                    : ($encoded === false ? '' : $encoded),
            ]]];
        }

        return $response->result;
    }

    /**
     * One request/response round trip. Public so protocol traffic beyond tools
     * (resources/list, prompts/get, completion) is one call away for embedders
     * without this library growing that surface speculatively — the product
     * implements none of it either.
     *
     * @param array<string,mixed>|null $params
     */
    public function request(string $method, ?array $params = null, ?float $deadline = null): ?McpMessage
    {
        $id = (string) $this->nextId++;
        if (!$this->writeLine(McpMessage::request($id, $method, $params)->toJson(), $deadline)) {
            return null;
        }

        return $this->readResponse($id, $deadline);
    }

    /** Fire-and-forget notification leg of the protocol. */
    public function notify(string $method, ?array $params = null, ?float $deadline = null): void
    {
        $this->writeLine(McpMessage::notification($method, $params)->toJson(), $deadline);
    }

    private function closePipes(): void
    {
        if ($this->pipes === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @param resource|\ProcOpen|null $process */
    private static function childIsRunning($process): bool
    {
        if (!is_resource($process)) {
            return false;
        }

        return (bool) proc_get_status($process)['running'];
    }

    /**
     * Write one wire line (json + "\n") under an optional deadline, absorbing
     * stderr while waiting for write-readiness so a full stderr pipe can never
     * wedge this side.
     */
    private function writeLine(string $json, ?float $deadline = null): bool
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return false;
        }

        if (!is_resource($this->pipes[0])) {
            return false;
        }

        $payload = $json . "\n";
        $consecutiveSelectFailures = 0;

        while ($payload !== '') {
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0.0) {
                return false;
            }

            $write = [$this->pipes[0]];
            $read = $this->stderrOpen && is_resource($this->pipes[2]) ? [$this->pipes[2]] : [];
            $except = [];
            $seconds = $remaining === null ? self::READ_POLL_SECONDS : (int) $remaining;
            $micros = $remaining === null ? 0 : (int) (($remaining - $seconds) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $seconds, $micros);

            if ($ready === false) {
                // select fails on EINTR (and on interrupt-driven storms). A
                // dead child ends this immediately; a live one gets the
                // bounded-retry ceiling before we give up.
                $consecutiveSelectFailures++;

                if (!self::childIsRunning($this->process)
                    || $consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES
                ) {
                    return false;
                }

                usleep(1000);

                continue;
            }

            $consecutiveSelectFailures = 0;

            if ($read !== []) {
                $this->absorbStderr();
            }

            if ($ready === 0 || $write === []) {
                continue;
            }

            // Broken pipe surfaces here as fwrite() === false; the leading @
            // suppresses the notice because the MISSING RESPONSE downstream is
            // the real signal, and every failure path in this class already
            // reads as "the exchange failed".
            $written = @fwrite($this->pipes[0], $payload);

            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                // feof on a WRITE pipe does not detect a dead child (measured) —
                // fwrite() === false is that detector. A zero-byte write just
                // means the pipe is momentarily full: yield and retry.
                if (feof($this->pipes[0])) {
                    return false;
                }

                usleep(1000);

                continue;
            }

            $payload = substr($payload, $written);
        }

        fflush($this->pipes[0]);

        return true;
    }

    /**
     * Read framed lines until the response for $id arrives, skipping
     * notifications and foreign ids. A non-JSON-RPC line ENDS the search as
     * failure: whoever sent it is not speaking the protocol, and continuing
     * past garbage risks matching a stale id from a desynchronised stream.
     */
    private function readResponse(string $id, ?float $deadline = null): ?McpMessage
    {
        while (true) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }

            $line = $this->readLine($deadline);
            if ($line === null) {
                return null;
            }

            $message = McpMessage::parse($line);
            if ($message === null) {
                return null;
            }

            if ($message->isNotification() || ($message->id !== null && $message->id !== $id)) {
                continue;
            }

            return $message;
        }
    }

    /**
     * Return one newline-terminated frame, or null at EOF/deadline with an
     * empty buffer. A partial buffer at EOF/deadline is delivered whole
     * (drainBuffer) — unterminated tail bytes are the sender's framing bug and
     * deserve the same parse-failure path, not silent discarding.
     */
    private function readLine(?float $deadline = null): ?string
    {
        while (($newline = strpos($this->readBuffer, "\n")) === false) {
            if ($this->pipes === null) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0.0) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            // A CLOSED resource in a stream_select set raises ValueError that
            // no @ can silence (measured) — the is_resource guard is load-
            // bearing control flow, not decoration.
            if (!is_resource($this->pipes[1])) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $read = [$this->pipes[1]];
            if ($this->stderrOpen && is_resource($this->pipes[2])) {
                $read[] = $this->pipes[2];
            }
            $write = [];
            $except = [];
            $seconds = $remaining === null ? self::READ_POLL_SECONDS : (int) $remaining;
            $micros = $remaining === null ? 0 : (int) (($remaining - $seconds) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                if (feof($this->pipes[1])) {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                // EINTR with a live stream: brief yield, then re-poll.
                usleep(1000);

                continue;
            }

            if ($ready === 0) {
                continue;
            }

            if ($this->stderrOpen && in_array($this->pipes[2], $read, true)) {
                $this->absorbStderr();
            }

            if (!in_array($this->pipes[1], $read, true)) {
                // stderr-ready-only pass: stdout stayed silent, keep waiting.
                continue;
            }

            $chunk = fread($this->pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($this->pipes[1])) {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                usleep(1000);

                continue;
            }
            $this->readBuffer .= $chunk;
            $this->refuseAnOversizedFrame();
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    /**
     * One bounded non-blocking read of stderr into the tail cap. Called from
     * every wait so the child is never blocked writing diagnostics it cannot
     * flush (see class docblock for the deadlock law).
     */
    private function absorbStderr(): void
    {
        if (!$this->stderrOpen || $this->pipes === null || !is_resource($this->pipes[2])) {
            return;
        }

        $chunk = fread($this->pipes[2], 8192);

        if ($chunk === false || ($chunk === '' && feof($this->pipes[2]))) {
            // At EOF a pipe reads as permanently ready — leaving it selected
            // would spin every later wait. Close it for this instance instead.
            $this->stderrOpen = false;

            return;
        }

        if ($chunk === '') {
            // Nothing ready right now (non-blocking empty read): keep watching.
            return;
        }

        $this->stderrTail .= $chunk;

        if (strlen($this->stderrTail) > self::MAX_STDERR_BYTES) {
            $this->stderrTail = substr($this->stderrTail, -self::MAX_STDERR_BYTES);
        }
    }

    /** Human-readable stderr fragment for the start-failure exception. */
    private function stderrTailForDiagnostics(): string
    {
        $tail = trim($this->stderrTail);

        if ($tail === '') {
            return '';
        }

        return strlen($this->stderrTail) >= self::MAX_STDERR_BYTES
            ? ' [stderr truncated] ' . $tail
            : ' stderr: ' . $tail;
    }

    /**
     * Refuse a frame that grew past the cap with no newline. The buffer is
     * DROPPED, not truncated: half a frame parses as a malformed message and
     * would blame the server for this side's refusal.
     */
    private function refuseAnOversizedFrame(): void
    {
        if (strlen($this->readBuffer) <= self::MAX_FRAME_BYTES) {
            return;
        }

        $held = strlen($this->readBuffer);
        $this->readBuffer = '';

        throw new \RuntimeException(sprintf(
            'MCP server %s sent %d bytes with no newline, past this client\'s %d-byte frame '
            . 'cap; the buffer was dropped rather than truncated, because half a frame parses '
            . 'as a malformed message and would blame the server for this side\'s refusal',
            $this->name,
            $held,
            self::MAX_FRAME_BYTES,
        ));
    }

    private function drainBuffer(): string
    {
        $line = $this->readBuffer;
        $this->readBuffer = '';

        return trim($line);
    }

    /**
     * Extract tool definitions from a tools/list response through the
     * well-typedness gate. Three nested guards, each load-bearing: the
     * container (`result.tools` may be any JSON type), the entries (mixed
     * arrays arrive in the wild), and the per-definition type check inside
     * McpTool::tryFromArray.
     *
     * @param array<string,mixed> $response inspection-view array
     * @return list<McpTool>
     */
    private function parseTools(array $response): array
    {
        $tools = [];
        $toolDefs = $response['result']['tools'] ?? [];

        if (!is_array($toolDefs)) {
            $toolDefs = [];
        }

        foreach ($toolDefs as $def) {
            if (!is_array($def)) {
                continue;
            }

            $tool = McpTool::tryFromArray($def, $this->name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
