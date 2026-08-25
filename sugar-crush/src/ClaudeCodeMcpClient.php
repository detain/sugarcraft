<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use RuntimeException;
use SugarCraft\Crush\Support\ProcessReaper;

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

    /**
     * How long {@see sendMessage()} waits on a `select()` slice before looking
     * at the clock again. Small enough that the idle bound below has resolution,
     * large enough not to be a spin — the loop is not otherwise throttled,
     * because a writable pipe makes `stream_select()` return immediately.
     */
    private const WRITE_POLL_MICROS = 20000;

    /**
     * How long the child may accept ZERO stdin bytes before the write gives up.
     *
     * AN IDLE BOUND, DELIBERATELY, AND NOT A TOTAL ONE. A child that keeps
     * taking bytes is never abandoned however long the message is, so this
     * cannot cut off a large-but-progressing `tools/call`. What it does bound is
     * the one shape both siblings leave open: a LIVE child that has stopped
     * reading, where `stream_select()` times out every {@see WRITE_POLL_MICROS},
     * the `$ready === 0` branch continues, and no liveness check is consulted at
     * all — MEASURED for {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()}'s
     * null-deadline path, which ends at the child's death and at nothing else
     * (see point (d) of that method's doc-block for the generator).
     *
     * ⚠️ STDERR TRAFFIC IS NOT PROGRESS, and that is the sharp edge. A server
     * that floods fd 2 forever while never reading fd 0 is exactly the "live,
     * chatty, never answering" shape {@see \SugarCraft\Crush\MCP\StdioMcpServer::start()}
     * documents, and counting the drain as progress would let it hold this loop
     * open indefinitely. Only bytes the child TOOK reset the clock. Both
     * polarities are pinned in
     * {@see \SugarCraft\Crush\Tests\ClaudeCodeMcpClientStdinWedgeTest}.
     *
     * A POLICY NUMBER, NOT A MEASUREMENT: nothing in this tree derives 15.0. It
     * is chosen to be far above any scheduling hiccup on a local pipe and far
     * below the "indefinite" this loop would otherwise inherit.
     */
    private const WRITE_IDLE_SECONDS = 15.0;

    /**
     * How many CONSECUTIVE `stream_select()` failures {@see sendMessage()} will
     * absorb before treating the write as lost.
     *
     * `stream_select()` answers `false` for EINTR — a signal arrived — which is
     * a retry and not an error. Without a ceiling a persistently failing select
     * spins here forever. Same instrument and same count as
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::MAX_CONSECUTIVE_SELECT_FAILURES}
     * and its `LspConnection` twin.
     *
     * ⚠️ IN THIS CLASS THE WHOLE BRANCH IS DORMANT, WHICH IS MORE THAN THE
     * SIBLING'S NOTE CLAIMS.
     *
     * WHAT THIS SAID: "see the former for the measurement of which half of the
     * condition actually fires (the liveness check is dormant, the count is the
     * exit)" — a statement about which of the two terms wins.
     *
     * WHAT IS TRUE NOW: here neither term is evaluated at all, because the
     * `$ready === false` branch that holds them is never entered. MEASURED at
     * this tree: replacing {@see childIsRunning()}'s body with a `throw` SURVIVES
     * the covering suite, which it could not do if anything reached the branch.
     * Reaching it needs a signal to land inside this loop's `stream_select()`,
     * and nothing in the suite delivers one there.
     *
     * WHY BOTH STILL EARN THEIR PLACE: this is the class's only write path with
     * no wall clock, so an EINTR storm is exactly the failure they exist for, and
     * the branch becomes live the first time a real signal lands. Dormant is not
     * the same as wrong — but it does mean fifty-five rounds of green say nothing
     * about them, so {@see childIsRunning()} is measured directly instead, in
     * both polarities, by
     * `ClaudeCodeMcpClientStdinWedgeTest::testChildIsRunningAnswersBothPolaritiesEvenThoughItsCallerIsUnreached()`.
     */
    private const MAX_CONSECUTIVE_SELECT_FAILURES = 10000;

    /**
     * How much of the server's stderr is kept for diagnostics.
     *
     * 64 KiB is one pipe buffer on this host, which is the natural unit: it is
     * exactly the point at which an undrained fd 2 stops the child dead. See
     * {@see drainStderr()}.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * The TAIL of whatever the MCP server has written to stderr, bounded.
     *
     * The tail rather than the head because this text answers "why did it stop
     * talking", and the reason a process gives is the last thing it says.
     */
    private string $stderrTail = '';

    /**
     * Bytes read from the child's stdout that do not yet end in a newline.
     *
     * A PROPERTY, NOT A LOCAL, AND THAT IS A FIX. {@see readMessages()} kept this
     * buffer on its own stack frame, so a message whose line had not arrived
     * whole by the time the method returned was DISCARDED — and the next call
     * then parsed the remainder as a fragment. MEASURED on this host (PHP 8.3.6,
     * Linux 6.8), one fixture generator with two arms differing only in whether
     * the child's line crosses a poll boundary, three consecutive takes each:
     *
     *     whole line + newline in one write  ->  1 message seen
     *     half, 400ms pause, rest + newline  ->  0 messages seen (LOST)
     *
     * Both arms poll for 1.8s, which is the shape {@see callTool()} and
     * {@see listTools()} drive (100 attempts, 10 ms apart). A stdio server has no
     * obligation to flush a response in one `write(2)`, so the second arm is the
     * ordinary case for any reply larger than a pipe's worth — and the symptom
     * was `RuntimeException: No response received`, which reads as a dead server.
     */
    private string $readBuffer = '';

    /**
     * Does the child's stdin hold a FRAGMENT that nothing has terminated?
     *
     * Set when {@see sendMessage()} gives up part-way through a message. The
     * framing here is NDJSON, so — unlike `Content-Length` — the stream has a
     * resynchronisation point and a fragment is not terminal. But it is not free
     * either: MEASURED, three consecutive takes, a 200068-byte message against a
     * 65536-byte pipe capacity leaves 65536 bytes in the pipe, and the NEXT
     * message's bytes are appended to them, so the child reads ONE 65578-byte
     * line that is unparseable and BOTH messages are lost.
     *
     * So the next send leads with a bare newline. That costs the child one
     * malformed line it was going to get anyway, and buys back the message that
     * would otherwise have been eaten closing the fragment.
     */
    private bool $stdinFragmentPending = false;

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
        // AND fd 2, which is DRAINED in readMessages() — see drainStderr().
        // It was opened as a pipe and never read, and a pipe nobody reads
        // blocks the writer at one buffer.
        stream_set_blocking($pipes[2], false);

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
     * Send a raw message, DRAINING STDERR AS IT GOES, and either write all of it
     * or say so.
     *
     * THIS WAS A SINGLE `fwrite()` CHECKED WITH `!==  strlen()`, and that is the
     * third member of the family {@see \SugarCraft\Crush\MCP\StdioMcpServer::writeLine()}
     * and {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()} closed
     * before it. The symptom here is different from both, because {@see connect()}
     * puts fd 0 in NON-BLOCKING mode: there was no hang. There was a SHORT WRITE
     * reported as a failure with the prefix already in the child's pipe.
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes,
     * identical — a `tools/call` carrying 200000 bytes of arguments:
     *
     *     payload 200068 bytes  ->  fwrite() returned 65536 (the pipe capacity)
     *     the child then read ONE 65578-byte line, unparseable
     *
     * 65578, not 65536, is the whole finding: the next message this client sent
     * supplied the newline that terminated the fragment, so it was consumed INTO
     * the malformed line and lost with it. One short write costs two messages.
     *
     * THREE DIFFERENCES FROM THE NDJSON SIBLING, and they are why this is not a
     * copy of `writeLine()`:
     *
     *  a. STDERR IS DRAINED UNCONDITIONALLY ONCE PER PASS RATHER THAN SELECTED
     *     ON. `StdioMcpServer` tracks stderr's EOF in a `$stderrOpen` flag, so it
     *     can keep fd 2 in the read set and take it out when the child closes it;
     *     leaving a closed stderr in a `select()` read set makes it permanently
     *     "readable" and turns the loop into a spin. This class has no such flag,
     *     and {@see drainStderr()} is non-blocking and bounded at 16 reads, so
     *     calling it every pass costs nothing and needs no EOF bookkeeping — the
     *     same choice `LspConnection::writeMessage()` makes for the same reason.
     *  b. THE BOUND IS IDLE, NOT TOTAL. See {@see WRITE_IDLE_SECONDS}. The
     *     sibling accepts an unbounded write on {@see \SugarCraft\Crush\MCP\StdioMcpServer::callTool()}'s
     *     path on the grounds that a tool call is somebody else's real work; that
     *     is the right instinct and the wrong instrument, because the work
     *     happens AFTER the child has read the request, and a child that has read
     *     nothing for fifteen seconds is not doing the work — it is not there.
     *  c. A PARTIAL WRITE IS RECOVERABLE HERE. `Content-Length` framing has no
     *     resynchronisation point, which is why `LspConnection` latches the
     *     session dead. NDJSON resynchronises at the next newline, so this class
     *     records {@see $stdinFragmentPending} and the next send leads with one.
     *
     * @throws RuntimeException if the client is not connected, or the message
     *         could not be written in full
     */
    public function sendMessage(McpMessage $message): void
    {
        if (!$this->connected || $this->process === null) {
            throw new RuntimeException('MCP client not connected');
        }

        // TERMINATE A FRAGMENT LEFT BY AN EARLIER GIVE-UP BEFORE ANYTHING ELSE
        // GOES OUT — see {@see $stdinFragmentPending} for the measurement of what
        // it costs not to. Cleared optimistically: if this write also gives up
        // part-way the flag is set again below, and if it gives up before its
        // first byte the fragment is still unterminated, which the `$sent === 0`
        // branch restores.
        $prefix = $this->stdinFragmentPending ? "\n" : '';
        $this->stdinFragmentPending = false;

        $payload = $prefix . $message->toJson() . "\n";
        $total = strlen($payload);
        $sent = $this->writeAll($payload, self::WRITE_IDLE_SECONDS);

        if ($sent === $total) {
            return;
        }

        // Partial only if something went out. Nothing written is a LOST message
        // and leaves the stream where it was — including a fragment from an
        // earlier failure, which is still waiting for its newline.
        $this->stdinFragmentPending = $sent > 0 || $prefix !== '';

        throw new RuntimeException(sprintf(
            'Failed to write to MCP process stdin: %d of %d bytes went out%s',
            $sent,
            $total,
            $sent > 0
                ? '; the next send leads with a newline to resynchronise the stream'
                : '; the message was lost and the stream is unchanged',
        ));
    }

    /**
     * Push `$payload` at the child's stdin until it is gone or the loop gives
     * up, and report how many bytes actually went out.
     *
     * The byte COUNT rather than a bool because {@see sendMessage()} has to tell
     * "lost" from "half-sent", and those want different repairs.
     *
     * ⚠️ `$idleSeconds` HAS NO DEFAULT, AND THAT IS NOT COSMETIC. It is the same
     * decision E480 forced on
     * {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()}: a bound with
     * a default is a bound a caller inherits without choosing, and the whole
     * subject of this loop is a write that ran unbounded because nobody had to
     * say. The one production caller passes {@see WRITE_IDLE_SECONDS}; the tests
     * pass a small one so the row that pins the bound does not cost the suite
     * fifteen seconds to observe a property that is about the CLOCK and not
     * about the number.
     *
     * @param float $idleSeconds how long the child may accept ZERO bytes before
     *        this gives up. Stderr traffic does not reset it — see
     *        {@see WRITE_IDLE_SECONDS}.
     */
    private function writeAll(string $payload, float $idleSeconds): int
    {
        /** @var array<int, resource> $pipes */
        $pipes = $this->getPipes();

        if (!is_resource($pipes[0])) {
            return 0;
        }

        $total = strlen($payload);
        $lastProgress = microtime(true);
        $consecutiveSelectFailures = 0;

        while ($payload !== '') {
            if (microtime(true) - $lastProgress >= $idleSeconds) {
                return $total - strlen($payload);
            }

            // BEFORE the select, every pass. A child parked in `write(2)` on a
            // full stderr pipe has not read its stdin either, so this is what
            // makes the write below able to make progress at all — and it is
            // deliberately NOT counted as progress, see {@see WRITE_IDLE_SECONDS}.
            $this->drainStderr();

            $write = [$pipes[0]];
            $read = [];
            $except = [];

            // `@` for EINTR: a signal arriving mid-select is a retry, and under
            // `failOnWarning="true"` the warning alone would red a passing run.
            $ready = @stream_select($read, $write, $except, 0, self::WRITE_POLL_MICROS);

            if ($ready === false) {
                $consecutiveSelectFailures++;

                if (!self::childIsRunning($this->process)
                    || $consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    return $total - strlen($payload);
                }

                usleep(1000);

                continue;
            }

            $consecutiveSelectFailures = 0;

            if ($ready === 0 || $write === []) {
                continue;
            }

            // A dead child closes the read end; writing then raises a "broken
            // pipe" notice. Suppressed — the failed write is the signal.
            $written = @fwrite($pipes[0], $payload);

            if ($written === false) {
                return $total - strlen($payload);
            }

            if ($written === 0) {
                // Reported writable and took nothing: a spurious wakeup. Yield
                // rather than spinning. NOT progress, so the idle clock runs on.
                usleep(1000);

                continue;
            }

            $lastProgress = microtime(true);
            $payload = substr($payload, $written);
        }

        fflush($pipes[0]);

        return $total;
    }

    /**
     * Is the server child still there? A LIVENESS check, deliberately not a
     * timeout: {@see writeAll()}'s EINTR branch needs to tell "a signal
     * interrupted the select" from "there is nobody left to write to", and
     * elapsed time answers neither.
     *
     * @param resource|null $process
     */
    private static function childIsRunning($process): bool
    {
        if (!is_resource($process)) {
            return false;
        }

        return (bool) proc_get_status($process)['running'];
    }

    /**
     * Read whatever complete NDJSON lines the child has produced since last time.
     *
     * THE PARTIAL LINE SURVIVES THE CALL, and it used not to. The accumulator was
     * a LOCAL, so a reply that had not arrived whole by the time this method
     * returned was thrown away with the stack frame — see {@see $readBuffer} for
     * the two-arm measurement. Nothing about the child was wrong in that case:
     * a stdio server is under no obligation to put a response on the wire in one
     * `write(2)`, and {@see callTool()} polls this method a hundred times, so
     * crossing a poll boundary is the ordinary case rather than the corner.
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

        // BEFORE stdout. A child blocked writing to a full stderr pipe has not
        // written its next stdout byte either, so draining fd 2 first is what
        // makes the loop below able to make progress at all.
        $this->drainStderr();

        // `is_resource()`, NOT `@`: `fread()` on an fclose'd pipe raises a
        // TypeError, and `@` does not suppress an exception. Same guard and same
        // measurement as {@see drainStderr()}.
        if (!is_resource($pipes[1])) {
            return [];
        }

        while (true) {
            $chunk = fread($pipes[1], self::READ_CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->readBuffer .= $chunk;
        }

        // SPLIT ONCE, AFTER THE READS, rather than inside the loop. The old shape
        // re-split a growing accumulator on every chunk, which was quadratic in
        // the number of chunks and — far worse — put the "keep the tail" step
        // somewhere the tail could not outlive.
        $lines = explode("\n", $this->readBuffer);
        $this->readBuffer = (string) array_pop($lines);

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

        return $messages;
    }

    /**
     * Disconnect and clean up the MCP process, in BOUNDED time.
     *
     * THE UNFIXED TWIN OF {@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()},
     * which is why this method now delegates to the same ladder rather than
     * growing a third spelling of it. Both classes own a stdio MCP child; that
     * one learned the escalation and this one did not, and the difference was
     * `fclose()` on the pipes followed by a bare `proc_close()`.
     *
     * `proc_close()` WAITS. MEASURED on this host (PHP 8.3.6) against a direct
     * child that installs a no-op `SIGTERM` handler and then loops for eight
     * seconds, the `proc_terminate()`-then-`proc_close()` shape returns after
     * **7.77s** — it does not abandon the child, it blocks for the child's whole
     * remaining lifetime. This method had not even the `proc_terminate()`: it
     * went straight to the wait. And it is reached from {@see __destruct()}, so
     * the block lands wherever the last reference happens to be dropped.
     *
     * WHY THE PIPES ARE CLOSED FIRST, and why that is not by itself enough. A
     * stdio MCP server's documented exit signal is EOF on its stdin, so closing
     * the pipes gives a well-behaved server the chance to leave on its own — and
     * {@see \SugarCraft\Crush\Support\ProcessReaper::terminateAndClose()}
     * checks `proc_get_status()` before signalling, so a server that takes it
     * pays no signal and no part of the escalation budget. A server that ignores
     * EOF is exactly the case the ladder below exists for; EOF is a courtesy, not
     * a mechanism.
     *
     * THE DIRECT CHILD IS THE SERVER here, so the signal reaches it:
     * {@see connect()} passes `proc_open()` an ARGV (`array_merge([$command],
     * $args)`), not a shell string. Under a string, `/bin/sh` on this host is
     * dash, which does NOT apply the `-c` exec optimisation — MEASURED: the
     * direct child's `comm` is `(sh)` and the real program is a grandchild, so
     * a signal to the direct child kills a wrapper. That trap is documented at
     * length on {@see \SugarCraft\Crush\MCP\StdioMcpServer::start()}; this
     * class was already on the right side of it and must stay there.
     */
    public function disconnect(): void
    {
        if (!$this->connected || $this->process === null) {
            return;
        }

        // ONE LAST DRAIN, BEFORE THE PIPES GO. A child blocked in write(2) on a
        // full stderr pipe cannot run its own SIGTERM handler, so it would take
        // the ladder's escalation to signal 9 every time. Emptying fd 2 first
        // gives it the chance to exit on the polite signal, and leaves
        // {@see stderrTail()} holding whatever it said on the way out.
        $this->drainStderr();

        if ($this->pipes !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        ProcessReaper::terminateAndClose($this->process);

        $this->process = null;
        $this->pipes = null;
        $this->connected = false;
        // The half-line and the unterminated fragment both belong to a session
        // that no longer exists; carrying either into a reconnect would put a
        // stranger's bytes at the head of the next server's first message.
        $this->readBuffer = '';
        $this->stdinFragmentPending = false;
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
     * Take whatever the server has written to stderr and keep the tail.
     *
     * ⚠️ THIS IS NOT DIAGNOSTICS PLUMBING; IT IS WHAT STOPS THE SERVER WEDGING.
     * {@see connect()} gives the child fd 2 as a `['pipe', 'w']` and nothing in
     * this class ever read it. A pipe whose reader never reads holds at most
     * one kernel buffer, after which the WRITER blocks in `write(2)` — so an
     * MCP server that logged more than that never got to write its next
     * JSON-RPC line, and could not exit either.
     *
     * MEASURED on this host (PHP 8.3.6, Linux 6.8, 64 KiB pipe buffer) with a
     * child that writes N bytes to stderr and then a framed reply to stdout,
     * fd 1 non-blocking and fd 2 never read, 5.0s deadline / 5ms poll — three
     * consecutive takes, identical: N = 1000 and N = 60000 both deliver the
     * reply in 0.04s; N = 100000 never delivers it at all.
     *
     * THE SYMPTOM IS NOT A HANG HERE. {@see readMessages()} returns whatever it
     * has and moves on, so the caller sees an empty list on time while the
     * server is permanently stuck, and {@see isConnected()} goes on answering
     * true. An MCP server that logs to stderr — which is the conventional place
     * for a stdio-transport server to log, since stdout is the protocol — is
     * the ordinary case, not the pathological one.
     */
    private function drainStderr(): void
    {
        // `is_resource()`, NOT `@`: `fread()` on a closed pipe raises a
        // TypeError, and `@` does not suppress an exception. That is the E367
        // mistake, where an `@stream_get_contents()` on an fclose'd pipe meant
        // the RuntimeException being built was never constructed at all.
        if ($this->pipes === null || !isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return;
        }

        // SET HERE TOO, NOT ONLY IN connect(). The loop below calls `fread()`
        // on fd 2, and on a BLOCKING pipe that call waits for a child that may
        // have nothing more to say — so this method's correctness depended on a
        // line in a different method thirty lines away. MEASURED: with the
        // `connect()` call deleted and this absent, the 4-second
        // ClaudeCodeMcpClientShutdownTest suite had not finished after 300s.
        // That is the worst shape for a regression to take, because CI reports
        // a stuck job rather than a failed assertion. Making the drain set its
        // own precondition means the mode does not exist.
        stream_set_blocking($this->pipes[2], false);

        // Bounded per pass rather than "until EOF": a child writing faster than
        // this reads must not be able to hold the caller here forever.
        for ($i = 0; $i < 16; $i++) {
            $chunk = @fread($this->pipes[2], self::READ_CHUNK_SIZE);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $this->stderrTail = substr($this->stderrTail . $chunk, -self::MAX_STDERR_BYTES);
        }
    }

    /**
     * The tail of the server's stderr, for a caller trying to explain a client
     * that went quiet. Empty when the server has said nothing.
     */
    public function stderrTail(): string
    {
        return $this->stderrTail;
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
