<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\McpMessage;

final class StdioMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    /** @var resource|null */
    private $process = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private $pipes = null;

    /**
     * Shutdown escalation budgets, matching
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend}'s: one second
     * for a well-behaved child to honour SIGTERM, one more after signal 9, and a
     * 5ms poll so a prompt exit is not rounded up to the whole budget.
     */
    private const TERMINATE_GRACE_SECONDS = 1.0;
    private const KILL_GRACE_SECONDS = 1.0;
    private const POLL_INTERVAL_US = 5000;

    /** Monotonic JSON-RPC request id — avoids collisions that `time()` causes. */
    private int $nextId = 0;

    /**
     * Persistent read buffer so a partial NDJSON line survives across reads:
     * one read may return less than a full line, and a server may emit
     * several messages in one burst.
     */
    private string $readBuffer = '';

    /**
     * THE CHILD'S STDERR IS DRAINED, AND THAT IS A DEADLOCK FIX, NOT A FEATURE.
     *
     * `start()` opens fd 2 as a pipe and nothing in this class ever read it,
     * closed it, or even took it out of blocking mode. A pipe has a fixed kernel
     * buffer — 64 KiB on this host (PHP 8.3.6, Linux 6.8) — and a child whose
     * stderr fills it BLOCKS IN ITS OWN `write()`. A blocked server does not
     * answer on stdout, so {@see readLine()} waited for a line that could never
     * arrive while holding the only thing that could have unblocked it.
     *
     * This is {@see \SugarCraft\Crush\Providers\ClaudeCodeProvider::completeStream()}'s
     * bug in a second place, and the shape is NOT identical — see
     * {@see absorbStderr()} for the three differences that make a copied fix
     * wrong here.
     *
     * The bytes are kept rather than discarded because {@see start()} had no
     * diagnostic at all: a server that dies printing a stack trace reported only
     * "Failed to start MCP server: <name>".
     */
    private string $stderrTail = '';

    /**
     * Whether fd 2 is still worth selecting on. A pipe AT EOF is permanently
     * "readable", so leaving a closed stderr in the `stream_select()` set turns
     * the deadline-less {@see callTool()} wait into a 100% CPU spin — the
     * failure a naive drain trades the hang for.
     */
    private bool $stderrOpen = false;

    /**
     * Cap on {@see $stderrTail}, matching
     * {@see \SugarCraft\Crush\Providers\ClaudeCodeProvider}'s. A cap because
     * the buffer grows across the WHOLE SESSION here, not across one completion,
     * so a server in a warning loop is otherwise an unbounded allocation in a
     * long-lived process. THE TAIL rather than the head because this text exists
     * to answer "why did it fail", and the reason a process gives is the last
     * thing it says.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * How long THE HANDSHAKE — `initialize` plus `tools/list`, one shared
     * wall clock across both — may take before {@see start()} gives this server
     * up. It is not, and must not become, a bound on {@see callTool()}: an MCP
     * tool call is the third-party equivalent of an LLM completion and may
     * legitimately run for minutes, while a handshake is a fixed two-message
     * exchange with a process that has just been spawned.
     *
     * SIXTY SECONDS, and the number is about `npx`-style servers specifically:
     * the canonical `.mcp.json` entry is `npx -y @modelcontextprotocol/server-…`,
     * whose FIRST run on a machine downloads and unpacks a package tree before
     * the server's own code executes at all. Measured reports of that cold path
     * sit in the 30–60s range, so a bound below it would turn "first launch after
     * a fresh checkout" into "this server is broken", which is the failure mode a
     * timeout must not manufacture. Every subsequent launch answers in
     * milliseconds, so the budget is paid only by a server that is genuinely
     * stuck.
     *
     * Per-server overridable from `.mcp.json` (`startTimeout`, in seconds) —
     * see {@see \SugarCraft\Crush\MCP\McpClient::startServer()} — because "how
     * long may this particular server take to come up" is a property of the
     * server, not of this class.
     */
    public const DEFAULT_START_TIMEOUT_SECONDS = 60.0;

    /**
     * Poll slice used while waiting for a line with NO deadline — the
     * {@see callTool()} path, which is deliberately unbounded. A slice rather
     * than an infinite `stream_select()` wait so the loop can still notice a
     * closed pipe; the length only affects how coarsely that is noticed.
     */
    private const READ_POLL_SECONDS = 1;

    private float $startTimeoutSeconds;

    /**
     * @param array<int, string> $args argv AFTER the program name — see
     *        {@see start()} for why this is an argv and not a shell string
     * @param array<string, string> $env
     * @param float|null $startTimeoutSeconds handshake budget in seconds; null
     *        takes {@see DEFAULT_START_TIMEOUT_SECONDS}
     */
    public function __construct(
        public readonly string $name,
        private string $command,
        private array $args,
        private array $env,
        ?float $startTimeoutSeconds = null,
    ) {
        $this->startTimeoutSeconds = $startTimeoutSeconds !== null && $startTimeoutSeconds > 0
            ? $startTimeoutSeconds
            : self::DEFAULT_START_TIMEOUT_SECONDS;
    }

    /**
     * Spawn the server and complete the MCP handshake, or throw.
     *
     * THE ARGV FORM OF `proc_open()`, NOT A SHELL STRING, and the difference is
     * what makes {@see stop()} able to reach the server at all. `command` +
     * `args[]` in `.mcp.json` IS an argv — the MCP config schema this package
     * parses ({@see \SugarCraft\Crush\MCP\McpClient::startServer()}) has a
     * separate string `command` and a separate list `args`, exactly like Claude
     * Code's, and nothing in it is a shell fragment. Handing that argv to
     * `sh -c` after `escapeshellarg()`ing it — which is what this method used to
     * do — bought nothing and cost the process tree: MEASURED on this host
     * (`/bin/sh` -> dash, which does NOT apply the `-c` exec optimisation) the
     * direct child was the wrapper and the server was a GRANDCHILD, so
     * `proc_terminate()` killed dash and left the server running, reparented to
     * pid 1, still answering `tools/call` over the inherited pipes:
     *
     *     1146812 1146811 sh -c '/usr/bin/php8.3' '…/stubborn.php'
     *     1146813 1146812 /usr/bin/php8.3 …/stubborn.php      <- the server
     *     after stop(): 1146812 gone, 1146813 ALIVE with PPID 1
     *
     * With the argv form the direct child IS the server, so the SIGTERM-then-9
     * escalation in {@see stop()} lands on it. WHAT THIS CHANGES FOR A CONFIG
     * THAT RELIED ON A SHELL: a `command` containing shell syntax — a pipeline,
     * `&&`, a glob, `$VAR`, a redirect — is no longer interpreted, because there
     * is no shell to interpret it. Such an entry was already outside the config
     * schema (its `command` is documented as a program, not a command line), and
     * a config that genuinely needs a shell can still say so explicitly:
     * `"command": "/bin/sh", "args": ["-c", "…"]`. That spelling keeps the shell
     * as the DIRECT child, which is the process this class then signals.
     *
     * `@proc_open()`: with the argv form an unresolvable program fails in
     * `posix_spawn()` and PHP raises `proc_open(): posix_spawn() failed: No such
     * file or directory` on top of returning false. The `false` IS the handling —
     * it becomes the exception below — and leaking a warning for a `.mcp.json`
     * entry naming a program the user has not installed would put PHP's noise
     * over the TUI (and red any `failOnWarning` suite) on a path that already
     * reports itself properly. Note this is a REAL detection improvement over the
     * shell form, where a bogus binary made `proc_open()` succeed (the shell
     * launched) and the failure surfaced only as a missing handshake response.
     *
     * @throws \RuntimeException when the program cannot be spawned, or the
     *         handshake does not complete within the budget — see
     *         {@see DEFAULT_START_TIMEOUT_SECONDS}
     */
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
            $this->env
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start MCP server: {$this->name}");
        }

        // NON-BLOCKING STDOUT, which is what lets {@see readLine()} honour a
        // deadline. `stream_set_timeout()` is the obvious instrument and it does
        // NOT work here — MEASURED on this host, it returns `false` for a
        // `proc_open()` pipe (an STDIO stream, not a socket) and a subsequent
        // `fgets()` still blocked for the full 5s a `sleep 5` child was alive.
        // So the bound is `stream_select()` plus a non-blocking `fread()`, and
        // the stream has to be non-blocking or a `fread()` of a half-written line
        // could still block after `select()` reported the pipe readable.
        stream_set_blocking($this->pipes[1], false);

        // FD 2 TOO, and it is not symmetry for its own sake: {@see absorbStderr()}
        // reads this pipe from inside the same `stream_select()` loop that waits
        // for stdout, and a blocking `fread()` there would re-create the wedge it
        // exists to close (a `select()` may report a pipe readable and still have
        // fewer bytes available than the read asks for).
        stream_set_blocking($this->pipes[2], false);
        $this->stderrOpen = true;
        $this->stderrTail = '';

        // ONE WALL CLOCK FOR THE WHOLE HANDSHAKE, not one per read. There are TWO
        // blocking exchanges below (`initialize` and `tools/list`), and a
        // per-read budget would let a server that answers each read promptly —
        // with a notification, forever — starve {@see readResponse()} without
        // ever tripping a per-read timeout. That is a real third shape beside
        // "dead" and "slow": live, chatty, and never answering. MEASURED before
        // this deadline existed: a server printing valid
        // `notifications/progress` in a loop made `start()` never return
        // (`timeout 15 php probe.php` -> rc=124), and so did one that simply
        // read its stdin and answered nothing.
        $deadline = microtime(true) + $this->startTimeoutSeconds;

        // Handshake: `initialize` is a REQUEST expecting a response, followed by
        // the `initialized` NOTIFICATION per the MCP spec.
        $response = $this->request('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
        ], $deadline);

        if ($response === null || ($response->result === null && $response->error === null)) {
            // READ THE TAIL BEFORE `stop()`, which clears it. Without this the
            // only thing a user ever saw for a server that died printing a stack
            // trace was the bare name — the child's own explanation was written
            // into a pipe nobody read.
            $diagnostics = $this->stderrTailForDiagnostics();
            $this->stop();

            throw new \RuntimeException("Failed to start MCP server: {$this->name}" . $diagnostics);
        }

        $this->notify('initialized');

        $listResponse = $this->request('tools/list', [], $deadline);
        $this->tools = $listResponse === null ? [] : $this->parseTools($listResponse->toArray());
    }

    /**
     * Shut the server child down in BOUNDED time.
     *
     * `proc_close()` WAITS for the child, so `proc_terminate()` immediately
     * followed by `proc_close()` — which is what this method used to be — hands
     * the caller's deadline to a third-party process that is free to ignore
     * SIGTERM. MEASURED on this host against the old two-liner, with a direct
     * child running `trap '' TERM; cat > /dev/null; sleep 8`: `proc_close()`
     * returned after 8.30s. An MCP server is by definition somebody else's code,
     * and this method is on the session's exit path.
     *
     * Same escalation as {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::terminateAndReap()},
     * deliberately: SIGTERM, a bounded poll of `proc_get_status()`, then signal 9.
     * Not consolidated with it in this bundle — see that method's docblock for the
     * one behavioural difference (it also has to drain pipes first) and this
     * bundle's report for the shared-code question.
     *
     * WHAT IT SIGNALS is the DIRECT CHILD, which since {@see start()} switched
     * to the argv form of `proc_open()` IS the server process — measured, with
     * the process tree, in that method's doc-block. The remaining gap is a server
     * that spawns children OF ITS OWN: those are not in this process's control
     * group and are not signalled here, which is a property of the server's own
     * process handling rather than something this method can close.
     */
    public function stop(): void
    {
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process);

            if (!self::waitForExit($this->process, self::TERMINATE_GRACE_SECONDS)) {
                // Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant:
                // that constant is defined by ext-pcntl, and naming an optional
                // extension's symbol on a shutdown path would make the shutdown
                // path itself fatal where the extension is absent.
                proc_terminate($this->process, 9);
                // Unchecked on purpose: after signal 9 the only way to still be
                // running is an uninterruptible kernel wait, and `proc_close()`
                // below is then the least-bad option left.
                self::waitForExit($this->process, self::KILL_GRACE_SECONDS);
            }

            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = null;
        $this->readBuffer = '';
        $this->stderrTail = '';
        $this->stderrOpen = false;
    }

    /**
     * Poll `proc_get_status()` until the child is gone or the budget runs out;
     * true if it exited. A bounded poll rather than a blocking wait, for the
     * reason {@see stop()} exists at all: an unflagged wait is precisely the
     * thing being replaced.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $budgetSeconds): bool
    {
        $deadline = microtime(true) + $budgetSeconds;

        do {
            if (!proc_get_status($process)['running']) {
                return true;
            }
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        return !proc_get_status($process)['running'];
    }

    /**
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * DELIBERATELY UNBOUNDED, unlike {@see start()}'s handshake: a `tools/call`
     * is somebody else's tool doing real work — a build, a search over a large
     * index, a remote API round trip — and a wall-clock cap here would abandon
     * results the model asked for. The handshake budget exists because a
     * two-message exchange with a just-spawned process has a knowable shape;
     * a tool call does not.
     *
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

        // A NON-ARRAY `result` IS WRAPPED, NOT RETURNED, and this branch exists
        // because {@see \SugarCraft\Crush\McpMessage}'s `$result` is `mixed`.
        // It was `?array`, which made a reply of `"result": true` a `TypeError`
        // inside `parse()`; widening it moved the same crash here, onto this
        // method's own `: array` return type, where it would have been a fresh
        // uncaught throw rather than a fix.
        //
        // The MCP spec does say a `tools/call` result is an object with
        // `content`, so a scalar here IS a misbehaving server — but the answer
        // to a misbehaving server is a tool result the model can read, not an
        // exception. The `{type: text}` shape is the one
        // {@see \SugarCraft\Crush\Tools\McpToolBridge::renderContent()} already
        // renders verbatim, so the scalar reaches the model as its own text.
        if (!is_array($response->result)) {
            return ['content' => [[
                'type' => 'text',
                'text' => is_string($response->result)
                    ? $response->result
                    : (json_encode($response->result) ?: ''),
            ]]];
        }

        return $response->result;
    }

    /**
     * Send a JSON-RPC request and read the matching response (by id).
     *
     * @param array<string, mixed> $params
     * @param float|null $deadline `microtime(true)` value past which the read
     *        gives up; null waits indefinitely — see {@see callTool()} for why
     *        that is the right default and {@see start()} for the one caller
     *        that supplies one
     */
    private function request(string $method, array $params, ?float $deadline = null): ?McpMessage
    {
        $id = (string) $this->nextId++;
        if (!$this->writeLine(McpMessage::request($id, $method, $params)->toJson())) {
            return null;
        }

        return $this->readResponse($id, $deadline);
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

        $line = $this->readLine(null);
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

        // DRAIN BEFORE WRITING, because the write below is BLOCKING and stdin is
        // the third pipe in the same deadlock. A child wedged in `write()` on a
        // full stderr is not reading its stdin either, so once the 64 KiB stdin
        // buffer also fills, `fwrite()` here blocks before `readLine()` — the
        // only other place that drains — is ever reached. One non-blocking read
        // costs nothing on the normal path and removes that ordering trap.
        $this->absorbStderr();

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
     * server-initiated notifications and stale responses. Returns null on EOF,
     * on non-JSON-RPC output, or on $deadline before a match.
     *
     * THE DEADLINE IS CHECKED HERE TOO, not only inside {@see readLine()}, and
     * the loop below is why: a server that has already flushed a burst of
     * notifications into {@see $readBuffer} is served entirely from that buffer,
     * so `readLine()` never reaches the `stream_select()` where its own check
     * lives. Skipping a notification is not progress towards a response.
     *
     * @param float|null $deadline see {@see request()}
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
     *
     * `stream_select()` + non-blocking `fread()` rather than `fgets()`, because
     * `fgets()` on a blocking pipe cannot be bounded at all and
     * `stream_set_timeout()` does not apply to a `proc_open()` pipe on this host
     * (measured — see {@see start()}). A caller with no deadline gets the same
     * wait-forever contract this method always had, in
     * {@see READ_POLL_SECONDS} slices.
     *
     * A DEADLINE EXPIRY IS REPORTED AS `null`, i.e. indistinguishably from EOF,
     * and that is enough for both callers: {@see start()} treats either as "this
     * server did not come up" and {@see callTool()} passes no deadline at all.
     * Anything finer would be a distinction with no reader.
     *
     * @param float|null $deadline see {@see request()}
     */
    private function readLine(?float $deadline = null): ?string
    {
        while (($newline = strpos($this->readBuffer, "\n")) === false) {
            // No pipe to refill from (process down) — flush any trailing bytes.
            if ($this->pipes === null) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0.0) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $read = [$this->pipes[1]];
            if ($this->stderrOpen) {
                $read[] = $this->pipes[2];
            }
            $write = [];
            $except = [];
            $seconds = $remaining === null ? self::READ_POLL_SECONDS : (int) $remaining;
            $micros = $remaining === null ? 0 : (int) (($remaining - $seconds) * 1_000_000);

            // `@`, because a signal arriving mid-select (the suite's own
            // `pcntl_alarm()` time limit, a SIGCHLD) makes `stream_select()`
            // return false with an `Interrupted system call` warning, and an
            // EINTR is not an error this method should report — it should retry.
            // A genuinely broken stream is caught by the `feof()` below.
            $ready = @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                if (feof($this->pipes[1])) {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                // Yield before retrying. An EINTR is not slowed by a millisecond,
                // and it is what stops a persistently failing `select()` on the
                // deadline-less path turning a block into a spin.
                usleep(1000);

                continue;
            }

            if ($ready === 0) {
                // Nothing to read within the slice. With a deadline the loop
                // re-checks it at the top and gives up; without one it waits on.
                continue;
            }

            // STDERR FIRST, AND UNCONDITIONALLY WHENEVER IT IS READY. Freeing
            // the child's stderr buffer is what lets it get back to writing the
            // stdout line this loop is waiting for, so it is progress even
            // though it produces no line.
            if ($this->stderrOpen && in_array($this->pipes[2], $read, true)) {
                $this->absorbStderr();
            }

            if (!in_array($this->pipes[1], $read, true)) {
                // Only stderr woke us. Nothing to parse; go round again — the
                // deadline is re-checked at the top of the loop, so a server
                // that emits stderr forever and never answers still gives up on
                // schedule rather than being kept alive by its own noise.
                continue;
            }

            $chunk = fread($this->pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($this->pipes[1])) {
                    // EOF with leftover buffered bytes: emit them as the final line.
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                // Readable but nothing there and not EOF: a spurious wakeup.
                // Yield rather than spinning the CPU while the deadline (or the
                // caller's patience) runs down.
                usleep(1000);

                continue;
            }
            $this->readBuffer .= $chunk;
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    /**
     * Take whatever is waiting on fd 2 and keep the tail of it.
     *
     * HOW THIS DIFFERS FROM {@see \SugarCraft\Crush\Providers\ClaudeCodeProvider::completeStream()},
     * which is the same defect fixed in a different shape. A copied fix would
     * have been wrong in three ways:
     *
     *  1. LIFETIME. That method drains both pipes in ONE loop that runs until
     *     both reach EOF, inside a single generator. This class is a long-lived
     *     session: many `request()`/`readResponse()` round trips, and
     *     {@see readLine()} returns on a NEWLINE with the child still alive and
     *     both pipes still open. There is no "read to EOF" to hang the drain on,
     *     so stderr's own EOF has to be tracked as separate state
     *     ({@see $stderrOpen}) instead of falling out of a loop condition.
     *  2. THE FAILURE A NAIVE DRAIN SUBSTITUTES. A pipe at EOF is permanently
     *     readable. Leaving fd 2 in the `select()` set after the child closes it
     *     turns the unbounded {@see callTool()} wait into a busy spin, so the
     *     EOF branch below MUST clear the flag. The provider's loop cannot hit
     *     this because reaching EOF is how it terminates.
     *  3. HOW BAD THE ORIGINAL WAS. The provider's wedge sat on a one-shot
     *     failure path. Here {@see callTool()} passes NO deadline at all — by
     *     design, a tool call may legitimately run for minutes — so the wedge
     *     was permanent rather than bounded, and {@see start()}'s was bounded
     *     only by the 60s handshake budget.
     *
     * A fourth difference is about the bytes rather than the loop: the provider
     * already needed stderr for its `RuntimeException` message, whereas nothing
     * here consumed fd 2 at all. {@see stderrTailForDiagnostics()} is therefore a
     * NEW diagnostic, not a preserved one.
     */
    private function absorbStderr(): void
    {
        if (!$this->stderrOpen || $this->pipes === null) {
            return;
        }

        $chunk = fread($this->pipes[2], 8192);

        if ($chunk === false || ($chunk === '' && feof($this->pipes[2]))) {
            // The child closed stderr (or the stream broke). Stop selecting on
            // it — see difference 2 above.
            $this->stderrOpen = false;

            return;
        }

        if ($chunk === '') {
            // Readable, nothing there, not EOF: a spurious wakeup, same as the
            // stdout path handles.
            return;
        }

        $this->stderrTail .= $chunk;

        if (strlen($this->stderrTail) > self::MAX_STDERR_BYTES) {
            $this->stderrTail = substr($this->stderrTail, -self::MAX_STDERR_BYTES);
        }
    }

    /**
     * The child's stderr tail, as a suffix for a failure message — empty string
     * when it said nothing, so a caller can concatenate unconditionally.
     */
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
