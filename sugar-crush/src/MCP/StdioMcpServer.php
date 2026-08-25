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

    /**
     * How many CONSECUTIVE `stream_select()` failures {@see writeLine()}
     * tolerates before giving up on the write.
     *
     * `stream_select()` answers `false` for EINTR — a signal arrived — which is a
     * retry and not an error, and that branch had no exit of ANY kind: a
     * persistently failing select spun at 1 ms forever on a path that
     * {@see callTool()} deliberately leaves unbounded. THIS COUNT IS THE EXIT
     * THAT FIRES.
     *
     * ⚠️ THE LIVENESS HALF OF THE CONDITION IN THAT BRANCH IS DORMANT BY
     * CONSTRUCTION, AND IT WAS WRITTEN AS THAT BRANCH'S PRIMARY EXIT. It is not. A write-set `stream_select()`
     * can only be INTERRUPTED if it BLOCKS, and it only blocks when the pipe is
     * full AND the child is alive — every other state makes the fd instantly
     * ready. MEASURED on this host (PHP 8.3.6, Linux 6.8), 1s of a 300 µs SIGUSR1
     * storm per state, three consecutive takes, identical every time:
     *
     *     pipe   child   select false   select ok
     *     empty  live            0        ~695000
     *     FULL   LIVE        ~2815              0     <- the only interruptible state
     *     empty  dead            0        ~660000
     *     full   dead            0        ~692000
     *
     * So a dead child can never reach this branch: its fd never blocks, the loop
     * reaches `fwrite()`, and `$written === false` is what catches it — every
     * time, on both pipe states. The check is KEPT rather than deleted because it
     * costs one status call per EINTR, it is correct, and it becomes live the
     * moment the loop's shape changes (an `except` set, a poll on an empty pipe,
     * a child that dies between the select and the check). Its dormancy is pinned
     * by `StdioMcpServerWriteBoundsTest::testOnlyAFullPipeWithALiveChildCanInterruptTheWriteSelect()`,
     * which reds if the `full/dead` figure ever moves.
     *
     * ⚠️ `feof()` WOULD BE THE WRONG CHECK HERE, and {@see readLine()}'s EINTR
     * branch using it is not a precedent to copy — see the measurement in the
     * `$written === 0` branch below: a WRITE pipe does not report the reader's
     * exit through `feof()` at all.
     *
     * DELIBERATELY GENEROUS. MEASURED on this host (PHP 8.3.6, Linux 6.8), the
     * densest signal storm this box can produce — a forked child sending SIGUSR1
     * every 300 µs — makes `stream_select()` fail about 2800 times a second with
     * ZERO successes interleaved; three consecutive takes gave 1407, 1406 and
     * 1407 failures in 0.5 s, and the `full/live` row of the table above is the
     * same figure re-measured through a WRITE-set select rather than a read one.
     * With this loop's 1 ms yield on the failure path
     * that is roughly 500–900 a second, so 10000 is on the order of ten seconds
     * of unbroken interruption.
     */
    private const MAX_CONSECUTIVE_SELECT_FAILURES = 10000;

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

        // AND FD 0, because {@see writeLine()} drives the write from a
        // `stream_select()` loop for the same reason {@see readLine()} drives the
        // read from one — see that method for the measurement.
        stream_set_blocking($this->pipes[0], false);
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

        // `!$response->resultSet`, NOT `result === null`: a server answering
        // `initialize` with a legal `"result": null` used to be rejected here as
        // "answered nothing at all", because the two were indistinguishable. It is
        // still a MISBEHAVING answer — the MCP spec says the result is an object
        // with a `protocolVersion` — but the gate this branch guards is "did the
        // server answer", and it did. `parseTools()` reads the tools out with `??
        // []`, so a server that answers null all the way through comes up with no
        // tools rather than being reported as a failed launch.
        if ($response === null || (!$response->resultSet && $response->error === null)) {
            // READ THE TAIL BEFORE `stop()`, which clears it. Without this the
            // only thing a user ever saw for a server that died printing a stack
            // trace was the bare name — the child's own explanation was written
            // into a pipe nobody read.
            $diagnostics = $this->stderrTailForDiagnostics();
            $this->stop();

            throw new \RuntimeException("Failed to start MCP server: {$this->name}" . $diagnostics);
        }

        $this->notify('initialized', null, $deadline);

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
            // CLOSE THE PIPES FIRST, AND THE ORDER IS THE WHOLE POINT — this is
            // not hygiene about resource lifetimes. A server whose stdin is still
            // open has been given no reason to leave, so it sits through the
            // SIGTERM grace below and is killed with signal 9; closing fd 0
            // delivers the EOF that a well-written server treats as "shut down
            // now", and it exits on its own before the grace is paid.
            //
            // MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive
            // takes, identical, against a child that traps SIGTERM to a no-op and
            // exits on stdin EOF, driven through this exact ladder:
            //
            //     pipes left open    -> 1.05s, escalated to signal 9, status 9
            //     pipes closed first -> 0.010s, exited on its own, status 0
            //
            // A hundredfold, and the difference between a clean exit and a
            // SIGKILL for any server that traps SIGTERM to flush state. This
            // method used to set `$this->pipes = null` below and leave the
            // resources to the destructor, which arrives long after
            // `proc_close()` has finished waiting.
            //
            // NO FINAL DRAIN BEFORE CLOSING FD 2, unlike
            // {@see \SugarCraft\Crush\LSP\LspConnection::stopProcess()}: the one
            // caller that wants the tail — {@see start()}'s failure branch —
            // already read it into a local before calling this, and every path
            // through here clears {@see $stderrTail} at the end regardless, so a
            // drain here would collect bytes nothing can ever read.
            $this->closePipes();

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
        // Unconditional, because {@see stop()} above only reaches its own call
        // when the process handle is live: a connection torn down some other way
        // still has pipes to release here.
        $this->closePipes();

        $this->process = null;
        $this->pipes = null;
        $this->readBuffer = '';
        $this->stderrTail = '';
        $this->stderrOpen = false;
    }

    /**
     * Release the three pipe resources, if they are still open.
     *
     * Idempotent, because {@see stop()} calls it twice on the ordinary path —
     * once before the escalation ladder for the EOF, and once after, for a
     * teardown that never reached the ladder at all.
     */
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
     * Is the server child still there? A LIVENESS check, deliberately not a
     * timeout: {@see writeLine()}'s EINTR branch needs to tell "a signal
     * interrupted the select" from "there is nobody left to write to", and
     * elapsed time answers neither. It is also the only bound
     * {@see callTool()}'s deadline-less path can accept without capping a tool
     * call that is legitimately slow.
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

        // `!$response->resultSet` rather than `result === null`, so a legal
        // `"result": null` is a RESULT and falls through to the wrapping branch
        // below — where it is rendered as the text `null`, exactly as `false` and
        // `0` are. An error response still lands here, because an error carries no
        // `result` key at all.
        if ($response === null || !$response->resultSet) {
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
            // `?:` HERE WOULD DESTROY A ZERO, which is the very coercion this
            // branch exists to prevent, one layer down. `json_encode(0)` is the
            // STRING `"0"` — falsy in PHP — so `json_encode($r) ?: ''` turned a
            // legal `"result": 0` into an EMPTY tool result while every other
            // scalar came through intact. MEASURED against a real server child
            // before the fix: `0` and `0.0` both arrived at the model as `''`,
            // `5` and `false` as `'5'` and `'false'`.
            //
            // The `=== false` arm is a RETURN-TYPE FORMALITY, not a live path:
            // `null` is answered above, arrays and strings take the other
            // branches, so all this call can ever see is a bool, an int or a
            // float from `json_decode()`, and `json_encode()` cannot fail on
            // those. It is spelled explicitly anyway because `text` is declared
            // `string` and `json_encode()` is declared `string|false`.
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
        if (!$this->writeLine(McpMessage::request($id, $method, $params)->toJson(), $deadline)) {
            return null;
        }

        return $this->readResponse($id, $deadline);
    }

    /**
     * Send a JSON-RPC notification (no id, no response expected).
     *
     * @param array<string, mixed>|null $params
     * @param float|null $deadline the caller's wall clock, where it has one — a
     *        notification expects no reply, which is not the same as expecting no
     *        bound: {@see start()}'s `initialized` sits BETWEEN its two bounded
     *        exchanges and would otherwise be the one unbounded step in a
     *        handshake that is bounded end to end
     */
    private function notify(string $method, ?array $params = null, ?float $deadline = null): void
    {
        $this->writeLine(McpMessage::notification($method, $params)->toJson(), $deadline);
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
     * Write one newline-framed message to the child's stdin, DRAINING STDERR AS
     * IT GOES.
     *
     * STDIN IS THE THIRD PIPE IN THE STDERR DEADLOCK, and this loop is here
     * because a single drain before a blocking `fwrite()` — which is what this
     * method briefly was — DOES NOT CLOSE IT. Generator: a child running
     * `fwrite(STDERR, str_repeat("e", N))` and only then reading stdin; a parent
     * that performs D non-blocking 8192-byte reads of stderr and then writes M
     * bytes; 4s bound. PHP 8.3.6, Linux 6.8, three consecutive takes, identical
     * every time:
     *
     *     N=100000  D=0   M=200000  ->  WEDGED (bound hit)
     *     N=100000  D=1   M=200000  ->  WEDGED (bound hit)   <- the one-shot drain
     *     N=100000  D=20  M=200000  ->  wrote 200001, 0.35s  <- flood fully drained
     *     N=1000    D=0   M=200000  ->  wrote 200001, 0.35s  <- stderr under capacity
     *     N=100000  D=0   M=1000    ->  wrote 1001,    0.35s  <- write under capacity
     *
     * The last two rows are the controls: BOTH sides have to be over their pipe
     * capacity for the deadlock to exist. It is reachable in normal use — a
     * server that logs progress to stderr while nothing is reading it (the model
     * is thinking between tool calls) plus a `tools/call` whose arguments carry a
     * file's contents is exactly the pair.
     *
     * One 8192-byte read frees 8192 bytes and the child immediately writes 8192
     * more, so a fixed number of reads is the wrong instrument at any count: the
     * bound has to be "until the write completes", which is what the loop below
     * is.
     *
     * ON THE DEADLINE, AND WHY THIS PARAGRAPH CHANGED TWICE.
     *
     * WHAT IT SAID FIRST: that being unbounded matched "this class's existing
     * contract". Half true, and wrong in the half that matters —
     * {@see readLine()} takes a `?float $deadline` and {@see readResponse()}
     * threads it from {@see request()}, so on {@see start()}'s path the handshake
     * budget already bounded the READ half of every exchange and never reached
     * this loop.
     *
     * WHAT IT SAID NEXT: that leaving the write unbounded still earned its place,
     * because liveness was no worse than the blocking `fwrite()` the loop
     * replaced. Also true, and also not good enough: a server that spawns, holds
     * its pipes open, never reads stdin and never writes stderr held `start()`
     * here indefinitely once the message exceeded the stdin pipe buffer, on a
     * path whose caller was already holding a 60s budget it simply was not being
     * given.
     *
     * WHAT IS TRUE NOW: the deadline is threaded. {@see request()} passes
     * {@see start()}'s handshake budget through, and so does {@see notify()}, so
     * BOTH halves of every handshake exchange are bounded by the one wall clock.
     *
     * WHY THE NULL DEFAULT STILL EARNS ITS PLACE: {@see callTool()} passes no
     * deadline, deliberately — an MCP tool call is somebody else's real work and
     * may legitimately run for minutes, so a wall clock there would abandon
     * correct servers. That path is bounded by the CHILD'S LIVENESS instead (see
     * {@see MAX_CONSECUTIVE_SELECT_FAILURES} and the `$written === false` branch
     * below), which is the right question for it: "is there anybody to write to",
     * not "has this taken too long".
     *
     * @param float|null $deadline `microtime(true)` value past which the write
     *        gives up; null bounds on the child's liveness alone
     */
    private function writeLine(string $json, ?float $deadline = null): bool
    {
        if (!is_resource($this->process) || $this->pipes === null) {
            return false;
        }

        $payload = $json . "\n";
        $consecutiveSelectFailures = 0;

        while ($payload !== '') {
            // Same shape as {@see readLine()}'s: the remaining budget is both the
            // give-up test and the `select()` slice, so a loop with a deadline
            // never waits past it and a loop without one falls back to the poll.
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0.0) {
                return false;
            }

            $write = [$this->pipes[0]];
            $read = $this->stderrOpen ? [$this->pipes[2]] : [];
            $except = [];
            $seconds = $remaining === null ? self::READ_POLL_SECONDS : (int) $remaining;
            $micros = $remaining === null ? 0 : (int) (($remaining - $seconds) * 1_000_000);

            // `@` for EINTR, same as {@see readLine()}: a signal arriving
            // mid-select is a retry, and under `failOnWarning="true"` the warning
            // alone would red a passing run.
            $ready = @stream_select($read, $write, $except, $seconds, $micros);

            if ($ready === false) {
                $consecutiveSelectFailures++;

                // AN EINTR IS A RETRY; AN ENDLESS ONE IS NOT. This branch had no
                // exit of any kind, so a persistently failing select spun here at
                // 1 ms forever — on {@see callTool()}'s path, which has no
                // deadline to stop it. See {@see MAX_CONSECUTIVE_SELECT_FAILURES}
                // for why `feof()` is not the instrument, and for the dormancy of
                // the liveness half of this condition.
                if (!self::childIsRunning($this->process)
                    || $consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
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

            // A dead child (e.g. a bogus command that already exited) closes the
            // pipe; writing then raises a "broken pipe" notice. Suppress it — the
            // missing response is what signals start() that the server failed,
            // not the write.
            $written = @fwrite($this->pipes[0], $payload);

            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                // Reported writable and took nothing: a spurious wakeup. Yield
                // rather than spinning.
                //
                // ⚠️ THE `feof()` BELOW IS NOT THE DEAD-CHILD DETECTOR, AND AN
                // EARLIER DRAFT OF THIS COMMENT SAID IT WAS. MEASURED on this
                // host (PHP 8.3.6, Linux 6.8), three consecutive takes, writing
                // to the stdin pipe of a child that has already exited:
                // `stream_select()` reports the pipe WRITABLE, `fwrite()`
                // returns `false`, and `feof()` returns **false** — a write pipe
                // does not report the reader's exit through `feof()` at all. So
                // the branch that actually catches a dead child is the
                // `$written === false` above it, every time.
                //
                // WHY THIS STILL EARNS ITS PLACE: it is the only exit this
                // branch has. If some stream ever does answer `feof()` true here
                // while still accepting a zero-length write, the alternative is
                // an unbounded loop; and the cost when it never fires is one
                // `feof()` per spurious wakeup.
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
