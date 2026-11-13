<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * An MCP server reached over a child process' stdin/stdout, speaking
 * newline-delimited JSON-RPC 2.0.
 *
 * Extracted from the candy-code MCP stack (src/MCP/StdioMcpServer.php); the
 * framing, handshake and lifecycle algorithms are faithful, with two
 * deliberate deltas documented below.
 *
 * DELTA — containment policy stays downstream, injected through a seam:
 * candy-code launches through ProcessContainment (setsid-wrapped detached
 * spawn, PATH pre-check, curated environment). That is product policy about
 * WHO may be spawned, not part of the wire protocol, so the library spawns
 * plainly by default — plain argv array, ambient environment unless overrides
 * are given — and embedders that need containment pass a $spawnPlanner
 * closure deciding the exact proc_open command and env per launch.
 *
 * TRUST BOUNDARY, read this before running a configured server: a stdio
 * server entry in a configuration file is arbitrary code execution AS THE
 * INVOKING USER. The default spawn hands the child the parent's FULL
 * environment (SSH agent sockets, cloud credentials, provider API keys and
 * everything else exported into the process); the env: map only ADDS or
 * OVERWRITES entries and can never remove one, and PATH is resolved by the OS
 * at exec time from that inherited environment without verification. Nothing
 * in this library vets the binary that answers. The README "Trust boundary"
 * section states the consequences for config-driven embedders; narrowing is
 * the spawn planner's job.
 *
 * DELTA — teardown goes through sugar-core's BoundedShutdown instead of the
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
 * WHY the start deadline is ONE monotonic clock across the whole handshake:
 * (hrtime, not wall clock — an NTP step mid-handshake must neither void the
 * bound nor fire it early), and ONE rather than per-read:
 * a server that streams notifications forever starves a per-read timeout —
 * every individual read arrives in time, so the budget never expires, and the
 * process hangs (product-measured, rc=124). One deadline checked at the top of
 * each leg bounds the exchange as a unit instead — and the thread is BY
 * REFERENCE across the handshake legs, so the handler-credit law holds for
 * the whole budget: wall clock spent answering the server mid-handshake is
 * added back onto the SAME instant the next leg resumes with, instead of a
 * by-value copy silently discarding the credit at the leg boundary.
 *
 * Timeout discipline: DEFAULT_START_TIMEOUT_SECONDS bounds the HANDSHAKE only.
 * callTool() carries NO deadline by default — a tool call is somebody's real
 * work (a build, a fetch) and minutes are legitimate; it is bounded by child
 * liveness rather than by a wall-clock kill of in-flight work. An embedder
 * that wants a bound OPTS IN per call (`$timeoutSeconds`, which candy-code
 * reads from a server's `toolTimeout`): the wait is abandoned at the deadline,
 * the server is told with `notifications/cancelled`, and the late reply is
 * skipped by the strict id match like any foreign one. That liveness bound is
 * ENFORCED, not inferred from pipe EOF: EOF needs every holder of the stdout
 * write end to close it, and a helper the server forked (or the real server
 * behind a dying wrapper) keeps it open after the direct child is gone. So
 * every read or write poll that leaves the pipe idle also asks whether the
 * direct child still runs — including a pass where only stderr was ready, since
 * a helper that inherited stderr and logs faster than a poll never lets the
 * select time out (rate-limited to once per READ_POLL_SECONDS) — and a read
 * with nothing more pending on a dead child ends the exchange. "Still runs"
 * holds from a forked caller too: there an unreaped dead server is a zombie
 * that signal 0 calls alive, so the probe reads its /proc state (see
 * serverIsRunning()).
 *
 * FORK SAFETY (the fork-corruption audit). An embedder may start a server in one process
 * and use the object from pcntl_fork()ed children — candy-code runs every turn
 * in a fork and parallel sub-agents in further forks. Every child inherits the
 * same pipes, so ONE server is shared by the whole process tree, and sharing
 * is made safe rather than avoided (re-spawning per process would relaunch an
 * `npx` server on every turn and throw away its server-side state — a browser
 * session, a memory store):
 *  - ids are unique across processes ({@see RequestIdSequence}): the owner
 *    keeps `0, 1, 2…`, any other pid sends `<pid>-<nonce>-<n>`, so the strict
 *    id match in readResponse() discards a reply meant for someone else —
 *    including the late reply of a call whose process was SIGKILLed;
 *  - each whole exchange (request write + response read, or a notification
 *    write) runs under a cross-process flock ({@see ExchangeLock}), so
 *    concurrent calls on one server from parallel agents are SERIALISED, one
 *    exchange at a time — the price of sharing one stdio stream;
 *  - stdout bytes read past a response live in the lock file, not in a
 *    process-private buffer, so the next exchange — in whichever process —
 *    starts at a line boundary;
 *  - a holder that dies mid-exchange leaves a W/R phase marker behind; the
 *    next holder terminates a half-written request line with a leading "\n"
 *    (the TS and Python SDK servers log the junk line and carry on), and
 *    the reader skips the half-consumed fragment like any unparseable line;
 *  - only the owner (the pid that called start()) ever tears the server down:
 *    stop()/__destruct in any other pid close that process' own handles and
 *    leave the shared server running.
 * stderr is NOT locked: it is diagnostics only, and the owner's pumpStderr()
 * may drain it while a child is mid-exchange — the tail may then be split
 * between processes, which loses nothing a protocol exchange depends on.
 *
 * SERVER REQUESTS. The protocol is bidirectional: a server may
 * send requests of its own (ping, roots/list, elicitation/create) and
 * notifications (notifications/progress) mid-call, on this same pipe.
 * readResponse() answers them inline instead of skipping them: a handler
 * (connection-wide, or per-call through callTool()/request()) sees every
 * method-bearing frame as ($method, $params, $isNotification); request
 * handlers return `['result' => ...]` or `['error' => ['code' => int,
 * 'message' => string, 'data' => mixed]]`, or null to fall back to the
 * default answer (ping is acknowledged, anything else refused "Method not
 * found"); a notification handler's return is ignored. Answers are written
 * under the SAME exchange lock that already serialises every exchange on
 * this connection — so an answerer that WAITS (an elicitation held open for
 * a human) holds that lock, and every other call to this server queues
 * behind it. That is the deliberate shape: one pipe, one conversation. An
 * answerer must therefore never call back into this server instance; it
 * answers from state it already holds. The wall clock a request handler
 * spends is PAUSED out of any bounded call's deadline — waiting for an
 * answer this client owes is not the server being slow (the no-blanket-timeout law).
 *
 * The failure vocabulary (one per door, named once here and consumed by the
 * `@return` annotations below): FailurePayload is callTool's array-or-error
 * shape shared by every convenience door — on success the server's result
 * envelope, on failure exactly `['error' => non-empty string]`, the
 * discriminator key checked first; RawRequestResult is request()'s reply
 * envelope-or-null — null means no usable reply (a named cause may sit in
 * lastFailureCause) while a JSON-RPC error is a non-null message with
 * ->error set, NOT null; NotifyAck is notify()'s delivery report — false
 * means not on the wire, never "answered". The typedefs exist so a
 * misapplied branch stops compiling silently; see README *Failure shapes*.
 *
 * @phpstan-type FailurePayload array<string,mixed>
 * @phpstan-type RawRequestResult McpMessage|null
 * @phpstan-type NotifyAck bool
 *
 */
final class StdioMcpServer implements McpServer
{
    /**
     * Speaks MCP 2024-11-05 (https://modelcontextprotocol.io/specification/2024-11-05):
     * advertised in the initialize handshake; a server negotiating a different
     * revision is refused, a legacy server omitting the field is allowed.
     */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Monotonic ceiling for the whole handshake (initialize → notifications/initialized →
     * tools/list). Sized for cold package fetches: an `npx`-style launcher
     * downloading its server takes 30–60s on first run, and failing that boot
     * faster is not failing better. Bounds the handshake ONLY — never a tool
     * call. Embedders with local servers pass a tighter startTimeoutSeconds.
     */
    public const DEFAULT_START_TIMEOUT_SECONDS = 60.0;

    /**
     * Page ceiling for the `tools/list` cursor walk, mirroring
     * candy-code's HttpMcpServer. A wall for pathological servers, not a
     * working budget: 32 pages times a typical page of a hundred tools is
     * three thousand entries, far past any real server. The guard that
     * actually catches a lying server is the repeated-cursor refusal; the
     * shared handshake deadline stops a silent one; this one catches the
     * adversarial shape of a server answering FAST with a fresh cursor
     * forever, which would otherwise eat the whole start budget walking
     * before refusing anything.
     */
    private const MAX_TOOL_PAGES = 32;

    /**
     * Tail budget for captured stderr. The cap exists so a server flooding
     * stderr cannot grow our memory without bound; keeping the TAIL (not the
     * head) is the point — the lines nearest the failure are the ones worth
     * reading.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * Ceiling on the read buffer: it bounds every byte held between newline
     * discovery and frame consumption (the whole pending buffer, not just one
     * unread line). A stream with no newline for 64MiB is
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

    /** @var resource|null the proc_open handle */
    private $process = null;

    /** @var array<int,resource>|null */
    private ?array $pipes = null;

    /**
     * Request id source; ids travel as strings — decimal for the owner,
     * pid-tagged for forked processes (see the FORK SAFETY note above).
     */
    private readonly RequestIdSequence $ids;

    /**
     * Bytes read from stdout but not yet consumed. Only meaningful INSIDE an
     * exchange: it is loaded from, and written back to, the {@see ExchangeLock}
     * file so it is shared by every process using this connection.
     */
    private string $readBuffer = '';

    /** Exclusion + shared state for exchanges; created by start(). */
    private ?ExchangeLock $lock = null;

    /** The pid that called start() — the only one allowed to tear down. */
    private int $ownerPid = 0;

    /** The server's pid, for liveness checks from processes that are not its parent. */
    private int $serverPid = 0;

    /**
     * The server's /proc start time captured at start() (0 where procfs is
     * absent), so a forked caller can tell the server from a later process
     * that reused its pid after the owner reaped it.
     */
    private int $serverStartTicks = 0;

    /** Tail-bounded capture of everything the server wrote to stderr. */
    private string $stderrTail = '';

    private bool $stderrOpen = false;

    /**
     * True once the stderr capture actually dropped bytes at the cap — the
     * honest truncation flag (the tail's LENGTH cannot tell an exactly-full
     * capture from a trimmed one). Cleared at start(), never at stop(): like
     * the tail itself it is post-mortem evidence.
     */
    private bool $stderrTruncated = false;

    /**
     * Why the IN-FLIGHT exchange did not answer, set at the reader's give-up
     * sites (EOF, dead select, write refusal, lock loss, malformed reply,
     * refused frame, handler failure) and reset per call. It distinguishes a
     * timeout from a death that merely raced the deadline, and names the
     * cause inside the callTool error payload instead of collapsing every
     * failure into one string. Process-local like {@see $onWait} — never
     * shared across the fork.
     */
    private ?string $lastFailureCause = null;

    /**
     * Labels of the tools/list definitions this server advertised that the
     * well-typedness gate refused to build, during the CURRENT start. A
     * malformed definition is never registered — but it is SKIPped and
     * COUNTED, not fatal: one bad entry's blast radius stops at that entry,
     * its well-formed neighbours still boot, and this list carries the
     * announcement ({@see malformedToolDefinitionsReport()}) so the drops
     * are diagnosable instead of silent.
     *
     * Post-mortem evidence like the stderr tail: cleared at every start(),
     * deliberately kept by stop() so a failed launch's report survives.
     *
     * The list is a CAPPED view — the ledger never grows past
     * {@see self::MALFORMED_LEDGER_CEILING} entries (one hostile
     * `tools/list` must not turn the diagnostic into a memory DoS);
     * {@see $malformedToolDefsTotal} keeps the honest count of everything
     * dropped, and the report announces the overflow.
     *
     * @var list<string>
     */
    private array $malformedToolDefs = [];

    /**
     * Every drop recorded since the last start(), capped or not — the
     * denominator the report quotes so a truncated view can never
     * understate the server's sloppiness.
     */
    private int $malformedToolDefsTotal = 0;

    /**
     * The server's full InitializeResult, kept from the one handshake it will
     * ever perform: `serverInfo` is the identity of the connection, and
     * `capabilities` is what lets an embedder GATE behaviour (listChanged,
     * elicitation, resources/*, prompts/*) instead of guessing. Post-mortem
     * evidence like the stderr tail and the malformed ledger: cleared at every
     * start(), deliberately kept by stop(), so "which server was this, and
     * what did it advertise" stays answerable after teardown. Tolerant store:
     * the spec makes both members required, and an absent one is REPORTED
     * ({@see initializeResultDefects()}), not boot-fatal — the handshake has
     * already proven the server can negotiate and answer tools/list.
     *
     * @var array<string,mixed>|null
     */
    private ?array $initializeResult = null;

    /**
     * The identity this client advertises in the initialize handshake. The
     * version literal is only the FALLBACK: an installed copy announces its
     * real package version (see {@see defaultClientInfo()}); the literal
     * stays for the linked monorepo checkout, where this package is itself
     * the install root and no shipped version has been resolved.
     */
    private const DEFAULT_CLIENT_INFO = ['name' => 'sugar-mcp-client', 'version' => '0.1.0'];

    /**
     * The advertised identity with the SHIPPED package version when the
     * Composer runtime knows it (m16's fix: a frozen literal here keeps
     * announcing an outdated version silently, release after release, and
     * the handshake is the one place a server gets to know who is calling).
     * Only the untouched default is resolved — an explicitly
     * caller-supplied identity is never rewritten.
     *
     * @return array<string,string>
     */
    private static function defaultClientInfo(): array
    {
        return [
            'name' => self::DEFAULT_CLIENT_INFO['name'],
            'version' => self::resolvedClientVersion(),
        ];
    }

    /**
     * The version arm on its own: the SHIPPED package version when the
     * Composer runtime knows it, the literal fallback otherwise. Shared by
     * the untouched default and the `clientName:` knob, so an author who
     * only renames never carries a version at all — a hand-typed version on
     * the wire rots silently release after release, which is the very
     * disease the resolver above exists to cure.
     */
    private static function resolvedClientVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('sugarcraft/sugar-mcp-client')) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('sugarcraft/sugar-mcp-client');
            } catch (\OutOfBoundsException) {
                $version = null;
            }

            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return self::DEFAULT_CLIENT_INFO['version'];
    }

    /** @var array<string,mixed> parsed in the constructor, trusted afterwards */
    private readonly array $clientInfo;

    /**
     * Client capabilities advertised in the initialize handshake
     * (the server-request law). A configured map; sent as a JSON OBJECT
     * whatever it holds — the object-vs-array wire shape law survives
     * configuration. [] means "none" and still encodes as {}.
     *
     * @var array<string,mixed>
     */
    private readonly array $capabilities;

    private float $startTimeoutSeconds;

    /**
     * The caller's "still waiting" beat for the call in flight, or null.
     * Set by callTool() for the length of one call and cleared after it, so
     * it is never a standing property of the connection (and never crosses a
     * fork as live state — each process sets its own).
     */
    private ?\Closure $onWait = null;

    /**
     * Connection-wide answerer for the server's own requests and
     * notifications (the server-request law), or null to fall back to the built-in
     * defaults (answer ping, refuse the rest). Overridden per call by
     * {@see $callServerMessage}; see the class docblock for the contract
     * and the lock-hold discipline.
     */
    private readonly ?\Closure $onServerMessage;

    /**
     * The server-request handler for the exchange in flight, or null.
     * Set by callTool()/request() for the length of one exchange and
     * cleared after it — the same one-call lifetime law as {@see $onWait},
     * so it never crosses a fork as live state.
     */
    private ?\Closure $callServerMessage = null;

    /**
     * @param string $name    server identity, tagged onto every McpTool
     * @param string $command program to exec (argv[0])
     * @param list<string> $args argv after the program
     * @param array<string,string> $env extra environment; [] means inherit
     *        the FULL parent environment, and a non-empty map is ADDITIVE —
     *        entries override or extend the inherited copy but can never
     *        remove a variable. See the class docblock and the README "Trust
     *        boundary" section: a stdio server runs arbitrary code as the
     *        invoking user with every ambient secret unless a spawn planner
     *        narrows the environment.
     * @param float|null $startTimeoutSeconds handshake ceiling; null or <= 0
     *        falls back to DEFAULT_START_TIMEOUT_SECONDS so a bad setting can
     *        never disable the bound entirely
     * @param \Closure|null $spawnPlanner embedder seam deciding the spawn:
     *        called as ($name, $argv, $env) where $env is the map authored
     *        via {@see $env} (empty when none was passed — never the merged
     *        ambient environment), returning [command, env],
     *        where command is a non-empty argv list<string> (NEVER a shell
     *        string — the shell would be the direct child and stop() would
     *        orphan the server behind it; a wrapper that truly needs a shell
     *        spells it ['/bin/sh', '-c', ...]) and env is null|array<string,
     *        string>; any other shape is an InvalidArgumentException from
     *        start(). null spawns plainly. A planner may throw to refuse a launch
     *        before any child exists — that refusal is the product's PATH
     *        pre-check living downstream where it belongs.
     * @param array<string,mixed> $clientInfo initialize-handshake identity;
     *        must carry a non-empty string 'name' and a string 'version' —
     *        the default constant is the only shape that may stand in for an
     *        omission, a caller-supplied [] is a bug, not a reset. For an
     *        identity that equals the default except for one arm, author it
     *        piecewise with {@see $clientName}/{@see $clientVersion} instead;
     *        the two doors are mutually exclusive
     * @param string|null $cwd directory the child process starts in; null (the
     *        default) keeps the historic inherit-the-parent-cwd behavior
     *        byte-identical. An embedder that must not let a server see where
     *        it happened to be launched passes an absolute directory and the
     *        child opens there — the move is proc_open's own cwd argument, so
     *        it lands on the child at exec time and the parent's working
     *        directory is never touched. The spawn planner cannot express it:
     *        the planner returns [command, env], and this is why the seam's
     *        shape stayed two-tuple — the directory is a spawn fact the
     *        embedder decides up front, not one the wrapper negotiates.
     * @param array<string,mixed> $capabilities client capabilities for the
     *        initialize handshake (the server-request law); the default [] encodes as the
     *        empty JSON OBJECT it always has — a configured map rides
     *        through as an object too, never an array.
     * @param \Closure|null $onServerMessage connection-wide answerer for
     *        the server's own requests and notifications; called as
     *        ($method, $params, $isNotification). Request handlers return
     *        `['result' => ...]`, `['error' => ['code' => int, 'message'
     *        => string]]`, or null for the default answer; notification
     *        handlers return is ignored. A handler that throws aborts the
     *        exchange in flight (callTool converts it to an error payload);
     *        it must never call back into this server — the answer travels
     *        under the exchange lock the caller already holds.
     * @param string|null $lockDir directory holding the exchange lock file;
     *        null (the default) uses the system temp directory. An embedder
     *        whose TMPDIR moves mid-process, or whose temp dir is swept by
     *        a janitor while servers run, pins the lock where it can keep
     *        seeing it. The directory must already exist — minting a lock
     *        file inside a missing one fails start() loudly rather than
     *        silently relocating the shared state out of reach of the
     *        other processes that look for it.
     * @param string|null $clientName name arm of the initialize-handshake
     *        identity, authored without replacing the whole map; the version
     *        arm then rides the same runtime resolution the untouched default
     *        uses — the honest short path for a consumer that only knows its
     *        own name, since a hand-carried version literal rots silently
     *        release after release. Mutually exclusive with a supplied
     *        $clientInfo; the empty string is refused like the whole-map door
     *        refuses []
     * @param string|null $clientVersion version arm of the identity, for the
     *        embedder the Composer runtime cannot see (a phar, a vendored
     *        copy older than this knob); omit it and the advertised version is
     *        whatever the untouched default would announce. Mutually exclusive
     *        with a supplied $clientInfo
     */
    public function __construct(
        public readonly string $name,
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
        ?float $startTimeoutSeconds = null,
        private readonly ?\Closure $spawnPlanner = null,
        array $clientInfo = self::DEFAULT_CLIENT_INFO,
        private readonly ?string $cwd = null,
        array $capabilities = [],
        ?\Closure $onServerMessage = null,
        private readonly ?string $lockDir = null,
        /**
         * Names stripped from the inherited environment before `env:` applies
         * (precedence: inherited → envRemove → env). `curatedEnv:` is the
         * exclusive full-replace alternative; the `spawnPlanner:` closure
         * owns its environment wholesale and may not be combined with either
         * knob (a silent no-op knob is a lie the constructor refuses).
         *
         * @var list<string>
         */
        private readonly array $envRemove = [],
        /** @var ?array<string,string> full replacement, `[]` means a purged environment */
        private readonly ?array $curatedEnv = null,
        ?string $clientName = null,
        ?string $clientVersion = null,
    ) {
        if ($clientName === '' || $clientVersion === '') {
            throw new \InvalidArgumentException(
                'clientName:/clientVersion: refuse the empty string — a blank identity arm is not a reset,'
                . ' it is a wire value that cannot be honored'
            );
        }

        if ($clientName !== null || $clientVersion !== null) {
            if ($clientInfo !== self::DEFAULT_CLIENT_INFO) {
                throw new \InvalidArgumentException(
                    'clientName:/clientVersion: and clientInfo: are mutually exclusive — the split knobs'
                    . ' author the identity piecewise, clientInfo: replaces it whole; supplying both leaves'
                    . ' one wire value with two masters'
                );
            }

            $clientInfo = [
                'name' => $clientName ?? self::DEFAULT_CLIENT_INFO['name'],
                'version' => $clientVersion ?? self::resolvedClientVersion(),
            ];
        } elseif ($clientInfo === self::DEFAULT_CLIENT_INFO) {
            $clientInfo = self::defaultClientInfo();
        }
        $this->startTimeoutSeconds = $startTimeoutSeconds !== null && $startTimeoutSeconds > 0
            ? $startTimeoutSeconds
            : self::DEFAULT_START_TIMEOUT_SECONDS;

        $this->assertClientInfoShaped($clientInfo);
        $this->assertEnvKnobsShaped($spawnPlanner);
        $this->clientInfo = $clientInfo;
        $this->capabilities = $capabilities;
        $this->onServerMessage = $onServerMessage;
        $this->ids = new RequestIdSequence();
    }

    /**
     * Fail fast at the boundary: a half-declared identity would send an
     * initialize frame a server can neither match nor debug.
     *
     * @param array<string,mixed> $clientInfo
     */
    private function assertClientInfoShaped(array $clientInfo): void
    {
        if (!isset($clientInfo['name'], $clientInfo['version'])
            || !is_string($clientInfo['name'])
            || $clientInfo['name'] === ''
            || !is_string($clientInfo['version'])
        ) {
            $keys = $clientInfo === []
                ? 'got an empty list'
                : 'got ' . implode(', ', array_map(static fn (string $k): string => "[$k]", array_keys($clientInfo)));
            throw new \InvalidArgumentException(
                "MCP clientInfo needs a non-empty 'name' and a 'version' string, {$keys}"
            );
        }
    }

    /**
     * Grammar for the declarative env knobs, checked at construction — the
     * one place a typo'd name list or a half-mixed strategy is still cheap
     * to say no to (before the child exists). The precedence chain when no
     * curatedEnv is given: inherited → strip envRemove names → overlay env.
     *
     * @throws \InvalidArgumentException on a non-string or empty name, a
     *         duplicate envRemove entry, a malformed curatedEnv map, or a
     *         combination that would leave one input silently unread
     */
    private function assertEnvKnobsShaped(?\Closure $spawnPlanner): void
    {
        if (!array_is_list($this->envRemove)) {
            throw new \InvalidArgumentException(
                'MCP envRemove must be a list of environment variable names (strings), got a keyed array',
            );
        }

        $seen = [];
        foreach ($this->envRemove as $index => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException(
                    'MCP envRemove entry #' . $index . ' must be a non-empty variable name, got ' . get_debug_type($entry),
                );
            }
            if (isset($seen[$entry])) {
                throw new \InvalidArgumentException(
                    "MCP envRemove names {$entry} twice; each name is stripped once",
                );
            }
            $seen[$entry] = true;
        }

        if ($this->curatedEnv !== null) {
            foreach ($this->curatedEnv as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \InvalidArgumentException(
                        'MCP curatedEnv must map variable names to string values, got ['
                        . (is_string($key) ? $key : get_debug_type($key)) . '] => ' . get_debug_type($value),
                    );
                }
            }
            if ($this->env !== [] || $this->envRemove !== []) {
                throw new \InvalidArgumentException(
                    'MCP curatedEnv replaces the environment outright, which makes env: and envRemove: unread; pass curatedEnv alone, or env:/envRemove: without it',
                );
            }
        }

        if ($spawnPlanner !== null && ($this->envRemove !== [] || $this->curatedEnv !== null)) {
            throw new \InvalidArgumentException(
                'MCP spawnPlanner owns the child environment wholesale, so envRemove: and curatedEnv: would never be read; express the scrub inside the planner, or drop the planner',
            );
        }
    }

    /**
     * The environment the default (planner-less) spawn hands to the child.
     * Null keeps proc_open's full inheritance — the untouched historical
     * shape when neither env nor a removal narrows it.
     *
     * @return ?array<string,string>
     */
    private function buildEnv(): ?array
    {
        if ($this->curatedEnv !== null) {
            return $this->curatedEnv;
        }

        if ($this->envRemove === []) {
            return $this->env === [] ? null : array_merge(getenv(), $this->env);
        }

        $base = getenv();
        foreach ($this->envRemove as $name) {
            unset($base[$name]);
        }

        return array_merge($base, $this->env);
    }

    /**
     * Orphan-proofing: a server the embedder forgot to stop dies with this
     * object. stop() is idempotent, so an already-stopped instance is a no-op.
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Resolve the (command, env) pair handed to proc_open. Default keeps the
     * library's plain-spawn contract byte-identical; a planner returns the
     * pair verbatim after shape validation, so a containment wrapper can own
     * the whole spawn decision (setsid wrap, scrubbed env, or null env).
     *
     * TRUST BOUNDARY, stated where the spawn is decided: the default env is
     * either null (the child inherits the parent's ENTIRE environment) or the
     * parent's full copy with {@see $env} merged over it — additive in every
     * case, never a subtraction. A configured stdio server is therefore run
     * arbitrary code AS THE INVOKING USER, seeing every ambient secret (SSH
     * agent sockets, cloud credentials, provider API keys) unless the
     * embedder wraps a spawn planner that hands back a narrowed map. PATH is
     * resolved by the OS at exec time from that inherited environment; the
     * binary that answers the name is whatever PATH says at that moment, and
     * this library does not verify it.
     *
     * The command must be an argv LIST of strings, never a shell string: a
     * string spawns /bin/sh with the server as its grandchild, and stop() then
     * signals the shell and orphans the server (the hazard in the class
     * docblock). A wrapper that genuinely needs a shell spells it as argv
     * (`['/bin/sh', '-c', $script]`) and owns that consequence explicitly.
     *
     * The NAMED RESIDUAL of that law is the server's OWN children. The group
     * arm of the teardown ladder fires only when the direct child LEADS its
     * process group (`posix_getpgid(child) === child`, checked while it
     * lives); a plain proc_open spawn runs in the embedding process's group,
     * so stop() addresses exactly one pid — and a worker the server daemonizes
     * outlives it, keeping the invoking uid's rights while only its inherited
     * stdio dies to EPIPE. Widening the reach would need setsid-at-spawn,
     * which proc_open cannot express without the wrapper shell this plan
     * refuses; containment of a fork-out tree is the server's job or the
     * embedder's supervisor's, never something this class can fake.
     *
     * @return array{0: list<string>, 1: ?array<string,string>}
     */
    private function spawnPlan(): array
    {
        $argv = [$this->command, ...array_map(static fn (mixed $arg): string => (string) $arg, array_values($this->args))];

        if ($this->spawnPlanner === null) {
            return [$argv, $this->buildEnv()];
        }

        $plan = ($this->spawnPlanner)($this->name, $argv, $this->env);

        if (!is_array($plan)
            || array_keys($plan) !== [0, 1]
            || !($plan[1] === null || is_array($plan[1]))
        ) {
            throw new \InvalidArgumentException(
                "MCP spawnPlanner for {$this->name} must return [command, env|null], got " . get_debug_type($plan)
            );
        }

        if (!is_array($plan[0])
            || $plan[0] === []
            || !array_is_list($plan[0])
            || array_filter($plan[0], static fn (mixed $part): bool => !is_string($part)) !== []
        ) {
            throw new \InvalidArgumentException(
                "MCP spawnPlanner for {$this->name} must return the command as a non-empty argv list of strings, got "
                . get_debug_type($plan[0])
                . ' — a shell-string command makes the server a grandchild of /bin/sh that stop() cannot reach'
            );
        }

        /** @var array{0: list<string>, 1: ?array<string,string>} $plan */
        return $plan;
    }

    /**
     * Renders server- or OS-supplied text safely for embedding into OUR
     * messages: control characters (C0/C1 — escape sequences, NUL, bare CR)
     * never survive, so a hostile or buggy child cannot splice terminal
     * redraws, OSC-8 hyperlinks, or newlines into a diagnostic this client
     * throws or returns. The result is STILL untrusted content: consumers
     * rendering it into a terminal should route it through
     * {@see \SugarCraft\Core\Util\Sanitize::untrusted()} as they would for any
     * server output.
     */
    private static function printableDiagnostic(string $text): string
    {
        $swept = preg_replace('/[\x00-\x1F\x7F-\x9F]+/u', ' ', $text);
        if ($swept !== null) {
            return $swept;
        }

        // The subject is not valid UTF-8 (a Latin-1 stderr line, raw binary).
        // A /u sweep REFUSES the whole string, so the old `?? ''` fallback
        // blinded the diagnostic on the one path built to be honest. Sweep
        // byte-wise instead — every target byte is ASCII anyway — collapsing
        // runs of anything outside printable ASCII to a single space (the
        // same face the /u mode shows), and leave a marker so the reader
        // knows bytes were lost rather than that the server said nothing.
        return (preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '') . ' [non-UTF-8]';
    }

    /**
     * Begin the session: spawn, then run initialize →
     * notifications/initialized → tools/list under one deadline.
     *
     * @param \Closure|null $onWait called at least once a second for as long
     *        as the handshake waits, so an embedder whose watchdog measures
     *        silence can tell a slow cold `npx` fetch from a hang. Same
     *        contract as callTool's beat; the whole 60-second window is now
     *        beat-covered, not just tool calls.
     */
    public function start(?\Closure $onWait = null): void
    {
        // Fail fast on re-entry: overwriting $this->process would drop the
        // handle to the live child, orphaning it until this object dies —
        // and __destruct only covers objects that actually die.
        if ($this->process !== null) {
            throw new \RuntimeException("MCP server double start(): {$this->name} is already running — call stop() first");
        }

        // The planner runs BEFORE any child exists: a refusal (a product
        // pre-check, a malformed plan) throws here and leaves the object as
        // dormant as it was, nothing to reap.
        [$spawnCommand, $spawnEnv] = $this->spawnPlan();

        // The lock exists before the child does: a server nobody can exchange
        // with safely is not started at all. `lockDir:` lets an embedder whose
        // TMPDIR moves or gets swept pin the file somewhere durable; null
        // keeps the historic system-temp placement.
        $this->lock = ExchangeLock::new($this->name, $this->lockDir);

        // Fork ownership (measured): a forked child may not take over the id
        // space while its parent — the current owner — is still live and
        // exchanging on this very connection; two live owners would mint
        // identical ids. The child's own calls mint nonce ids either way, and
        // a claim after the owner's death still succeeds (the restart path).
        if (!$this->ids->claim()) {
            $liveOwner = $this->ids->ownerPid();
            $this->lock->destroy();
            $this->lock = null;

            throw new \RuntimeException(sprintf(
                'MCP server %s is already owned by live pid %d; refusing to start a second owner from pid %d on the same request-id space',
                $this->name,
                $liveOwner,
                (int) getmypid(),
            ));
        }

        $this->ownerPid = (int) getmypid();

        $this->process = @proc_open(
            $spawnCommand,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            $this->cwd,
            $spawnEnv,
        );

        if (!is_resource($this->process)) {
            // The spawn failed; error_get_last() holds the OS answer proc_open
            // raised on the way to false (posix_spawn/execve text), and the
            // program name is the fact a reader can act on — a missing `npx`
            // is THE most common MCP setup error, and naming only the
            // user-chosen alias made it invisible.
            $osCause = (string) (error_get_last()['message'] ?? '');
            $this->process = null;
            $this->lock->destroy();
            $this->lock = null;

            throw new \RuntimeException(sprintf(
                'Failed to start MCP server: %s — could not execute %s%s',
                $this->name,
                $spawnCommand[0],
                $osCause === '' ? '' : ' (' . self::printableDiagnostic($osCause) . ')',
            ));
        }

        $this->serverPid = (int) proc_get_status($this->process)['pid'];
        $this->serverStartTicks = self::procStat($this->serverPid)['startTicks'] ?? 0;

        // stream_set_timeout does not work on proc_open pipes (measured: it
        // returns false and sets nothing), and blocking reads would ignore any
        // deadline we hold. Non-blocking + stream_select is the bound.
        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->stderrOpen = true;
        $this->stderrTail = '';
        $this->stderrTruncated = false;
        $this->lastFailureCause = null;
        $this->malformedToolDefs = [];
        $this->malformedToolDefsTotal = 0;
        $this->initializeResult = null;

        $deadline = self::deadlineAfter($this->startTimeoutSeconds);

        $previousBeat = $this->onWait;
        $this->onWait = $onWait;

        try {
            // capabilities is a JSON object in the schema; PHP's `[]` encodes
            // as a JSON array, which the official SDK servers answer with an
            // "expected object, received array" error. The configured map (the
            // server-request law) rides through the same (object) cast, so the
            // default [] still lands as {} byte-for-byte.
            $response = $this->requestAs((string) $this->ids->next(), 'initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) $this->capabilities,
                'clientInfo' => $this->clientInfo,
            ], $deadline);

            // A successful initialize answers with a result. Silence, garbage
            // or a null-shaped non-response means we cannot trust a byte from
            // this process — and an ERROR reply is a refusal, not a start: the
            // server will not serve tools to a session it never initialized,
            // so carrying on would report "up, 0 tools" after burning the
            // whole tools/list budget. The server's own code and message are
            // the most useful diagnostic there is, so they lead the exception.
            // Diagnostics are read BEFORE stop() because stop() clears the
            // stderr capture.
            if ($response === null || $response->error !== null || !$response->resultSet) {
                $diagnostics = $this->stderrTailForDiagnostics();
                $refusal = $response?->error !== null ? $this->describeError('initialize', $response) : '';
                $this->stop();

                throw new \RuntimeException("Failed to start MCP server: {$this->name}" . $refusal . $diagnostics);
            }

            // Protocol negotiation (MCP requires the server to echo the
            // revision it will speak). A reply naming a DIFFERENT revision
            // means the pair holds diverging assumptions about every frame
            // after this — refuse at the one place that still knows, instead
            // of letting the mismatch surface later as an unexplainable call
            // failure. A server that omits the field (pre-negotiation builds)
            // proceeds: absence is not a counter-offer.
            $negotiated = $response->result['protocolVersion'] ?? null;
            if (is_string($negotiated) && $negotiated !== self::PROTOCOL_VERSION) {
                $diagnostics = $this->stderrTailForDiagnostics();
                $this->stop();

                throw new \RuntimeException(sprintf(
                    'Failed to start MCP server: %s — protocol mismatch: this client speaks %s, the server answered %s',
                    $this->name,
                    self::PROTOCOL_VERSION,
                    self::printableDiagnostic($negotiated),
                ) . $diagnostics);
            }

            // Keep what the server advertised: server-authored data handed
            // out verbatim by the accessors, for the consumer to sanitize.
            // Judging-and-discarding used to leave the identity unrecoverable.
            $this->initializeResult = is_array($response->result) ? $response->result : null;

            // The spec's method name. A bare `initialized` is ignored by the TS
            // SDK (its oninitialized hook never fires) and only tolerated by
            // others, so a server gating work on initialization never sees it.
            // A notification that never left (dead pipe, spent budget) means
            // the server never learnt the session is initialized — a server
            // gating work on it would then answer tools/list with an error or
            // not at all, so the failure is named here, where it happened.
            if (!$this->notify('notifications/initialized', null, $deadline)) {
                $diagnostics = $this->stderrTailForDiagnostics();
                $this->stop();

                throw new \RuntimeException(
                    "Failed to start MCP server: {$this->name} — notifications/initialized could not be delivered" . $diagnostics
                );
            }

            // params omitted rather than sent as `{}`: tools/list params are
            // optional ({cursor?}) and omission is exactly what the reference
            // TS client puts on the wire, so it is the shape every server has
            // been tested against.
            $this->tools = $this->collectToolPages($this->requestAs((string) $this->ids->next(), 'tools/list', null, $deadline), $deadline);
        } catch (\Throwable $failure) {
            // A throw from any handshake leg — the oversized-frame refusal
            // rides this path — must not leave the spawned child alive on the
            // pipes: reap now instead of waiting for this object's destructor
            // (an embedder that keeps the failed instance alive keeps the
            // orphan alive too). stop() is idempotent, so the initialize-
            // failure leg above may already have run it.
            $this->stop();

            throw $failure;
        } finally {
            // The handshake beat lives exactly as long as the handshake —
            // the same one-call lifetime law as the per-call beat.
            $this->onWait = $previousBeat;
        }
    }

    /**
     * Tear the connection down: pipes first (a well-behaved server exits on
     * stdin EOF before ever seeing a signal), then the TERM → poll → KILL →
     * reap ladder, then the lock and the per-call state. Idempotent, and
     * fork-aware — a non-owner clone closes only its own copies and leaves
     * the connection to the process that started it.
     *
     * REACH: the ladder addresses the ONE process proc_open started. Group
     * kill needs a group-leading child and a plain spawn never leads one, so
     * a server that daemonizes its own workers outlives stop() — the named
     * residual of {@see spawnPlan()}'s argv law, not a bug this pass hides.
     */
    public function stop(): void
    {
        if ($this->process !== null && !$this->isOwnerProcess()) {
            // A forked process holds COPIES of the pipes and handle; the server
            // belongs to the process tree's owner, which may still be serving
            // (and other children may be mid-exchange). Close what is ours and
            // forget the rest — no signal, no reap, no unlink.
            $this->closePipes();
            $this->lock?->close();
            $this->forgetConnection();

            return;
        }

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
        $this->lock?->destroy();
        $this->forgetConnection();
    }

    private function forgetConnection(): void
    {
        $this->process = null;
        $this->pipes = null;
        $this->lock = null;
        $this->serverPid = 0;
        $this->serverStartTicks = 0;
        $this->readBuffer = '';
        $this->stderrOpen = false;
        // The roster belongs to the connection that just died: isUp() is
        // false while listTools() served a table of dead tools.
        $this->tools = [];
        // stderrTail/stderrTruncated/lastFailureCause/malformedToolDefs/
        // initializeResult deliberately SURVIVE
        // stop() — the captured evidence is exactly what a post-mortem of
        // the server you just lost needs. start() clears them afresh.
    }

    /**
     * The captured stderr tail, control characters swept. Survives stop() on
     * purpose: a server that died is the one whose logs you want. The TEXT
     * is server-authored and remains UNTRUSTED — render it through a
     * sanitizer before terminal-facing display.
     */
    public function stderrTail(): string
    {
        return self::printableDiagnostic(trim($this->stderrTail));
    }

    /** Is this the process that started the server (or nothing is started)? */
    private function isOwnerProcess(): bool
    {
        return $this->ownerPid === 0 || $this->ownerPid === (int) getmypid();
    }

    /**
     * Liveness that works from any process. The owner asks proc_get_status();
     * a forked child cannot — waitpid() on a pid that is not its child fails
     * with ECHILD and PHP reports "not running" — so it probes the pid captured
     * at start with signal 0.
     *
     * Signal 0 alone is not enough there: a server that died while the owner
     * is busy elsewhere (candy-code's parent blocks in waitpid() on the turn,
     * never in proc_get_status()) stays an unreaped ZOMBIE, and kill(zombie, 0)
     * succeeds — so a forked turn would wait out a helper still holding the
     * pipes. Where /proc exists the probe therefore also reads the pid's state
     * and treats Z/X as dead, and a start time that no longer matches the one
     * recorded at start() as a reused pid, i.e. dead too. Without /proc (macOS,
     * BSD) the signal-0 answer stands; without ext-posix a child assumes the
     * server is up and lets the pipes report a dead one (write failure / EOF).
     */
    private function serverIsRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        if ($this->isOwnerProcess()) {
            return self::childIsRunning($this->process);
        }

        if ($this->serverPid <= 0 || !function_exists('posix_kill')) {
            return true;
        }

        // The shared refuse-only-on-certainty polarity: EPERM and unreadable
        // errnos answer alive here exactly as they do for the id-sequence
        // claim and the stale-lock sweep.
        if (!ProcessLiveness::ownerAlive($this->serverPid)) {
            return false;
        }

        $stat = self::procStat($this->serverPid);
        if ($stat === null) {
            // No procfs here, or the entry went between the signal and the
            // read: signal 0 said "exists", and a pid that is truly gone will
            // fail that probe on the next poll.
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
        $path = "/proc/{$pid}/stat";
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $close = is_string($raw) ? strrpos($raw, ')') : false;
        if (!is_string($raw) || $close === false) {
            return null;
        }

        // Field 3 (state) onwards; starttime is field 22 => index 19 here.
        $fields = preg_split('/\s+/', trim(substr($raw, $close + 1)));
        if (!is_array($fields) || count($fields) < 20 || $fields[0] === '' || !ctype_digit($fields[19])) {
            return null;
        }

        return ['state' => $fields[0], 'startTicks' => (int) $fields[19]];
    }

    /**
     * Drain pending stderr without blocking, for callers that idle between
     * exchanges and want diagnostics warm. This is a CALLER-PUMPED seam on
     * purpose: mounting it on an event loop would put two readers on one pipe,
     * and pipe reads are destructive — whichever reader wins eats bytes the
     * other was meant to see (the shared-pipe readership ruling: two readers on one pipe steal each other's bytes).
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
        return $this->serverIsRunning();
    }

    /** @return list<McpTool> */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * Labels of the tools/list definitions refused by the well-typedness
     * gate during the current start — the ones {@see parseTools()} skipped
     * instead of registering. Post-mortem evidence: reset by every start(),
     * kept by stop(). The view is capped at
     * {@see self::MALFORMED_LEDGER_CEILING} entries; the full count rides
     * the report.
     *
     * @return list<string>
     */
    public function malformedToolDefinitions(): array
    {
        return $this->malformedToolDefs;
    }

    /**
     * Ceiling on recorded malformed-definition rows (the roster list, not
     * the counted total): bounds what ONE adversarial `tools/list` can
     * make this client remember, mirroring the capped-tail posture of the
     * stderr capture.
     */
    private const MALFORMED_LEDGER_CEILING = 100;

    /**
     * One-line announcement for the skipped definitions, '' when the last
     * tools/list walk registered everything the server advertised. Report
     * it wherever the server's start outcome is surfaced — the roster is
     * already SAFE (only valid definitions were registered); this is the
     * half of the story the user must not have to guess. The count is the
     * honest total; the named rows are the first few of a ledger capped at
     * {@see self::MALFORMED_LEDGER_CEILING}, and a cut-off list says so
     * with `[+N more]` — truncation announced, never silent.
     */
    public function malformedToolDefinitionsReport(): string
    {
        if ($this->malformedToolDefs === []) {
            return '';
        }

        $overflow = $this->malformedToolDefsTotal - count($this->malformedToolDefs);

        return sprintf(
            'MCP server %s advertised %d malformed tool definition(s) in tools/list (first: %s)%s — skipped, the rest of the roster registered',
            $this->name,
            $this->malformedToolDefsTotal,
            implode(', ', array_slice($this->malformedToolDefs, 0, 5)),
            $overflow > 0 ? sprintf(' [+%d more]', $overflow) : '',
        );
    }

    /**
     * The server's advertised `serverInfo` (`name`, `version`, optional
     * `title`) exactly as it answered the handshake — UNTRUSTED
     * server-authored text: route it through Sanitize::untrusted before it
     * reaches a terminal. Null before the first successful start(), after a
     * start that never reached the judgement, or when the server omitted the
     * member (see {@see self::initializeResultDefects()}). Survives stop():
     * "which server was this?" stays answerable post-mortem.
     *
     * @return array<string,mixed>|null
     */
    public function serverInfo(): ?array
    {
        $info = $this->initializeResult['serverInfo'] ?? null;

        return is_array($info) ? $info : null;
    }

    /**
     * The server's advertised capability map — the gate an embedder reads
     * before trying `resources/*`, `prompts/*`, elicitation, or trusting a
     * listChanged notification: `null`/absent means the server never claimed
     * the feature. Same survival and untrusted-text laws as
     * {@see self::serverInfo()}.
     *
     * @return array<string,mixed>|null
     */
    public function serverCapabilities(): ?array
    {
        $caps = $this->initializeResult['capabilities'] ?? null;

        return is_array($caps) ? $caps : null;
    }

    /**
     * Spec-required InitializeResult members the server failed to send
     * (`serverInfo`, `capabilities`). The store is deliberately tolerant —
     * a server that negotiated and listed tools boots, and these names tell
     * the embedder WHY the accessors come back empty instead of leaving the
     * omission unannounced. Empty list means the handshake was whole.
     *
     * @return list<string>
     */
    public function initializeResultDefects(): array
    {
        if ($this->initializeResult === null) {
            return [];
        }

        $defects = [];
        if (!isset($this->initializeResult['serverInfo']) || !is_array($this->initializeResult['serverInfo'])) {
            $defects[] = 'serverInfo (required by the spec; serverInfo() returns null)';
        }
        if (!isset($this->initializeResult['capabilities']) || !is_array($this->initializeResult['capabilities'])) {
            $defects[] = 'capabilities (required by the spec; serverCapabilities() returns null)';
        }

        return $defects;
    }

    /** Budget for delivering `notifications/cancelled` after a timed-out call. */
    private const CANCEL_NOTICE_SECONDS = 2.0;

    /**
     * Invoke a tool. No deadline by default (the no-blanket-timeout law): see the class docblock.
     *
     * $timeoutSeconds is the opt-in bound: a positive number abandons the
     * wait that many seconds after the call starts (lock wait included —
     * time queued behind another process's exchange is time the caller
     * spent), sends `notifications/cancelled` naming the request so the
     * server can stop the work, and answers `['error' => 'Tool call timed
     * out …']`. Null (or a non-finite value) keeps the call unbounded; zero
     * and a negative are refused at the door with an InvalidArgumentException
     * — "never" is spelled null, and a mis-set bound must not masquerade as
     * a deliberate one.
     *
     * $onWait is the caller's liveness beat: it is called at least once per
     * READ_POLL_SECONDS for as long as this call waits — for the exchange
     * lock, for stdin to drain, for the reply — so an embedder whose own
     * watchdog measures SILENCE (candy-code kills a turn after 120 s with no
     * frame) can tell "a tool is working" from "the turn is hung" without
     * this library guessing a deadline for somebody else's work. It may be
     * called far more often than that; throttling is the caller's business.
     * An exception it throws ends the call through the error rails (payload,
     * never an escaped throw — the same posture as every other failure).
     *
     * A failed call answers as an error payload, never a throw: unencodable
     * arguments, a blown deadline, and this client's refusals of a
     * non-conforming reply stream (an oversized frame) all arrive as
     * `['error' => ...]`. Refusing a frame drops the read buffer — the
     * framing reset — and leaves the connection up for the next exchange.
     * The payload names the CAUSE: the server's JSON-RPC error code and
     * message when it answered with an error, and the reader's reason for
     * giving up (dead child, write failure, lost lock, refused frame) when
     * it never got an answer at all — never a bare "Tool call failed".
     *
     * The parameter names match the `McpServer` interface exactly, so a
     * `callTool(name: ..., arguments: ...)` call written against the
     * contract binds on this class too. The three trailing opt-ins
     * (`onWait:`, `timeoutSeconds:`, `onServerMessage:`) exist on this
     * concrete class only — the interface carries the two required
     * parameters, and code typed to the interface cannot pass them.
     *
     * @param array<string,mixed> $arguments
     * @param (\Closure(): void)|null $onWait
     * @param (\Closure(string, ?array<string,mixed>, bool): ?array)|null $onServerMessage
     *        per-call override of the connection's server-request handler
     *        (the server-request law); null keeps the configured one in force.
     * @return FailurePayload
     */
    public function callTool(string $name, array $arguments, ?\Closure $onWait = null, ?float $timeoutSeconds = null, ?\Closure $onServerMessage = null): array
    {
        // A zero or negative bound is not "unbounded said politely". The
        // constructor already folds its 0 into the default start wait, so a
        // second, OPPOSITE reading of the same input at the call door would
        // leave every caller guessing. Null says never, out loud; anything
        // at or below zero is a bug and is refused like one.
        if ($timeoutSeconds !== null && $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'callTool() timeoutSeconds must be null (unbounded) or a positive number of seconds; zero and negatives are refused.',
            );
        }

        $previous = $this->onWait;
        $this->onWait = $onWait;
        $previousHandler = $this->callServerMessage;
        $this->callServerMessage = $onServerMessage;

        try {
            return $this->callToolWaiting($name, $arguments, $timeoutSeconds);
        } finally {
            $this->onWait = $previous;
            $this->callServerMessage = $previousHandler;
        }
    }

    /**
     * @param array<string,mixed> $arguments
     * @return FailurePayload
     */
    private function callToolWaiting(string $name, array $arguments, ?float $timeoutSeconds): array
    {
        $bounded = $timeoutSeconds !== null && is_finite($timeoutSeconds) && $timeoutSeconds > 0.0;
        $deadline = $bounded ? self::deadlineAfter($timeoutSeconds) : null;
        $id = (string) $this->ids->next();
        $this->lastFailureCause = null;

        try {
            // A NON-EMPTY list cannot be a JSON object under any repair: the
            // wire would carry `"arguments":[…]`, which every conforming
            // server answers -32602. That round trip is wasted traffic the
            // transport can assert against right here; the empty case below
            // stays repairable (`[]` → stdClass) because it IS a valid empty
            // object the moment it is re-encoded.
            if (array_is_list($arguments) && $arguments !== []) {
                throw new \InvalidArgumentException(
                    'arguments must be a JSON object, got a list.',
                );
            }

            // Encode HERE, apart from the wait: the InvalidArgumentException
            // that reports argument-encoding trouble may only come from this
            // line — nothing has been written yet. A handler's malformed
            // answer throws from the wait side below and gets its true class
            // named there.
            $encoded = McpMessage::request($id, 'tools/call', [
                'name' => $name,
                // An argument-less call arrives as PHP `[]`, which would encode as a
                // JSON array; `arguments` is a JSON object in the schema and the SDK
                // servers reject the array form ("expected record, received array").
                // The same loss one level down (`{"filter":{}}` decoded assoc to
                // `['filter' => []]`, the nested-object wire audit) is undone
                // against the tool's own inputSchema — see ArgumentShape.
                'arguments' => $arguments === [] ? new \stdClass() : ArgumentShape::conform($arguments, $this->inputSchemaOf($name)),
            ])->toJson();
        } catch (\InvalidArgumentException $unencodable) {
            // Arguments JSON cannot carry (a model's `1e999` decodes to INF):
            // nothing was written, and the McpServer contract reports a failed
            // call as a payload the transcript can show, never as a throw.
            return ['error' => 'Tool call failed: ' . $unencodable->getMessage()];
        }

        try {
            $response = $this->requestEncoded($id, $encoded, $deadline);
        } catch (\RuntimeException $frameRefusal) {
            // The RuntimeExceptions reachable from requestAs are this
            // client's refusals of a non-conforming stream — the frame-cap
            // drop (refuseAnOversizedFrame) and any later conformance tripwire.
            // The McpServer contract (see the interface docblock)
            // promises a failed call as an error payload, never a throw
            // escaping callTool into the caller's tool loop.
            //
            // The connection is NOT stopped here. Dropping the oversized
            // buffer IS the framing reset: exchange()'s finally already
            // recorded the dirty phase and cleared the partial line, and the
            // bytes still in the pipe resync under readResponse's skip law
            // (unparseable/foreign-id frames are ignored), so the next
            // exchange either completes or fails on its own merits. Killing
            // the child would punish every later call for one bad frame and
            // contradict the payload-refusal posture this catch exists to
            // honour.
            return ['error' => 'Tool call failed: ' . $frameRefusal->getMessage()];
        } catch (\Throwable $handlerFault) {
            // The last rail (review M10): everything else the wait can throw
            // — a server-request handler's TypeError, its LogicException, a
            // malformed-answer InvalidArgumentException — belongs in an
            // error payload, not in the caller's tool loop: the McpServer
            // contract promises failed calls come back as payloads, never as
            // throws escaping callTool. The fault's own class name is kept
            // so it can never be mistaken for argument-encoding trouble.
            return ['error' => 'Tool call failed: ' . get_class($handlerFault) . ': ' . self::printableDiagnostic($handlerFault->getMessage())];
        }

        if ($response === null && $deadline !== null && $this->lastFailureCause === null && self::nowSeconds() >= $deadline) {
            // A TIMED-OUT wait is null with no other story to tell. If the
            // reader gave up for a concrete reason (dead child, refused
            // frame, failed write) that reason IS the answer — calling it a
            // timeout would send debugging at the wrong door, and the
            // server-request leg's deadline extension can otherwise make a
            // fast EOF look like a blown budget.
            $this->cancelAbandoned($id);

            return ['error' => sprintf(
                'Tool call timed out after %ss and was cancelled; the server may still finish the work',
                rtrim(rtrim(sprintf('%.3F', $timeoutSeconds), '0'), '.'),
            )];
        }

        if ($response === null) {
            // No answer, and a cause to name: set by whichever reader rail
            // gave up (write failure, lost lock/deadline, dead child, select
            // storm, refused frame). Absent one, the wait simply ended.
            $cause = $this->lastFailureCause ?? 'no reply arrived before the wait ended';

            return ['error' => 'Tool call failed: ' . $cause . $this->stderrTailForDiagnostics()];
        }

        if ($response->error !== null) {
            // JSON-RPC 2.0 forbids a reply carrying BOTH result and error;
            // the error is checked FIRST so a broken or hostile server can
            // never fake transcript success. The start() legs already judge
            // error before result — the call leg must not be the exception.
            // Server-authored bytes are printableDiagnostic-swept inside
            // describeError and remain UNTRUSTED for the consumer.
            return ['error' => 'Tool call failed' . $this->describeError('tools/call', $response) . $this->stderrTailForDiagnostics()];
        }

        if (!$response->resultSet) {
            return ['error' => 'Tool call failed: the server answered neither result nor error'];
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
     * `resources/list` — the server's advertised resources. Same
     * array-or-error shape as {@see self::callTool()}: the result payload on
     * success, `['error' => ...]` on any failure; never a throw, never null.
     * The unbounded-wait law applies (this is protocol chatter without a
     * defensible blanket budget — bound it via {@see self::request()} if you
     * need a deadline).
     *
     * @return FailurePayload
     */
    public function listResources(): array
    {
        return $this->protocolResult('resources/list');
    }

    /**
     * `resources/read` — fetch one resource by URI.
     *
     * @return FailurePayload
     */
    public function readResource(string $uri): array
    {
        return $this->protocolResult('resources/read', ['uri' => $uri]);
    }

    /**
     * `prompts/list` — the server's advertised prompt templates.
     *
     * @return FailurePayload
     */
    public function listPrompts(): array
    {
        return $this->protocolResult('prompts/list');
    }

    /**
     * `prompts/get` — render one prompt template. $arguments follows the
     * tool-arguments law: an object map, a non-empty positional list is
     * refused at the client door, and `[]` is repaired to an empty JSON
     * object before the wire sees it.
     *
     * @param array<array-key,mixed> $arguments
     * @return FailurePayload
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        if (array_is_list($arguments) && $arguments !== []) {
            return ['error' => 'MCP prompts/get failed: arguments must be a JSON object, got a list.'];
        }

        return $this->protocolResult('prompts/get', [
            'name' => $name,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ]);
    }

    /**
     * The shared request→check-error→extract-result walk the embedders
     * previously re-implemented per transport. The failure cause (dead
     * child, refused frame, JSON-RPC error) is named exactly the way
     * callTool names it, so one vocabulary covers every convenience door.
     *
     * @param array<string,mixed>|null $params
     * @return FailurePayload
     */
    private function protocolResult(string $method, ?array $params = null): array
    {
        $this->lastFailureCause = null;

        try {
            $response = $this->request($method, $params);
        } catch (\InvalidArgumentException $unencodable) {
            return ['error' => "MCP {$method} failed: " . $unencodable->getMessage()];
        }

        if ($response === null) {
            $cause = $this->lastFailureCause ?? 'no reply arrived before the wait ended';

            return ['error' => "MCP {$method} failed: {$cause}" . $this->stderrTailForDiagnostics()];
        }

        if ($response->error !== null) {
            return ['error' => "MCP {$method} failed" . $this->describeError($method, $response) . $this->stderrTailForDiagnostics()];
        }

        if (!$response->resultSet) {
            return ['error' => "MCP {$method} failed: the server answered neither result nor error"];
        }

        return is_array($response->result) ? $response->result : ['result' => $response->result];
    }

    /**
     * Tell the server to stop a call this side gave up on (MCP
     * `notifications/cancelled`). Best effort and bounded: a server that has
     * died or wedged its stdin costs CANCEL_NOTICE_SECONDS, never a hang, and
     * a lost notice loses nothing this side depends on — the late reply, if
     * one comes, is skipped by id.
     */
    private function cancelAbandoned(string $id): void
    {
        $previous = $this->onWait;
        $this->onWait = null;

        try {
            $this->notify('notifications/cancelled', [
                'requestId' => $id,
                'reason' => 'client timeout',
            ], self::deadlineAfter(self::CANCEL_NOTICE_SECONDS));
        } finally {
            $this->onWait = $previous;
        }
    }

    /**
     * The inputSchema the tool advertised at start(), or `[]` for a name the
     * table does not hold (ArgumentShape then converts nothing). `[]` is
     * deliberately shared between "unknown tool" and "tool that schema-types
     * nothing": both mean "no shape information to act on", and a wrong name
     * is the server's error to report, not this lookup's.
     *
     * @return array<array-key,mixed>
     */
    private function inputSchemaOf(string $toolName): array
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $toolName) {
                return $tool->inputSchema;
            }
        }

        return [];
    }

    /**
     * One request/response round trip. Public so protocol traffic beyond tools
     * (resources/list, prompts/get, completion) is one call away for embedders
     * without this library growing that surface speculatively — the product
     * implements none of it either.
     *
     * @param array<string,mixed>|null $params
     * @param float|null $deadline an ABSOLUTE monotonic instant on the
     *        hrtime basis {@see self::nowSeconds()} returns — NOT a
     *        duration, NOT wall clock. Guessing wrong fails quietly: a past
     *        instant returns null having written nothing; a `microtime(true)`
     *        magnitude (~1.8e9 vs the boot-relative ~1e4 of a real instant)
     *        can never be reached and the bounded wait degenerates to an
     *        unbounded one on a silent server. The magnitude is therefore
     *        refused outright (below), and legitimate instants up to ~31
     *        years of uptime still pass. Compute one with
     *        {@see self::deadlineAfter()}.
     * @param (\Closure(string, ?array<string,mixed>, bool): ?array)|null $onServerMessage
     *        per-call override of the connection's server-request handler
     *        (the server-request law), live for this round trip only.
     * @return RawRequestResult
     * @throws \InvalidArgumentException when $params cannot be encoded as JSON
     *         (see McpMessage::toJson()), or when $deadline carries the
     *         wall-clock magnitude this seam refuses; nothing is written in
     *         either case
     */
    public function request(string $method, ?array $params = null, ?float $deadline = null, ?\Closure $onServerMessage = null): ?McpMessage
    {
        self::refuseImplausibleDeadline($deadline);

        $previousHandler = $this->callServerMessage;
        $this->callServerMessage = $onServerMessage;

        try {
            return $this->requestAs((string) $this->ids->next(), $method, $params, $deadline);
        } finally {
            $this->callServerMessage = $previousHandler;
        }
    }

    /**
     * {@see request()} under an id the caller already drew — callTool() needs
     * it to name the request in a `notifications/cancelled`.
     *
     * $deadline is taken by reference, matching {@see self::readResponse()}'s
     * law: a server-request handler answered mid-leg is credited BACK onto
     * this instant, so a caller that threads one budget across several legs
     * (the handshake, the pagination walk) sees handler wall clock excluded
     * from the WHOLE budget, not just from the leg that paused. Public
     * request()/notify() keep the by-VALUE signature on purpose — making a
     * public parameter by-reference would forbid the documented call shape
     * `request($m, $p, deadlineAfter(5.0))` (PHP fatals on expressions
     * passed by reference), and per-call credit confined to one call is the
     * honest public semantics.
     *
     * @param array<string,mixed>|null $params
     */
    private function requestAs(string $id, string $method, ?array $params, ?float &$deadline): ?McpMessage
    {
        self::refuseImplausibleDeadline($deadline);

        return $this->requestEncoded($id, McpMessage::request($id, $method, $params)->toJson(), $deadline);
    }

    /**
     * Run an ALREADY-ENCODED request under the exchange lock and await its
     * matching reply. Encoding is split out so a caller can tell "the
     * arguments could not be encoded" (nothing was written) apart from "the
     * wait died" (a refused frame, a handler fault) — the honest-cause
     * rails the reply-shape review asked for.
     *
     * $deadline threads by reference (see {@see self::requestAs()}): the
     * closure binds to the CALLER's instant, so readResponse's handler-credit
     * extension outlives the closure and reaches the next leg.
     */
    private function requestEncoded(string $id, string $json, ?float &$deadline): ?McpMessage
    {
        return $this->exchange($deadline, true, function (string $prefix) use ($json, $id, &$deadline): ?McpMessage {
            if (!$this->writeLine($prefix . $json, $deadline)) {
                $this->lastFailureCause = 'the request could not be written to the server';

                return null;
            }

            // Same law as the W mark in exchange(): an unrecorded R would let
            // the next holder trust a stdout this one may die half-way into.
            if (!($this->lock?->markPhase(ExchangeLock::PHASE_READING) ?? false)) {
                $this->lastFailureCause = 'the read phase marker could not be saved';

                return null;
            }

            return $this->readResponse($id, $deadline);
        });
    }

    /**
     * Notification leg of the protocol: no reply is awaited, but delivery is
     * reported — true once the whole line went out, false when it did not
     * (not started, dead pipe, lock or $deadline lost), so an embedder sending
     * `notifications/cancelled` or `progress` can tell a dropped one.
     *
     * @param array<string,mixed>|null $params
     * @return NotifyAck
     * @throws \InvalidArgumentException when $params cannot be encoded as JSON
     */
    public function notify(string $method, ?array $params = null, ?float $deadline = null): bool
    {
        self::refuseImplausibleDeadline($deadline);

        $json = McpMessage::notification($method, $params)->toJson();

        return $this->exchange($deadline, false, fn (string $prefix): ?bool => $this->writeLine($prefix . $json, $deadline) ? true : null) === true;
    }

    /**
     * Public framing primitive: write exactly one newline-delimited JSON-RPC
     * frame (payload + "\n") under an optional monotonic deadline, absorbing
     * stderr while waiting for write-readiness — the same drain law every
     * internal leg obeys, so a full stderr pipe can never wedge this side.
     *
     * Embedders that previously hand-framed their own writes around this
     * transport's private leg (register nit n29: the newline-delimited stdio
     * copies around this transport in candy-code — notably
     * `ClaudeCodeMcpClient::writeAll`) call THIS instead of re-deriving the
     * delimiter. It carries no exchange lock and no dead-holder salvage
     * prefix on purpose: full request/response
     * sequencing (lock, reply matching, half-line recovery) lives in
     * {@see self::request()} and {@see self::notify()}, which are preferred
     * whenever the caller is speaking the protocol rather than emitting one
     * raw frame.
     *
     * The payload must be a single-line JSON document — an embedded raw
     * newline OR carriage return would forge a second wire frame (many
     * stdio line readers — Node readline, Python universal-newline IO,
     * and friends — split on a bare CR as well as LF, so the CR carries
     * the same forging power toward the child even though our own
     * framing only reads LF). It is therefore refused loudly at the
     * boundary (the private leg must still accept the salvage-prefixed
     * shape, which is why this validates and delegates instead of replacing).
     *
     * @param float|null $deadline absolute monotonic instant, same law as
     *        {@see self::notify()}: compute it with {@see self::deadlineAfter()}
     * @return bool true once the whole frame went out; false when it did not
     *         (not started, dead pipe, deadline lost)
     * @throws \InvalidArgumentException when $json embeds a carriage return
     *         or newline, or when $deadline is not a monotonic instant
     */
    public function writeFramed(string $json, ?float $deadline = null): bool
    {
        if (strpbrk($json, "\r\n") !== false) {
            throw new \InvalidArgumentException(
                'MCP frame payload must be a single-line JSON document; an embedded CR or LF would forge a second wire frame.',
            );
        }

        self::refuseImplausibleDeadline($deadline);

        return $this->writeLine($json, $deadline);
    }

    /**
     * Run one exchange under the cross-process lock (see FORK SAFETY above).
     *
     * $body gets the line prefix ("\n" when a previous holder died while
     * writing, so its half line is terminated before ours); a fragment the
     * dead holder left on stdout is skipped by readResponse(), which skips
     * every unparseable line. A null from $body — or a throw — is a FAILED exchange: the
     * phase marker it reached stays behind so the next holder recovers, since
     * a request abandoned at a deadline can leave half a line on either pipe
     * just as a killed process can.
     *
     * @template T
     * @param \Closure(string): (T|null) $body
     * @return T|null
     */
    private function exchange(?float $deadline, bool $reads, \Closure $body): mixed
    {
        $lock = $this->lock;
        if ($lock === null || !is_resource($this->process) || $this->pipes === null) {
            $this->lastFailureCause = 'the server is not started';

            return null;
        }

        // The liveness probe doubles as the wait beat: acquire() polls it
        // while another process holds the exchange, which for a shared
        // server is time this caller spends waiting on somebody else's call.
        if (!$lock->acquire($deadline, function (): bool {
            $this->beat();

            return $this->serverIsRunning();
        })) {
            $this->lastFailureCause = 'the exchange lock was unavailable (deadline passed or the server died while another process held it)';

            return null;
        }

        $result = null;

        try {
            [$phase, $buffer] = $lock->load();
            $dirty = $phase !== ExchangeLock::PHASE_CLEAN;

            // A dead holder's buffer is not trustworthy: it may end in the head
            // of a line whose tail is still in the pipe. Drop it and let the
            // reader skip the fragment instead.
            $this->readBuffer = $dirty ? '' : $buffer;

            // Unrecorded, a W marker cannot tell the next holder that this
            // one died mid-line — running unprotected is the defect the
            // marker exists to close, so a failed mark fails the exchange.
            if ($lock->markPhase(ExchangeLock::PHASE_WRITING)) {
                $result = $body($phase === ExchangeLock::PHASE_WRITING ? "\n" : '');
            } else {
                $this->lastFailureCause = 'the exchange phase marker could not be saved';
            }
        } finally {
            // A store that fails leaves the file reading as dirty (see
            // ExchangeLock::store()), so the next holder resynchronises
            // instead of trusting state this exchange could not record. The
            // result itself stands: the reply WAS read, and only what was
            // past it is lost.
            if ($result === null) {
                // Keep the marker this exchange reached (W or R); the buffer is
                // dropped for the same reason a dead holder's is.
                [$reached] = $lock->load();
                $lock->store($reached === ExchangeLock::PHASE_CLEAN ? ExchangeLock::PHASE_READING : $reached, '');
            } elseif (isset($dirty) && $dirty && !$reads) {
                // Our line went out whole, so stdin is clean again — but stdout
                // may still start mid-line and nothing has resynchronised it:
                // hand the recovery to the next reader.
                $lock->store(ExchangeLock::PHASE_READING, '');
            } else {
                $lock->store(ExchangeLock::PHASE_CLEAN, $this->readBuffer);
            }

            // The private copy must not outlive the lock: the next exchange may
            // run in another process, and this copy would then be stale.
            $this->readBuffer = '';
            $lock->release();
        }

        return $result;
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

    /**
     * The clock every `$deadline` in this class is measured on: monotonic
     * seconds since an arbitrary boot-relative origin (`hrtime`), never wall
     * clock, never rewound by an NTP step (a handshake bound must ride a
     * clock that only moves forward, matching sugar-core BoundedShutdown's
     * own hrtime basis). Public so elapsed-time arithmetic is possible; to
     * BOUND a wait, prefer {@see self::deadlineAfter()} over hand-adding.
     */
    public static function monotonicNow(): float
    {
        return self::nowSeconds();
    }

    /**
     * The seatbelt for the two deadline idioms: {@see self::request()} and
     * {@see self::notify()} take an ABSOLUTE monotonic instant while
     * {@see self::callTool()} takes a RELATIVE duration — mixing them up
     * (passing a duration where an instant belongs) fails quietly, because a
     * small boot-relative instant sits in the past and the wait simply never
     * starts, or a wall-clock magnitude can never be reached. This helper
     * turns "how long" into "until when" on the one basis this class reads,
     * so embedders never hand-combine the idioms.
     *
     * @throws \InvalidArgumentException when $seconds is negative — a
     *         negative bound is a caller arithmetic bug, and feeding it to
     *         the past-instant path would silently write nothing
     */
    public static function deadlineAfter(float $seconds): float
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'deadlineAfter() refuses a negative bound (%.6F seconds); pass 0.0 for an already-expired instant or null for no bound.',
                $seconds,
            ));
        }

        return self::nowSeconds() + $seconds;
    }

    /**
     * A wall-clock `microtime(true)` passed where a monotonic instant belongs
     * is the one deadline mistake whose consequence is invisible (the bound
     * simply never fires), so the implausible magnitude is refused loudly.
     * The threshold sits far above any real uptime (1e9 monotonic seconds is
     * ~31 years) and far below wall-clock seconds (~1.76e9 today).
     */
    private static function refuseImplausibleDeadline(?float $deadline): void
    {
        if ($deadline !== null && is_finite($deadline) && $deadline > 1.0e9) {
            throw new \InvalidArgumentException(sprintf(
                'MCP deadline %.6F is not a monotonic instant (hrtime clocks stay under 1e9 seconds ~ 31 years of uptime); this looks like wall-clock seconds. Compute it with StdioMcpServer::deadlineAfter(the seconds to wait), or pass null for no bound.',
                $deadline,
            ));
        }
    }

    private static function nowSeconds(): float
    {
        return hrtime(true) / 1_000_000_000.0;
    }

    /** @param resource|null $process */
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
        $livenessCheckedAt = self::nowSeconds();

        while ($payload !== '') {
            $this->beat();
            $remaining = $deadline === null ? null : $deadline - self::nowSeconds();
            if ($remaining !== null && $remaining <= 0.0) {
                return false;
            }

            $write = [$this->pipes[0]];
            $read = $this->stderrOpen && is_resource($this->pipes[2]) ? [$this->pipes[2]] : [];
            $except = [];
            [$seconds, $micros] = $this->pollSlice($remaining);

            $ready = @stream_select($read, $write, $except, $seconds, $micros);

            if ($ready === false) {
                // select fails on EINTR (and on interrupt-driven storms). A
                // dead child ends this immediately; a live one gets the
                // bounded-retry ceiling before we give up.
                $consecutiveSelectFailures++;

                if (!$this->serverIsRunning()
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
                // stdin stayed unwritable for this pass. Same liveness law as
                // readLine(): a helper that inherited stdin keeps the pipe from
                // ever breaking, so a full pipe to a dead direct child would
                // stall a deadline-less write (callTool) until the helper
                // exits — and stderr chatter keeps the select from going idle,
                // so the check is rate-limited per poll, not gated on idleness.
                if (self::nowSeconds() - $livenessCheckedAt >= self::READ_POLL_SECONDS) {
                    if (!$this->serverIsRunning()) {
                        return false;
                    }
                    $livenessCheckedAt = self::nowSeconds();
                }

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
     * notifications, foreign ids AND unparseable lines. Only EOF, the
     * deadline, or a stdout-idle poll on a dead child ends the search.
     *
     * Skipping garbage is what the reference SDK clients do (measured against them):
     * real servers print a startup banner or a log line to stdout, or emit a
     * blank keep-alive line (readLine() trims it to ''), and the genuine reply
     * follows on the next line. Treating that line as fatal made start() fail
     * with "Failed to start MCP server" and callTool() report "Tool call
     * failed" while the answer was already in the pipe. Skipping cannot match
     * a stale reply: ids are unique per exchange across processes
     * ({@see RequestIdSequence}) and the match below is strict.
     *
     * The same rule covers recovery from a holder that died mid-exchange: the
     * stream may start with the tail of a line it half consumed, and that
     * fragment is skipped like any other unparseable line.
     *
     * One unparseable shape is NOT noise: a JSON-RPC 2.0 envelope carrying OUR
     * id but neither `result` nor `error`. That is the server's answer, and
     * it is broken — the call fails now instead of waiting on a deadline-less
     * callTool() waiting on a reply that already came.
     *
     * The strike law bounds the OTHER silence-shaped hole: a server that
     * keeps the pipe busy with frames no exchange can ever be attributed to
     * — blank-id result pushes, batches, unparseable chatter answering
     * nothing. Skipping those without end is a deadline-less hang on a
     * non-conforming server, so each read counts them and refuses the
     * exchange after {@see UNATTRIBUTABLE_FRAMES_BEFORE_REFUSAL}; the
     * refusal throws and callTool() reports it as an error payload (the call
     * error rails), while the handshake legs fail the start loudly. Method-bearing frames are exempt
     * from the count by design: they are ANSWERED (or fire the notification
     * handler), so a server that streams progress forever is within its
     * rights; the convoy cost of a wedged answerer is the documented
     * serialisation law — one pipe, one conversation — not a counting defect.
     *
     * $deadline is taken by reference because answering a server request
     * pauses it: the wall clock a server-request handler spends is added
     * back, so an answerer that waits (an elicitation held for a human) does
     * not consume the bounded call's budget — the wait this client owes is
     * not the server being slow. A leg that DIES by exception grants no
     * credit: the throw itself ends the bounded wait, so an extension is
     * meaningless and the honest story is told by the error payload instead.
     */
    private function readResponse(string $id, ?float &$deadline = null): ?McpMessage
    {
        $unattributable = 0;
        $blanks = 0;

        while (true) {
            if ($deadline !== null && self::nowSeconds() >= $deadline) {
                return null;
            }

            $line = $this->readLine($deadline);
            if ($line === null) {
                return null;
            }

            $message = McpMessage::parse($line);
            if ($message === null) {
                if (self::isMalformedReplyTo($line, $id)) {
                    // The envelope answers OUR id while carrying neither
                    // result nor error: that is the (broken) reply, not noise
                    // to skip. Name it so callTool's payload says WHAT ended
                    // the wait instead of the generic no-reply wording.
                    $this->lastFailureCause = 'the server answered neither result nor error';

                    return null;
                }

                // Strike: a NON-BLANK frame that is not even JSON-RPC
                // (banner, batch array, garbage). Blank lines do not join
                // the strike bucket — they are the documented keep-alive
                // shape — but they get their OWN, far higher ceiling: an
                // endless stream of bare newlines used to hold a
                // deadline-less callTool() forever, reopening exactly the
                // starvation class the strike law closed.
                if ($line !== '') {
                    $this->refuseAfterUnattributableFrames($id, ++$unattributable);
                } elseif (++$blanks >= self::MAX_BLANK_FRAMES_BEFORE_REFUSAL) {
                    throw new \RuntimeException(sprintf(
                        'MCP server %s held the reply to request %s behind %d blank frames',
                        $this->name,
                        $id,
                        $blanks,
                    ));
                }

                continue;
            }

            // The server-request law: a frame carrying a METHOD is the
            // server speaking —
            // its own request (ping, roots/list, elicitation/create) or a
            // notification (progress). Answer it inline, under the exchange
            // lock this read already holds, then keep waiting for the
            // response we came for. Skipping these unanswered (the historic
            // shape) is what left ping/roots/elicitation dead traffic.
            if ($message->method !== null) {
                $this->answerServerMessage($message, $line, $this->callServerMessage ?? $this->onServerMessage, $deadline);

                continue;
            }

            // Strict match: only a genuine response (no method) whose id is
            // byte-equal to ours answers the request. An id-less broadcast
            // — a server spontaneously pushing `result`/`error` with no
            // id, or one whose id was un-coercible (float/nested) so
            // parse() stored null — must never be mistaken for OUR
            // response: the measured probe showed a looser guard let
            // exactly that shape through.
            if (!$message->isResponse() || $message->id !== $id) {
                // Strike: a payload-shaped frame (result, no method)
                // carrying NO id at all — the answer-is-coming-forever
                // shape. A foreign response WITH an id stays exempt: it is a
                // late answer to somebody else's (often this client's own
                // abandoned) call, and skipping it without blame is the
                // whole point of strict id matching. Method-bearing frames
                // no longer reach this check: the server-request
                // interception above answers them first.
                if ($message->id === null && $message->method === null && $message->error !== null) {
                    // The exception that proves the rule: JSON-RPC mandates
                    // a NULL id on parse-error (-32700) and invalid-request
                    // (-32600) replies, and this client PROVOKE exactly that
                    // shape whenever the dead-holder repair prefixed "\n"
                    // onto our write. Such a frame answers OUR malformed
                    // request; a strict id matcher can never consume it, so
                    // striking it let a deadline-less callTool() wait
                    // forever on a reply that had already come. Name the
                    // cause and end the wait.
                    $this->lastFailureCause = sprintf(
                        'the server refused an unparseable frame (error code %s)',
                        $message->errorCode() ?? 'unknown',
                    );

                    return null;
                }

                if ($message->id === null && $message->method === null && $message->resultSet) {
                    $this->refuseAfterUnattributableFrames($id, ++$unattributable);
                }

                continue;
            }

            return $message;
        }
    }

    /**
     * Answer one method-bearing frame while an exchange awaits its
     * response. Notifications fire the handler (return ignored, nothing is
     * written back); requests consult the handler and fall back to the
     * built-in default — acknowledge `ping` with the empty-object result the
     * schema wants, refuse everything else with JSON-RPC's "Method not
     * found". The reply echoes the request's id with its wire TYPE intact
     * (an int id must not come back as a string), and the wall clock the
     * handler spent is added back to $deadline so a waiting answerer never
     * eats the bounded call's budget.
     *
     * A handler exception propagates from this method — a broken answerer
     * should surface loudly rather than as a silently wrong reply — and
     * callTool()'s error rails convert it into an `['error' => ...]` payload
     * that NAMES the handler class and message, never a mislabelled
     * argument-encoding failure and never an exception escaping into the
     * caller's tool loop.
     *
     * @param (\Closure(string, ?array<string,mixed>, bool): ?array)|null $handler
     */
    private function answerServerMessage(McpMessage $message, string $line, ?\Closure $handler, ?float &$deadline): void
    {
        $startedAt = self::nowSeconds();

        if ($message->isNotification) {
            // A notification is answered by the handler alone; the time it
            // spends is still a wait this client owes, so it earns the
            // pause credit whether it completes or dies on the way out.
            try {
                if ($handler !== null) {
                    $handler($message->method, $message->params, true);
                }
            } finally {
                if ($deadline !== null) {
                    $deadline += self::nowSeconds() - $startedAt;
                }
            }

            return;
        }

        {
            $answer = $handler !== null ? $handler($message->method, $message->params, false) : null;

            if ($answer === null) {
                $answer = $message->method === 'ping'
                    ? ['result' => new \stdClass()]
                    : ['error' => ['code' => -32601, 'message' => 'Method not found']];
            }

            if (!is_array($answer)) {
                throw new \InvalidArgumentException('MCP server-request handler must return an array or null, ' . get_debug_type($answer) . ' given');
            }

            $raw = McpMessage::parsePreservingId($line);
            $wireId = $raw !== null && $raw->wireIdSet ? $raw->wireId : $message->id;

            if (array_key_exists('error', $answer)) {
                $error = $answer['error'];
                if (!is_array($error) || !isset($error['code'], $error['message'])
                    || !is_int($error['code']) || !is_string($error['message'])) {
                    throw new \InvalidArgumentException('MCP server-request handler returned a malformed error answer');
                }

                $response = self::requesterReply($wireId, static fn (string $id): McpMessage => McpMessage::error($id, $error['code'], $error['message'], $error['data'] ?? null));
            } elseif (array_key_exists('result', $answer)) {
                $response = self::requesterReply($wireId, static fn (string $id): McpMessage => McpMessage::success($id, $answer['result']));
            } else {
                throw new \InvalidArgumentException('MCP server-request handler must return ["result" => ...] or ["error" => ...], got keys ' . implode(',', array_keys($answer)));
            }

            // Bill the handler's thinking back to the call BEFORE the answer
            // is written — the pause is worth nothing if the write itself
            // is refused against the un-extended deadline. A leg that threw
            // on its way here granted nothing: its exception ends the
            // bounded wait outright (see the docblock).
            if ($deadline !== null) {
                $deadline += self::nowSeconds() - $startedAt;
            }

            if (!$this->writeLine($response->toJson(), $deadline)) {
                // m3: under back-pressure or a dead stdin the server's
                // request goes silently unanswered. The awaited response
                // may still arrive; if the wait then ends empty, this is
                // the reason the payload names.
                $this->lastFailureCause = 'could not deliver the reply to the server request ' . (string) $message->method;
            }
        }
    }

    /**
     * Build the reply a server REQUEST deserves, carrying the id exactly as
     * it arrived. A request whose id this client cannot address (absent,
     * explicit null, float, nested) gets JSON-RPC's "Invalid Request" with
     * a null id — inventing an id for a reply to an unreadable request is
     * how streams become undiagnosable.
     *
     * @param \Closure(string): McpMessage $build
     */
    private static function requesterReply(string|int|null $wireId, \Closure $build): McpMessage
    {
        if (is_int($wireId)) {
            return $build((string) $wireId)->withWireId($wireId);
        }

        if (is_string($wireId)) {
            return $build($wireId);
        }

        return McpMessage::error('', -32600, 'Invalid Request')->withWireId(null);
    }

    /**
     * The strike ceiling: how many skipped unattributable frames one exchange tolerates
     * before the wait is judged hopeless. Eight bounds a deadline-less
     * callTool() against a server whose every frame is junk-to-us while
     * staying far above the sloppiness the pinned fixtures model — the rogue
     * id-less broadcast and the noisy stdout banner each cost AT MOST two
     * strikes per leg, and any genuine reply resets the count by ending the
     * read.
     */
    private const UNATTRIBUTABLE_FRAMES_BEFORE_REFUSAL = 8;

    /**
     * How many BLANK frames one exchange tolerates before refusing it.
     * Newlines are the documented keep-alive shape, so they never join the
     * strike bucket — but a server holding the real reply behind an endless
     * stream of blanks used to make that exemption an unbounded wait on a
     * deadline-less callTool(). The ceiling sits far above any honest
     * keep-alive rate (16 bytes of newline per line, a 64 KiB frame fits
     * 65536 of them — one burst of frame-sized keep-alive still passes,
     * a permanent flood refuses) so the fix closes the starvation class
     * without accusing a server that merely heartbeats.
     */
    private const MAX_BLANK_FRAMES_BEFORE_REFUSAL = 1024;

    /**
     * Refuse the outstanding exchange once $count of its skipped frames could
     * be attributed to no request. The throw rides the error rails in
     * callToolWaiting (error payload, not an escaped exception) for tool
     * calls, and the start() reap rail for handshake legs; the stream itself
     * keeps its framing — only this exchange is abandoned.
     */
    private function refuseAfterUnattributableFrames(string $id, int $count): void
    {
        if ($count < self::UNATTRIBUTABLE_FRAMES_BEFORE_REFUSAL) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'MCP server %s sent %d consecutive frames attributable to no request (no id, or not JSON-RPC) '
            . 'while the reply to id %s was awaited; the stream does not answer this client and the '
            . 'exchange was refused rather than left to wait forever',
            $this->name,
            $count,
            $id,
        ));
    }

    /**
     * True when $line, which McpMessage::parse() refused, is still a JSON-RPC
     * 2.0 response envelope addressed to $id (see readResponse()). The id is
     * coerced the way parse() coerces it, so an integer id matches its string.
     * The decode depth mirrors McpMessage::MAX_DEPTH on purpose: a reply the
     * real parser refuses for nesting must not be READ as a malformed reply
     * here — that would blame the server for "answering neither result nor
     * error" when the honest verdict is "unparseable", the same bucket every
     * other unreadable line strikes into.
     */
    private static function isMalformedReplyTo(string $line, string $id): bool
    {
        $decoded = json_decode($line, true, McpMessage::MAX_DEPTH);
        if (!is_array($decoded) || ($decoded['jsonrpc'] ?? null) !== '2.0') {
            return false;
        }

        if (isset($decoded['method']) || !isset($decoded['id'])) {
            return false;
        }

        $wireId = $decoded['id'];

        return (is_string($wireId) || is_int($wireId)) && (string) $wireId === $id;
    }

    /**
     * Return one newline-terminated frame, or null at EOF/deadline/dead child
     * with an empty buffer. A partial buffer at EOF/deadline is delivered whole
     * (drainBuffer) — unterminated tail bytes are the sender's framing bug and
     * deserve the same parse-failure path, not silent discarding.
     *
     * The scan carries a floor offset ($scannedFrom): bytes already inspected
     * for "\n" are never re-scanned, so accumulating one huge unterminated
     * frame costs O(n) CPU, not O(n²) — otherwise an adversarial server
     * streaming garbage burns this side's CPU long before the frame cap
     * refuses it. The floor is per-call; a leftover buffer from a previous
     * call rescans at most its own length once.
     */
    private function readLine(?float $deadline = null): ?string
    {
        $scannedFrom = 0;
        $livenessCheckedAt = self::nowSeconds();
        $selectFailures = 0;

        while (($newline = strpos($this->readBuffer, "\n", $scannedFrom)) === false) {
            $this->beat();
            if ($this->pipes === null) {
                return $this->readBuffer === '' ? null : $this->drainBuffer();
            }

            $remaining = $deadline === null ? null : $deadline - self::nowSeconds();
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
            [$seconds, $micros] = $this->pollSlice($remaining);

            $ready = @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                if (feof($this->pipes[1])) {
                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                // Bounded retry, mirroring writeLine: EINTR with a live
                // stream yields briefly and re-polls — but a select that
                // keeps failing against a dead server used to livelock
                // forever here, because the liveness probe only rode the
                // ready===0 branch, unreachable while select kept failing.
                ++$selectFailures;
                $alive = $this->serverIsRunning();

                if (!$alive || $selectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES) {
                    $this->lastFailureCause = $alive
                        ? sprintf('the read pipe failed %d consecutive selects (EINTR storm?)', $selectFailures)
                        : 'the server process exited';

                    return $this->readBuffer === '' ? null : $this->drainBuffer();
                }

                usleep(1000);

                continue;
            }

            $selectFailures = 0;

            if ($ready === 0) {
                // An idle poll is the liveness check (see the class docblock):
                // pipe EOF never comes while a grandchild holds the write end,
                // and callTool() has no deadline to fall back on. Nothing was
                // pending on stdout for a whole poll, so a dead direct child
                // has nothing more to say — whatever a surviving helper might
                // write is not an answer from the server we spawned.
                if (!$this->serverIsRunning()) {
                    $this->lastFailureCause = 'the server process exited';

                    return $this->frameLeftByAnExitedServer();
                }
                $livenessCheckedAt = self::nowSeconds();

                continue;
            }

            if ($this->stderrOpen && in_array($this->pipes[2], $read, true)) {
                $this->absorbStderr();
            }

            if (!in_array($this->pipes[1], $read, true)) {
                // stderr-ready-only pass: stdout stayed silent. A helper that
                // inherited stderr too and logs more often than a poll never
                // lets the select go idle, so the idle-poll check above would
                // never run — the liveness check must ride this pass as well,
                // rate-limited to once per poll's worth of stdout silence so a
                // chatty LIVE server costs no proc_get_status per log line.
                if (self::nowSeconds() - $livenessCheckedAt >= self::READ_POLL_SECONDS) {
                    if (!$this->serverIsRunning()) {
                        $this->lastFailureCause = 'the server process exited';

                        return $this->frameLeftByAnExitedServer();
                    }
                    $livenessCheckedAt = self::nowSeconds();
                }

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
            $scannedFrom = strlen($this->readBuffer);
            $this->readBuffer .= $chunk;
            $this->refuseAnOversizedFrame();
            $livenessCheckedAt = self::nowSeconds();
        }

        $line = substr($this->readBuffer, 0, $newline);
        $this->readBuffer = substr($this->readBuffer, $newline + 1);

        return trim($line);
    }

    /**
     * The select timeout for one wait pass, as [seconds, microseconds]: the
     * whole remaining budget when nothing else needs the clock, never more
     * than READ_POLL_SECONDS while a caller is listening for wait beats (a
     * deadline far away must not turn one select into a minute of silence),
     * and the plain poll slice when there is no deadline at all.
     *
     * @return array{0: int, 1: int}
     */
    private function pollSlice(?float $remaining): array
    {
        if ($remaining === null) {
            return [self::READ_POLL_SECONDS, 0];
        }

        if ($this->onWait !== null) {
            $remaining = min($remaining, (float) self::READ_POLL_SECONDS);
        }

        $seconds = (int) $remaining;

        return [$seconds, (int) (($remaining - $seconds) * 1_000_000)];
    }

    /** Fire the in-flight call's wait beat, if it has one. */
    private function beat(): void
    {
        if ($this->onWait !== null) {
            ($this->onWait)();
        }
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
            $this->stderrTruncated = true;
        }
    }

    /** The server's own refusal (code + message) for the start-failure exception. */
    private function describeError(string $leg, McpMessage $response): string
    {
        $code = $response->errorCode();
        $message = $response->errorMessage();

        return sprintf(
            ' — %s refused%s: %s',
            $leg,
            $code === null ? '' : " ({$code})",
            // Partial output: a decoded error may carry INF (a `1e999` on the
            // wire), and an unencodable diagnosis must still say something.
            // The bytes are the SERVER's — control characters are swept so a
            // naive printer is not injected with, but the TEXT remains
            // UNTRUSTED: render through Sanitize::untrusted for anything
            // terminal-facing.
            self::printableDiagnostic($message === null || $message === '' ? (string) json_encode($response->error, JSON_PARTIAL_OUTPUT_ON_ERROR) : $message),
        );
    }

    /** Human-readable stderr fragment for the start-failure exception. */
    private function stderrTailForDiagnostics(): string
    {
        $tail = trim($this->stderrTail);

        if ($tail === '') {
            return '';
        }

        // The flag is set where bytes were ACTUALLY dropped (an exactly-
        // full tail is not "truncated"); the tail is server-authored bytes,
        // swept here for the same terminal-injection reason describeError
        // sweeps error messages, and still UNTRUSTED text.
        return ($this->stderrTruncated ? ' [stderr truncated] ' : ' stderr: ') . self::printableDiagnostic($tail);
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

    /**
     * The read's answer once the direct child is known dead: whatever stdout
     * already holds, never a further wait. The liveness probe runs AFTER the
     * poll that saw stdout empty, so a server that wrote its reply and exited
     * inside that window left the reply in the pipe — it is drained here with
     * zero-timeout polls instead of being dropped. The drain is bounded by
     * the frame cap (a surviving helper streaming stdout cannot pin it), and
     * the first complete frame wins; a partial tail is delivered whole, as
     * at EOF.
     */
    private function frameLeftByAnExitedServer(): ?string
    {
        while ($this->pipes !== null && is_resource($this->pipes[1])
            && strpos($this->readBuffer, "\n") === false
        ) {
            $read = [$this->pipes[1]];
            $write = [];
            $except = [];
            if (@stream_select($read, $write, $except, 0, 0) !== 1) {
                break;
            }

            $chunk = fread($this->pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->readBuffer .= $chunk;
            $this->refuseAnOversizedFrame();
        }

        $newline = strpos($this->readBuffer, "\n");
        if ($newline === false) {
            return $this->readBuffer === '' ? null : $this->drainBuffer();
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
     * Gate a `tools/list` reply and FOLLOW ITS CURSOR until the roster is
     * whole (the same walk candy-code's HttpMcpServer does — the
     * spec says `tools/list` MAY paginate, and caching the FIRST page as the
     * whole truth leaves tools silently missing from every later dispatch).
     * Each follow-up page goes through the SAME gate as the first, inside the
     * SAME handshake deadline; a cursor that repeats is a server lying about
     * its pagination, refused rather than looped. Page 1's refusal messages
     * stay byte-identical to the pre-pagination gate every existing pin
     * asserts; later pages name their page number.
     *
     * A throwing helper is reap-safe from here: start()'s catch stops the
     * child for any throw, exactly as the inline gate's stop() used to.
     *
     * @return list<McpTool>
     */
    private function collectToolPages(?McpMessage $first, ?float &$deadline): array
    {
        $data = $first?->toArray();
        $tools = $this->requireToolsListPage($first, $data, 1);
        $seenCursors = [];

        for ($page = 2; $page <= self::MAX_TOOL_PAGES; $page++) {
            $cursor = is_array($data) ? ($data['result']['nextCursor'] ?? null) : null;

            if (!is_string($cursor) || $cursor === '') {
                return $tools;
            }

            if (isset($seenCursors[$cursor])) {
                throw new \RuntimeException(
                    'tools/list repeated cursor ' . $cursor . ' at page ' . $page
                    . ' — refusing to walk a pagination loop',
                );
            }
            $seenCursors[$cursor] = true;

            $response = $this->requestAs((string) $this->ids->next(), 'tools/list', ['cursor' => $cursor], $deadline);
            $data = $response?->toArray();
            $tools = array_merge($tools, $this->requireToolsListPage($response, $data, $page));
        }

        // MAX pages spent and the last reply still handed out a cursor: a
        // roster KNOWN to be cut short must not masquerade as a whole one —
        // missing tools are invisible downstream, exactly the failure this
        // walk exists to prevent. Fail the start the way an error reply does.
        throw new \RuntimeException('tools/list still had a nextCursor after '
            . self::MAX_TOOL_PAGES . ' pages — refusing to cache a truncated roster');
    }

    /**
     * The one gate every `tools/list` page passes, at any page number: an
     * ERROR reply (session- or capability-gated servers answer -32601 here)
     * or no reply at all is not "up, 0 tools" — that shape looks connected,
     * exposes nothing, and throws the server's own diagnosis away.
     *
     * @param array<mixed>|null $data the reply's wire shape, for the tools body
     * @return list<McpTool>
     */
    private function requireToolsListPage(?McpMessage $response, ?array $data, int $page): array
    {
        if ($response === null || $response->error !== null || !$response->resultSet) {
            $diagnostics = $this->stderrTailForDiagnostics();
            $refusal = $response?->error !== null ? $this->describeError('tools/list', $response) : ' — no tools/list reply';
            if ($page > 1) {
                $refusal .= " (page {$page})";
            }

            throw new \RuntimeException("Failed to start MCP server: {$this->name}" . $refusal . $diagnostics);
        }

        return $this->parseTools($data ?? []);
    }

    /**
     * Extract tool definitions from a tools/list response through the
     * well-typedness gate. Three nested guards, each load-bearing: the
     * container (`result.tools` may be any JSON type), the entries (mixed
     * arrays arrive in the wild), and the per-definition type check inside
     * McpTool::tryFromArray.
     *
     * The guards SKIP and COUNT — they never abort. A definition the gate
     * cannot build is exactly the half-trusted roster entry that must not
     * enter dispatch, so it is dropped; but dropping it must not take the
     * server's valid siblings, let alone every OTHER configured server,
     * down with it. Every drop is recorded in {@see $malformedToolDefs}
     * and announced through {@see malformedToolDefinitionsReport()}, so
     * "advertised 3 malformed definitions" stays diagnosable. Server-level
     * failures (error reply, no reply, lying pagination) STILL throw —
     * those say nothing was received at all, and McpClient contains them
     * to the one server.
     *
     * @param array<string,mixed> $response inspection-view array
     * @return list<McpTool>
     */
    private function parseTools(array $response): array
    {
        $toolDefs = $response['result']['tools'] ?? [];

        if (!is_array($toolDefs)) {
            // A wrong-typed tools container is recorded and answers as the
            // empty roster this server actually delivered. Iterating a
            // scalar is a PHP warning, so the shape is checked once here —
            // the same family as the per-entry is_array($def) skip below.
            $this->malformedToolDefs[] = sprintf('tools container was %s', get_debug_type($toolDefs));
            ++$this->malformedToolDefsTotal;

            return [];
        }

        $tools = [];
        $dropped = [];

        foreach ($toolDefs as $def) {
            $tool = is_array($def) ? McpTool::tryFromArray($def, $this->name) : null;

            if ($tool === null) {
                // Counted, never silently vanished: "advertised 3 malformed
                // definitions" is diagnosable; a shorter tool list than the
                // server promised is not. The name that failed the gate is
                // EXACTLY the text the -004 control-gate exists to refuse,
                // so the ledger never gets to announce it raw: sanitize to
                // printable bytes and cap the row, same posture the stderr
                // tail keeps. The get_debug_type rows are already ours.
                $dropped[] = is_array($def) && isset($def['name']) && is_string($def['name'])
                    ? mb_substr(self::printableDiagnostic($def['name']), 0, 120)
                    : get_debug_type($def);
            } else {
                $tools[] = $tool;
            }
        }

        // Capped merge: one `tools/list` carrying thousands of junk entries
        // must not mint thousands of ledger rows (memory DoS through the
        // diagnostic path). The ceiling bounds the VIEW; the total keeps
        // the roster-size claim honest.
        $this->malformedToolDefsTotal += count($dropped);
        $room = self::MALFORMED_LEDGER_CEILING - count($this->malformedToolDefs);

        if ($room > 0) {
            $this->malformedToolDefs = [...$this->malformedToolDefs, ...array_slice($dropped, 0, $room)];
        }

        // A LEGAL empty roster stays legal: a server with no tools is real.
        return $tools;
    }
}
