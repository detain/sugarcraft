<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use RuntimeException;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Mcp\ExchangeLock;
use SugarCraft\Mcp\RequestIdSequence;

/**
 * MCP client that connects to Claude Code via stdio transport.
 * Sends JSON-RPC 2.0 messages and receives responses using
 * non-blocking I/O so the TUI loop stays responsive.
 *
 * Mirrors the MCP spec stdio transport:
 * https://modelcontextprotocol.io/specification/basic/transports/
 *
 * A GATED SEAM as of E699 — and named so you can tell which one you are
 * reading: this used to be the dormant sibling of the live transport client. This
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
 * can be said, and it was said about the sibling for as long as this file was
 * the dormant one; E699 gave THIS class its own wired door — the gated
 * `claude-mcp` factory arm described below, not a general reachability.
 * Reading the sentence that used to be here as covering both is exactly the
 * basename confusion the rename existed to end.
 *
 * THE GATED-REACHABILITY LAW. This class is reachable from a run ONLY through
 * the `claude-mcp` arm of
 * {@see \SugarCraft\Crush\MCP\McpClient::buildServer()}, which constructs it
 * exclusively via {@see \SugarCraft\Crush\MCP\ClaudeCodeMcpServer::fromGrant()}
 * behind the double opt-in: a `.mcp.json` entry of that type AND an
 * operator-tier `claudeMcpBinary` absolute path in the user config — the
 * repository cannot name this binary, its args, or its environment. The two
 * gates the old dormancy note demanded arrive as `claudeMcpBinary` (path
 * policy with NO $PATH trust: {@see resolveExecutable()} stays a test-double
 * convenience, and the factory arm refuses a bare grant before this class
 * ever sees one) and the
 * {@see \SugarCraft\Crush\Tools\McpToolBridge}/PreToolUse chain in front of
 * the forwarded calls, respectively. {@see \SugarCraft\Crush\Support\ContainedPath}
 * is deliberately NOT applied to the grant: containment answers
 * repository-chosen paths, and this path is operator-authored in the user
 * tier whose ownership the settings reader already enforces — there is no
 * anchor a `within()` could check it against.
 *
 * {@see \SugarCraft\Crush\Tests\ClaudeCodeMcpClientTest::testTheOnlyPathToThisSeamIsTheGatedFactoryArm()}
 * enforces exactly that set, separating comments from code with
 * `token_get_all()` rather than grepping bytes: a doc-comment mention reaches
 * nothing, and reporting one as a call site would be this file's own basename
 * confusion in a new costume. Reaching for this class through any other door
 * is how a process-spawning seam goes live ungated, and the pin reddens.
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
     *
     * ⚠️ WHAT THE NUMBER ACTUALLY COSTS, MEASURED, BECAUSE THE FIRST ACCOUNT OF
     * IT WAS WRONG ABOUT WHICH CALLS PAY IT.
     * WHAT WAS SAID (E508): "The handshake is safe: a fresh pipe is empty, so a
     * few hundred bytes always fit and the loop never waits. A large
     * `tools/call` from the TUI is not: the loop can now park the calling thread
     * for fifteen seconds." I.e. the cost was attributed to PAYLOAD SIZE.
     * WHAT IS TRUE NOW: payload size is irrelevant. MEASURED on this host
     * (PHP 8.3.6, Linux 6.8), three consecutive takes, `sendMessage()` driving
     * this constant against two children:
     *
     *     a child that READS  10485853 bytes  ->  0.028s / 0.026s / 0.401s
     *                          1048669 bytes  ->  0.036s / 0.035s / 0.037s
     *     a child that DOES NOT  65629 bytes  ->  15.006s / 15.006s / 15.009s
     *
     * Ten mebibytes to a live reader never approaches the bound; sixty-five
     * kilobytes to a deaf one pays it in full. So this is a WEDGED-SERVER bound,
     * not a big-message bound, and 65629 is the interesting figure — barely one
     * pipe buffer over, which is the smallest message that can pay the whole
     * fifteen seconds.
     * WHY IT STILL EARNS ITS PLACE: the alternative for a wedged server is the
     * unbounded wait this loop used to have. The number is not defended here;
     * what is defended is that it is an IDLE bound, so no progressing write can
     * ever hit it — which the first row above is the evidence for.
     *
     * ⚠️ IT USED TO BE FIFTEEN TIMES {@see callTool()}'s OWN READ BUDGET, a
     * counted poll worth about one second. That poll is gone (audit MCP-3): a
     * tool call's read has no total deadline now, so this idle bound is the
     * only clock left on that path — see that method.
     */
    private const WRITE_IDLE_SECONDS = 15.0;

    /**
     * How many CONSECUTIVE `stream_select()` failures {@see sendMessage()} will
     * absorb before treating the write as lost — and, since audit MCP-3,
     * {@see readFrame()} before treating the wait as lost; its EINTR branch is
     * dormant in the suite for the same reason as the write's.
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
     * this tree by mutating the BRANCH rather than either term — a `throw` as the
     * first statement inside `if ($ready === false)` SURVIVES all four
     * `ClaudeCodeMcpClient` suites, 42 rows, rc 0. Reaching it needs a signal to
     * land inside this loop's `stream_select()`, and nothing in the suite
     * delivers one there.
     *
     * ⚠️ MUTATE THE BRANCH, NOT {@see childIsRunning()}, IF YOU RE-CHECK THIS.
     * A `throw` in that method's body used to survive too, and no longer does —
     * the row named below calls it directly. That row is deliberately not a
     * caller of the branch, so a surviving mutation of the METHOD would now
     * report the reachability of a test rather than of the branch.
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
     * Upper bound on ONE NDJSON line, and on the persistent read buffer that
     * accumulates towards it.
     *
     * ⚠️ IT IS THE BUFFER THAT WAS UNBOUNDED, NOT THE PEER. {@see $readBuffer}
     * became instance state so that a line split across polls survived the call
     * that read half of it — that fix was right and is measured in the property's
     * own note — but it JOINED an existing family defect rather than creating
     * one: {@see \SugarCraft\Crush\MCP\StdioMcpServer} and
     * {@see \SugarCraft\Crush\LSP\LspConnection} were uncapped before it. A
     * server that emits an endless stream with no newline grows this without
     * limit for the life of the process, and {@see callTool()} keeps reading
     * for as long as the call takes.
     *
     * SIXTY-FOUR MEBIBYTES, AND THE INHERITANCE IS NOW A LANGUAGE FACT: this
     * line NAMES {@see \SugarCraft\Crush\Backend\EngineBackend::MAX_FRAME_BYTES}
     * rather than repeating its arithmetic. The engine puts the same bound on
     * the same question, for the same reason — a frame legitimately carries raw
     * image bytes, and a corrupt stream must never make the parent buffer an
     * arbitrary length before noticing.
     *
     * WHAT THIS SAID: "inherited rather than invented", full stop. WHAT WAS
     * TRUE WHEN IT SAID IT: nothing derived the value and nothing checked it.
     * The engine's constant was `private`, so PHP could not name it here, and
     * this line spelled `64 * 1024 * 1024` as its own literal — as did the
     * other two framers, all three under doc-blocks claiming an inheritance
     * that existed only in the comments. Round 58 corrected that sentence in
     * {@see \SugarCraft\Crush\LSP\LspConnection} and
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer} and left this file alone,
     * because it sat outside that lane's file list; for one round the third
     * member of the family was the only one still overstating.
     *
     * WHAT IS TRUE NOW: the engine's constant is `public`, this initialiser
     * references it, and the family cannot disagree at all.
     *
     * WHY A TEST STILL EARNS ITS PLACE: its job changed rather than ended.
     * {@see \SugarCraft\Crush\Tests\FrameCapFamilyTest} no longer compares
     * literals that happen to match — it pins that every member DERIVES, over a
     * roster read off the `MAX_FRAME_BYTES` declarations in `src/`. A FOURTH
     * framer that copies this doc-block and spells the arithmetic is reported.
     *
     * WHAT THIS SAID FOR ONE ROUND: that such a framer was "the one way the
     * family can still come apart". WHAT IS TRUE: that sentence was written
     * into three files at once and was wrong in all three. The roster's own
     * scanner could not read four of PHP 8.3's five `const` spellings — the
     * typed one this tree already uses elsewhere among them — so a framer
     * written that way left the family SILENTLY, and the whole suite came out
     * rc 0 with one in place. WHY IT STILL EARNS ITS PLACE, REPHRASED: a
     * copied literal is the way the family comes apart THAT ANYONE WOULD
     * WRITE ON PURPOSE, and it is caught; the scanner's own blind spots are
     * now held by a second instrument that decides membership without parsing
     * anything, in
     * {@see \SugarCraft\Crush\Tests\FrameCapFamilyTest::testEveryFileDeclaringTheCapReachesTheRoster()}.
     *
     * ⚠️ AND A WORD ABOUT DORMANCY, BECAUSE THE OBVIOUS INFERENCE IS BACKWARDS.
     * WHAT AN EARLIER DRAFT OF THIS PARAGRAPH SAID: that nothing calls this
     * client, so no test exercises its cap end to end, and the derivation is
     * what kept a number in the then-dormant file from going stale unobserved.
     *
     * WHAT IS TRUE: the first half was right and the second is inverted. This
     * class had no call site in `src/` until E699 — the class doc-block now
     * explains the gated single door — but its framing path IS exercised end
     * to end either way.
     * {@see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest} drives the real
     * {@see readMessages()} against a real child process at `cap` and at
     * `cap + 1`, so those rows cover the CALL SITE as well as the check, unlike
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer}'s equivalent rows, which
     * reach a private method by reflection and deliberately do not kill a
     * mutation of the line that calls it.
     *
     * ⚠️ AND NOT "THE BEST COVERED OF THE THREE", WHICH IS WHAT AN EARLIER
     * DRAFT OF THIS SENTENCE CLAIMED. Its evidence was that scope note, and
     * the note compares this class with `StdioMcpServer` only — two of the
     * three. MEASURED against the third:
     * {@see \SugarCraft\Crush\Tests\LSP\LspConnectionFrameCapTest} carries
     * seven test methods to this class's two, drives a real child of its own,
     * and adds a dormancy pin this class has no counterpart for. A superlative
     * needs the whole population; this one was read off a pairwise comparison,
     * which is the same mistake one level down from the one the paragraph
     * above corrects.
     *
     * WHY THE POINT SURVIVES THE CORRECTION: product dormancy and test coverage
     * are separate facts, and it is the FIRST that makes copying a number here
     * dangerous. Nobody editing a caller will ever be led to this file, so a
     * literal that drifted would be found by whoever next read the class for
     * unrelated reasons. Naming the engine's constant removes the possibility
     * rather than relying on somebody looking.
     *
     * ⚠️ EXCEEDING IT IS A NAMED FAILURE, NOT A TRUNCATION. Cutting the buffer at
     * the cap would hand `McpMessage::parse()` half a line, which comes back as
     * a malformed message and blames the SERVER for what is in fact this side
     * refusing to hold more. The buffer is dropped and a `RuntimeException`
     * naming the cap is raised instead.
     */
    private const MAX_FRAME_BYTES = EngineBackend::MAX_FRAME_BYTES;

    /**
     * The bound on the HANDSHAKE legs — `initialize` and `tools/list` — and on
     * nothing else (audit MCP-3).
     *
     * WHAT IT REPLACED: a counted poll, 100 attempts 10 ms apart, shared by
     * every exchange including `tools/call`. That is a TOTAL budget of about
     * one second, and MEASURED against server-everything
     * (`trigger-long-running-operation`, duration 3) the call threw
     * "No response received for request 3" after 1.01s while the server was
     * still doing the work; the reply then arrived to nobody. A `claude mcp
     * serve` that takes over a second to boot failed `tools/list` the same way,
     * and the launch skipped it silently.
     *
     * DERIVED, not restated: the stdio sibling's start ceiling, which its own
     * doc-block sizes for a cold `npx`-style boot. A child that is slow to
     * answer its handshake is the same question on both transports.
     *
     * `tools/call` has NO total deadline, on purpose and by the project's own
     * rule: a tool call is somebody's real work (a build, a Task sub-agent) and
     * minutes are legitimate. It ends when the answer arrives, when the child
     * closes its stdout, or when the child is gone — see {@see readFrame()}.
     */
    private const HANDSHAKE_TIMEOUT_SECONDS = \SugarCraft\Crush\MCP\StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS;

    /**
     * How long {@see readFrame()} parks in `stream_select()` before it looks at
     * the clock and the child again. A wakeup-resolution figure, not a budget:
     * stdout readiness ends the wait at once, so a prompt reply never pays it.
     */
    private const READ_POLL_MICROS = 250000;

    /**
     * The longest a read or write may go without asking whether the server
     * still runs, on passes the idle check never sees: stderr chatter wakes
     * {@see readFrame()}'s select every time, and a full stdin keeps
     * {@see writeAll()} spinning on its short write poll. Rate-limited rather
     * than per-pass so a chatty LIVE server costs no proc_get_status() per
     * log line. One read poll's worth, as in sugar-mcp's StdioMcpServer.
     */
    private const LIVENESS_CHECK_SECONDS = self::READ_POLL_MICROS / 1_000_000;

    /** Protocol version the `initialize` request advertises — the stdio sibling's. */
    private const PROTOCOL_VERSION = \SugarCraft\Mcp\StdioMcpServer::PROTOCOL_VERSION;

    /** The product identity the handshake names, the same one the stdio adapter sends. */
    private const CLIENT_INFO = ['name' => 'sugar-crush', 'version' => '1.0.0'];

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
     * Both arms poll for 1.8s. {@see callTool()} and {@see listTools()} no
     * longer poll at all — {@see readFrame()} waits on `stream_select()` and
     * takes one line at a time out of this same buffer — but the buffer is
     * still what lets a split reply survive between reads. A stdio server has no
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
     * either: MEASURED, three consecutive takes, a 200092-byte message against a
     * 65536-byte pipe capacity leaves 65536 bytes in the pipe, and the NEXT
     * message's bytes are appended to them, so the child reads ONE 65578-byte
     * line that is unparseable and BOTH messages are lost. See
     * {@see sendMessage()} for the exact envelope those two figures come from,
     * and for the earlier reading of 200068 that its stated generator does not
     * produce.
     *
     * So the next send leads with a bare newline. That costs the child one
     * malformed line it was going to get anyway, and buys back the message that
     * would otherwise have been eaten closing the fragment.
     */
    private bool $stdinFragmentPending = false;

    /**
     * FORK SAFETY (audit B1 / AG-1) — the same law as the library stdio
     * transport ({@see \SugarCraft\Mcp\StdioMcpServer}'s FORK SAFETY note).
     * The `claude-mcp` server is started in the TUI parent and called from
     * forked turn and sub-agent processes, each holding copies of these pipes.
     * Ids are unique across processes ({@see $ids}); every {@see callTool()} /
     * {@see listTools()} exchange runs under {@see $lock}, which also carries
     * the unread stdout bytes and the W/R phase marker a killed holder leaves,
     * so concurrent calls from parallel agents SERIALISE on one server instead
     * of reading each other's replies. Only {@see $ownerPid} tears it down.
     */
    private readonly RequestIdSequence $ids;

    /** Null until {@see connect()} — and for a test-injected connection, which runs unlocked. */
    private ?ExchangeLock $lock = null;

    /** The pid that called {@see connect()}; 0 = not connected by this object. */
    private int $ownerPid = 0;

    /** The child's pid, for liveness probes from processes that are not its parent. */
    private int $serverPid = 0;

    /**
     * The child's /proc start time captured at {@see connect()} (0 where
     * procfs is absent), so a forked caller can tell the server from a later
     * process that reused its pid after the owner reaped it.
     */
    private int $serverStartTicks = 0;

    /** Seconds {@see connect()} and {@see listTools()} may each wait; see {@see HANDSHAKE_TIMEOUT_SECONDS}. */
    private readonly float $handshakeTimeoutSeconds;

    /**
     * @param array<string, mixed>|null $initialOptions extra `initialize`
     *        params, merged over the defaults (protocolVersion, capabilities,
     *        clientInfo)
     * @param array<string, string> $env E699: overrides merged onto the
     *        inherited environment at spawn, routed through
     *        {@see \SugarCraft\Crush\Support\ProcessContainment::env()} so a
     *        contained spawn sees them too. Empty for every pre-E699 caller.
     * @param float|null $handshakeTimeoutSeconds handshake/tools-list budget;
     *        null or a non-positive value takes
     *        {@see HANDSHAKE_TIMEOUT_SECONDS}, so a bad setting cannot turn the
     *        bound off. It never bounds a `tools/call`.
     */
    public function __construct(
        public readonly ?string $command = null,
        public readonly array $args = [],
        public readonly ?array $initialOptions = null,
        private mixed $process = null,
        /** @var array<int, resource>|null */
        private ?array $pipes = null,
        private bool $connected = false,
        private int $requestId = 0,
        public readonly array $env = [],
        ?float $handshakeTimeoutSeconds = null,
    ) {
        // Owner ids continue the historical `++$requestId` sequence (1, 2, …).
        $this->ids = new RequestIdSequence(firstOwnerId: $requestId + 1);
        $this->handshakeTimeoutSeconds = $handshakeTimeoutSeconds !== null && $handshakeTimeoutSeconds > 0.0
            ? $handshakeTimeoutSeconds
            : self::HANDSHAKE_TIMEOUT_SECONDS;
    }

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
        //
        // E699: this PATH-searching branch is a TEST-DOUBLE convenience and
        // NOT trust on a wiring path — the `claude-mcp` factory arm refuses
        // a bare grant before this class ever sees one, so a production
        // spawn reaches here only with the operator's absolute path.
        if (self::resolveExecutable($command) === null) {
            throw new RuntimeException("Failed to spawn MCP process: " . basename($command));
        }

        // stdio transport — Claude Code MCP speaks JSON-RPC over stdin/stdout.
        //
        // E699/E672: the same choke point StdioMcpServer learned —
        // ProcessContainment::spawnSpec() fronts the argv with `setsid`
        // where available, so {@see disconnect()} can group-kill the
        // process TREE, and ProcessContainment::env() is the single place
        // the spawn environment is composed. `@` for the same reason
        // StdioMcpServer::start() gives it: an unresolvable program under
        // the wrapper would put a PHP warning over the TUI on a path that
        // already reports itself properly below.
        $lock = ExchangeLock::new('claude-mcp');

        /** @var array{0: resource, 1: resource, 2: resource} */
        $processHandles = @proc_open(
            ProcessContainment::spawnSpec(array_merge([$command], $args)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ProcessContainment::env($this->env),
        );

        if (!is_resource($processHandles)) {
            $lock->destroy();

            throw new RuntimeException("Failed to spawn MCP process: " . basename($command));
        }

        $this->lock = $lock;
        $this->ownerPid = (int) getmypid();
        $this->serverPid = (int) proc_get_status($processHandles)['pid'];
        $this->serverStartTicks = self::procStat($this->serverPid)['startTicks'] ?? 0;
        $this->ids->claim();
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

        // THE SPEC'S HANDSHAKE, IN ORDER (audit MCP-3): `initialize` is a
        // REQUEST, the client WAITS for its result, and only then announces
        // `notifications/initialized`. This used to send `initialize` as a
        // notification — no id, so nothing could answer it — with
        // `capabilities: {tools: true, resources: null}` (server-side fields,
        // and a null where the schema wants an object), never sent
        // `notifications/initialized`, and worked only because the TS SDK does
        // not enforce initialization. A server that does would refuse every
        // later request.
        $initId = (string) $this->ids->next();
        $initialize = McpMessage::request($initId, 'initialize', $this->initializeParams($options));

        try {
            $response = $this->exchange(
                $initialize,
                self::nowSeconds() + $this->handshakeTimeoutSeconds,
                "No response received for initialize request {$initId}",
            );

            // An ERROR answer is a refusal, not a start: the server will not
            // serve a session it never initialized.
            if ($response->error !== null || !$response->resultSet) {
                throw new RuntimeException(sprintf(
                    'MCP server refused initialize: %s',
                    $response->errorMessage() ?? 'the answer carried no result',
                ));
            }

            $this->notify(McpMessage::notification('notifications/initialized'));
        } catch (\Throwable $failure) {
            // A half-started child must not outlive the failure on its pipes:
            // reap it now rather than whenever this object happens to die.
            $this->disconnect();

            throw $failure;
        }

        return [$response];
    }

    /**
     * The `initialize` params: the spec's three required fields, with the
     * caller's options laid over them.
     *
     * `capabilities` is a JSON OBJECT in the schema and PHP's `[]` encodes as
     * an array, which the SDK servers reject ("expected object, received
     * array") — the same defect class audit MCP-1 closed for `arguments`.
     *
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    private function initializeParams(?array $options): array
    {
        $params = array_replace(
            [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => self::CLIENT_INFO,
            ],
            $this->initialOptions ?? [],
            $options ?? [],
        );

        if (!isset($params['capabilities']) || $params['capabilities'] === []) {
            $params['capabilities'] = new \stdClass();
        }

        return $params;
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

        $id = (string) $this->ids->next();
        // `arguments` is a JSON object in the schema; PHP's `[]` encodes as an
        // array, which the SDK servers reject ("expected record, received
        // array") — so an omitted or empty map must leave as `{}`.
        $request = McpMessage::request($id, 'tools/call', [
            'name' => $name,
            'arguments' => $params === null || $params === [] ? new \stdClass() : $params,
        ]);

        // NO TOTAL DEADLINE ON THE READ (audit MCP-3). It was a counted poll
        // worth about one second, so every tool that worked for longer — Bash,
        // Grep, Task — failed with its answer still on the way. The wait now
        // ends on the answer, on EOF, or on the child's death; the only clock
        // left is the write's {@see WRITE_IDLE_SECONDS} = 15.0; an IDLE bound
        // that a child still taking bytes never pays. A server that reads the
        // request and then never answers holds this call (and the exchange
        // lock) for as long as it lives — the stdio sibling's `callTool()`
        // makes the same choice for the same reason.
        return $this->exchange($request, null, "No response received for request {$id}");
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

        $id = (string) $this->ids->next();
        $request = McpMessage::request($id, 'tools/list', null);

        // Bounded, unlike a tool call: this is the start-time leg, and a
        // server that cannot list its tools in a slow-boot budget is not up.
        return $this->exchange(
            $request,
            self::nowSeconds() + $this->handshakeTimeoutSeconds,
            'No response received for tools/list request',
        );
    }

    /**
     * Send $request and wait for the response carrying its id, as ONE exchange
     * under the cross-process lock (see {@see $ids}).
     *
     * The wait is {@see readFrame()}'s: until `$deadline` (hrtime seconds; null
     * = none, the tool-call policy), EOF, or the child's death — never a
     * counted number of polls.
     *
     * STRICT MATCH, so a LATE reply cannot answer a later request: only a
     * genuine response (no `method`) whose id is byte-equal to ours is taken.
     * The reply to an earlier call that gave up — or whose process was
     * SIGKILLed — carries that call's id, which {@see RequestIdSequence} never
     * reissues, and is skipped like any other stranger's line. So is a request
     * echoed back at us, which carries our id AND a method.
     *
     * One unparseable shape is NOT skipped: an envelope with our id and neither
     * `result` nor `error`. That is the server's answer and it is broken; with
     * no deadline on a tool call, skipping it would wait for a reply that
     * already came.
     *
     * @throws RuntimeException when the write fails, the server is gone, or no
     *         response arrives before the deadline
     */
    private function exchange(McpMessage $request, ?float $deadline, string $failureMessage): McpMessage
    {
        $id = (string) $request->id;

        return $this->underLock($deadline, true, function () use ($request, $id, $deadline, $failureMessage): McpMessage {
            $this->sendMessage($request);

            // Same law as the W mark in underLock(): an unrecorded R would let
            // the next holder trust a stdout this one may die half-way into.
            if (!($this->lock?->markPhase(ExchangeLock::PHASE_READING) ?? true)) {
                throw new RuntimeException(
                    "{$failureMessage}: the exchange state could not be recorded in the lock file",
                );
            }

            while (true) {
                try {
                    $line = $this->readFrame($deadline);
                } catch (RuntimeException $ended) {
                    throw new RuntimeException("{$failureMessage}: {$ended->getMessage()}", 0, $ended);
                }

                $message = McpMessage::parse($line);
                if ($message === null) {
                    if (self::isMalformedReplyTo($line, $id)) {
                        throw new RuntimeException(
                            "{$failureMessage}: the server answered with neither a result nor an error",
                        );
                    }

                    continue;
                }

                if ($message->isResponse() && $message->id === $id) {
                    return $message;
                }
            }
        });
    }

    /**
     * Send a NOTIFICATION as its own exchange: nothing is read, but the write
     * still has to be serialised against every other process's request line.
     */
    private function notify(McpMessage $notification): void
    {
        $this->underLock(null, false, function () use ($notification): bool {
            $this->sendMessage($notification);

            return true;
        });
    }

    /**
     * Run $body as one exchange under {@see $lock} (unlocked for a test-injected
     * connection). The shared state is loaded at the start and written back at
     * the end. A holder that died mid-exchange left W (its request line may be
     * half written: this send leads with a newline, the
     * {@see $stdinFragmentPending} mechanism) or R (stdout may start mid-line:
     * its buffer is dropped, and the reader skips the unparseable fragment). A
     * failed exchange leaves its own marker the same way.
     *
     * @template T
     * @param \Closure(): T $body
     * @return T
     */
    private function underLock(?float $deadline, bool $reads, \Closure $body): mixed
    {
        $lock = $this->lock;

        if ($lock !== null && !$lock->acquire($deadline, fn (): bool => $this->serverIsRunning())) {
            throw new RuntimeException(
                $deadline !== null && self::nowSeconds() >= $deadline
                    ? 'MCP server exchange lock was not free before the deadline'
                    : 'MCP server is not running',
            );
        }

        $completed = false;
        $dirty = false;

        try {
            if ($lock !== null) {
                [$phase, $buffer] = $lock->load();
                $dirty = $phase !== ExchangeLock::PHASE_CLEAN;
                $this->readBuffer = $dirty ? '' : $buffer;
                $this->stdinFragmentPending = $phase === ExchangeLock::PHASE_WRITING;

                // Unrecorded, a W marker cannot tell the next holder that this
                // one died mid-line — running unprotected is the defect the
                // marker exists to close, so a failed mark (a full or
                // read-only temp filesystem) fails the exchange before a byte
                // goes out. Same rule as sugar-mcp StdioMcpServer::exchange().
                if (!$lock->markPhase(ExchangeLock::PHASE_WRITING)) {
                    throw new RuntimeException(
                        'MCP server exchange state could not be recorded in the lock file; nothing was sent',
                    );
                }
            }

            $result = $body();
            $completed = true;

            return $result;
        } finally {
            if ($lock !== null) {
                if (!$completed) {
                    [$reached] = $lock->load();
                    $lock->store($reached === ExchangeLock::PHASE_CLEAN ? ExchangeLock::PHASE_READING : $reached, '');
                } elseif ($dirty && !$reads) {
                    // Our line went out whole, so stdin is clean again — but
                    // stdout may still start mid-line and nothing has read it
                    // back to a boundary: hand the recovery to the next reader.
                    $lock->store(ExchangeLock::PHASE_READING, '');
                } else {
                    $lock->store(ExchangeLock::PHASE_CLEAN, $this->readBuffer);
                }

                // Both live in the lock file now; a private copy would go stale
                // the moment another process takes the next exchange.
                $this->readBuffer = '';
                $this->stdinFragmentPending = false;
                $lock->release();
            }
        }
    }

    /**
     * Return the next newline-terminated line from the child's stdout, waiting
     * for it on `stream_select()` rather than on a counted poll.
     *
     * The wait ends in exactly three ways besides a line arriving, each a
     * RuntimeException naming which: `$deadline` passed (hrtime seconds; null
     * means no deadline), the child closed its stdout, or the child is gone.
     * Bytes past the line stay in {@see $readBuffer}, so a reply that shares a
     * read with the next line loses nothing.
     *
     * STDERR IS DRAINED ON EVERY PASS, and is in the select set until its EOF,
     * so a child that logs while it works wakes this loop instead of wedging in
     * `write(2)` — see {@see drainStderr()}. Leaving a CLOSED stderr in the set
     * would make it permanently readable and turn the wait into a spin, hence
     * the `feof()` test.
     *
     * @throws RuntimeException
     */
    private function readFrame(?float $deadline): string
    {
        $scannedFrom = 0;
        $consecutiveSelectFailures = 0;
        $livenessCheckedAt = self::nowSeconds();

        while (($newline = strpos($this->readBuffer, "\n", $scannedFrom)) === false) {
            // Bytes already searched for "\n" are not searched again, so a huge
            // frame accumulating towards the cap costs O(n), not O(n²).
            $scannedFrom = strlen($this->readBuffer);
            $pipes = $this->getPipes();

            if (!is_resource($pipes[1])) {
                throw new RuntimeException('the server\'s stdout is closed');
            }

            $slice = self::READ_POLL_MICROS;
            if ($deadline !== null) {
                $remaining = $deadline - self::nowSeconds();
                if ($remaining <= 0.0) {
                    throw new RuntimeException(sprintf(
                        'no answer within the %.1fs handshake budget',
                        $this->handshakeTimeoutSeconds,
                    ));
                }
                $slice = min($slice, max(1, (int) ceil($remaining * 1_000_000)));
            }

            $read = [$pipes[1]];
            if (isset($pipes[2]) && is_resource($pipes[2]) && !feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            $write = [];
            $except = [];

            // `@` for EINTR: a signal mid-select is a retry, and under
            // `failOnWarning="true"` the warning alone would red a passing run.
            $ready = @stream_select($read, $write, $except, 0, $slice);
            $this->drainStderr();

            if ($ready === false) {
                $consecutiveSelectFailures++;
                if (!$this->serverIsRunning()) {
                    throw new RuntimeException('the server is not running');
                }
                if ($consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    throw new RuntimeException('stream_select() kept failing on the server\'s stdout');
                }
                usleep(1000);

                continue;
            }
            $consecutiveSelectFailures = 0;

            $held = strlen($this->readBuffer);
            $atEof = $this->fillReadBuffer();
            if (strpos($this->readBuffer, "\n", $scannedFrom) !== false) {
                continue;
            }

            if ($atEof) {
                throw new RuntimeException('the server closed its stdout');
            }

            if (strlen($this->readBuffer) > $held) {
                // stdout made progress: the server (or what speaks for it) is
                // talking, so this pass is no reason to probe.
                $livenessCheckedAt = self::nowSeconds();

                continue;
            }

            // stdout gave nothing this pass. A timed-out select is checked at
            // once; a pass woken only by STDERR is checked too, at most once per
            // LIVENESS_CHECK_SECONDS — a helper the server forked inherits
            // stderr as well as stdout, and one that logs more often than a
            // poll never lets the select time out, so an idle-only check would
            // never run and a deadline-less tool call would wait out the helper.
            if ($ready !== 0 && self::nowSeconds() - $livenessCheckedAt < self::LIVENESS_CHECK_SECONDS) {
                continue;
            }

            // The child gone: one last read for anything it wrote on the way
            // out, then give up rather than wait for an EOF a surviving
            // grandchild holding the pipe would never deliver.
            if (!$this->serverIsRunning()) {
                $this->fillReadBuffer();
                if (strpos($this->readBuffer, "\n", $scannedFrom) !== false) {
                    continue;
                }

                throw new RuntimeException('the server exited');
            }

            $livenessCheckedAt = self::nowSeconds();
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = (string) substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    /**
     * True when $line, which McpMessage::parse() refused, is still a JSON-RPC
     * 2.0 response envelope addressed to $id (see {@see exchange()}). The id is
     * coerced the way parse() coerces it, so an integer id matches its string.
     * Same rule as {@see \SugarCraft\Mcp\StdioMcpServer}'s twin.
     */
    private static function isMalformedReplyTo(string $line, string $id): bool
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || ($decoded['jsonrpc'] ?? null) !== '2.0') {
            return false;
        }

        if (isset($decoded['method']) || !isset($decoded['id'])) {
            return false;
        }

        $wireId = $decoded['id'];

        return (is_string($wireId) || is_int($wireId)) && (string) $wireId === $id;
    }

    /** Monotonic seconds: an NTP step mid-handshake must neither void the bound nor fire it early. */
    private static function nowSeconds(): float
    {
        return hrtime(true) / 1_000_000_000.0;
    }

    /**
     * Liveness from any process: the owner asks proc_get_status(); a forked
     * child cannot (waitpid() fails with ECHILD and PHP reports "not running"),
     * so it probes the pid captured at connect with signal 0, or assumes up
     * without ext-posix and lets the pipes report a dead server.
     *
     * Signal 0 alone is not enough there: a server that died while the owner
     * is busy elsewhere (the TUI parent blocks in waitpid() on the turn, never
     * in proc_get_status()) stays an unreaped ZOMBIE, and kill(zombie, 0)
     * succeeds — so a forked turn would wait out a helper still holding the
     * pipes. Where /proc exists the probe therefore also reads the pid's state
     * and treats Z/X as dead, and a start time that no longer matches the one
     * recorded at connect() as a reused pid, i.e. dead too. Without /proc the
     * signal-0 answer stands. The same probe as sugar-mcp's StdioMcpServer.
     */
    private function serverIsRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        if ($this->ownerPid === 0 || $this->ownerPid === (int) getmypid()) {
            return self::childIsRunning($this->process);
        }

        if ($this->serverPid <= 0 || !function_exists('posix_kill')) {
            return true;
        }

        if (!posix_kill($this->serverPid, 0)) {
            return false;
        }

        $stat = self::procStat($this->serverPid);
        if ($stat === null) {
            // No procfs here, or the entry went between the signal and the
            // read: signal 0 said "exists", and a pid that is truly gone fails
            // that probe on the next poll.
            return true;
        }

        if ($stat['state'] === 'Z' || $stat['state'] === 'X' || $stat['state'] === 'x') {
            return false;
        }

        return $this->serverStartTicks === 0 || $stat['startTicks'] === $this->serverStartTicks;
    }

    /**
     * The state letter and start time (clock ticks since boot) of $pid from
     * /proc/<pid>/stat, or null where procfs is absent or unreadable. The
     * process name (field 2) may itself contain spaces and parentheses, so the
     * fields are split after its LAST ')'.
     *
     * @return array{state: string, startTicks: int}|null
     */
    private static function procStat(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }

        $path = "/proc/{$pid}/stat";
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $close = is_string($raw) ? strrpos($raw, ')') : false;
        if (!is_string($raw) || $close === false) {
            return null;
        }

        // Field 3 (state) onwards; starttime is field 22, index 19 here.
        $fields = preg_split('/\s+/', trim(substr($raw, $close + 1)));
        if (!is_array($fields) || count($fields) < 20 || $fields[0] === '' || !ctype_digit($fields[19])) {
            return null;
        }

        return ['state' => $fields[0], 'startTicks' => (int) $fields[19]];
    }

    /**
     * Send a raw message, DRAINING STDERR AS IT GOES, and either write all of it
     * or say so.
     *
     * THIS WAS A SINGLE `fwrite()` CHECKED WITH `!==  strlen()`, and that is the
     * third member of the family {@see \SugarCraft\Mcp\StdioMcpServer::writeLine()}
     * and {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()} closed
     * before it. The symptom here is different from both, because {@see connect()}
     * puts fd 0 in NON-BLOCKING mode: there was no hang. There was a SHORT WRITE
     * reported as a failure with the prefix already in the child's pipe.
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes,
     * identical — a `tools/call` carrying 200000 bytes of arguments:
     *
     *     payload 200092 bytes  ->  fwrite() returned 65536 (the pipe capacity)
     *     the child then read ONE 65578-byte line, unparseable
     *
     * 65578, not 65536, is the whole finding: the next message this client sent
     * supplied the newline that terminated the fragment, so it was consumed INTO
     * the malformed line and lost with it. One short write costs two messages.
     *
     * ⚠️ THE PAYLOAD FIGURE, CORRECTED, AND WHY IT IS WORTH A PARAGRAPH.
     * WHAT THIS SAID: "payload 200068 bytes" for that generator.
     * WHAT IS TRUE NOW: 200068 is reproducible only from a hand-built envelope
     * with a bare `params` key. Followed through THIS codebase's own factory —
     * the shape this file's probe builds, `McpMessage::request('1', 'tools/call',
     * ['name' => 'x', 'arguments' => ['t' => str_repeat('x', 200000)]])` — the
     * JSON is 200092 bytes, and the payload `sendMessage()` pushes is 200093 with
     * its newline. PHP 8.3.6, three consecutive takes, identical; the probe
     * prints the same quantity as `PAYLOAD:` every run.
     * WHY IT MATTERS THAT IT WAS WRONG: nothing about the finding turns on the
     * number - anything past 65536 short-writes - but a figure whose stated
     * generator does not produce it cannot be checked by the next reader, and
     * checking it is the only thing the generator is for.
     *
     * THE SECOND TERM OF 65578 IS THE NEXT MESSAGE, and it is 42 bytes: 65536
     * bytes of truncated fragment plus the following request's JSON, whose own
     * newline terminates the joined line. `McpMessage::request('1', 'ping', null)
     * ->toJson()` is exactly 42 bytes on this host, which is how that half
     * re-derives. This is a PRE-FIX reading: the probe as it stands sends no
     * second message, so the suite does not reproduce 65578 and is not trying to
     * - the two-arm rows pin the BEHAVIOUR, and this paragraph is the archaeology.
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
     *  b. THE BOUND IS IDLE, NOT TOTAL, AND MEASURING IT SHOWED IT IS A
     *     DEAF-CHILD BOUND RATHER THAN A LARGE-MESSAGE ONE: ten mebibytes to a
     *     reading child costs 0.03s, sixty-five kilobytes to a deaf one costs
     *     the full 15.006s. Three takes each; see {@see WRITE_IDLE_SECONDS}. The
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
        $livenessCheckedAt = self::nowSeconds();

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

                if (!$this->serverIsRunning()
                    || $consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    return $total - strlen($payload);
                }

                usleep(1000);

                continue;
            }

            $consecutiveSelectFailures = 0;

            if ($ready === 0 || $write === []) {
                // stdin stayed unwritable for this pass. A helper the server
                // forked inherits stdin, so the pipe never breaks when the
                // server dies; without this the write waits out the whole
                // idle bound against nobody. Rate-limited like readFrame()'s.
                if (self::nowSeconds() - $livenessCheckedAt >= self::LIVENESS_CHECK_SECONDS) {
                    if (!$this->serverIsRunning()) {
                        return $total - strlen($payload);
                    }
                    $livenessCheckedAt = self::nowSeconds();
                }

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
     * `write(2)`, and a caller polls this method between waits, so crossing
     * a poll boundary is the ordinary case rather than the corner.
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

        $this->fillReadBuffer();

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
     * Move whatever the child's stdout holds RIGHT NOW into {@see $readBuffer},
     * without blocking, enforcing {@see MAX_FRAME_BYTES}. Shared by
     * {@see readMessages()} and {@see readFrame()} so the cap has one home.
     *
     * @return bool true when stdout has reached EOF (the child closed it)
     * @throws RuntimeException past the frame cap — the buffer is dropped
     */
    private function fillReadBuffer(): bool
    {
        $pipes = $this->getPipes();

        if (!is_resource($pipes[1])) {
            return true;
        }

        while (true) {
            $chunk = fread($pipes[1], self::READ_CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->readBuffer .= $chunk;

            // INSIDE the loop, not after it. A peer that never stops writing
            // never lets this loop end on its own, so a check placed after it
            // would be a cap that is only consulted once the stream is already
            // finished — which is the one case it is not needed in.
            if (strlen($this->readBuffer) > self::MAX_FRAME_BYTES) {
                $held = strlen($this->readBuffer);
                $this->readBuffer = '';

                throw new RuntimeException(sprintf(
                    'the MCP server sent %d bytes with no newline, past this client\'s '
                    . '%d-byte frame cap; the buffer was dropped rather than truncated, '
                    . 'because half a line parses as a malformed message and would blame '
                    . 'the server for this side\'s refusal',
                    $held,
                    self::MAX_FRAME_BYTES,
                ));
            }
        }

        return feof($pipes[1]);
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

        // A forked process holds COPIES of the pipes; the child belongs to the
        // process that connected, which may still be serving it (and siblings
        // may be mid-exchange). Close our copies and forget — no signal, no
        // reap, no lock-file unlink.
        if ($this->ownerPid !== 0 && $this->ownerPid !== (int) getmypid()) {
            foreach ($this->pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->lock?->close();
            $this->lock = null;
            $this->process = null;
            $this->serverPid = 0;
            $this->serverStartTicks = 0;
            $this->pipes = null;
            $this->connected = false;
            $this->readBuffer = '';
            $this->stdinFragmentPending = false;

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

        // E699/E673: the group id is read WHILE the child is alive, exactly
        // as StdioMcpServer::stop() does — this child is a CLI that spawns
        // grandchildren of its own, and a bare pid signal would orphan them
        // with the pipes already closed.
        ProcessReaper::terminateAndClose($this->process, ProcessContainment::groupId($this->process));
        $this->lock?->destroy();
        $this->lock = null;

        $this->process = null;
        $this->serverPid = 0;
        $this->serverStartTicks = 0;
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

    /**
     * E699: connected AND the child still running — the liveness question
     * {@see childIsRunning()} already answers inside the write loop, exposed
     * once for the `/mcp` panel's snapshot the way
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::isUp()} does for it.
     */
    public function isUp(): bool
    {
        return $this->connected && $this->serverIsRunning();
    }

    /**
     * E699: the bounded stderr drain as an on-demand seam, same contract as
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::pumpStderr()} — bounded
     * (16 passes inside {@see drainStderr()}), a no-op before connect() and
     * after disconnect(), never throws on a torn-down server.
     */
    public function pumpStderr(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->drainStderr();
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
     *
     * E699: this PATH branch is a TEST-DOUBLE convenience and NOTHING on the
     * gated wiring path relies on it —
     * {@see \SugarCraft\Crush\MCP\ClaudeCodeMcpServer::fromGrant()} refuses a
     * non-absolute `claudeMcpBinary` before a spawn, so $PATH never picks the
     * binary a run talks to.
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
