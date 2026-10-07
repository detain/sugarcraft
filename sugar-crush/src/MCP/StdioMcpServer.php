<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\Support\ProcessContainment;

/**
 * The product-side adapter over the sugar-mcp stdio transport
 * (`sugarcraft/sugar-mcp`, {@see \SugarCraft\Mcp\StdioMcpServer}).
 *
 * PHASE-2a REWIRE: framing, the NDJSON read buffer, the 64 MiB frame cap, the
 * handshake exchange, the stderr drain, the closed-pipe guards and the bounded
 * teardown all moved INTO the library, where their suites now live (the
 * library's StdioMcpServerTest, StderrDrainTest-shape rows and frame-cap rows).
 * What deliberately STAYS here is product policy the library refuses to own:
 *
 *  - E672/E674 containment, injected through the library's `$spawnPlanner`
 *    seam: the PATH pre-check that refuses a bogus binary BEFORE any child
 *    exists, the setsid-wrapped spawn spec, and the curated environment —
 *    see {@see spawnPlan()}.
 *  - the product identity the handshake advertises — `sugar-crush` `1.0.0`,
 *    injected through the library's `$clientInfo` seam, because a third-party
 *    server's logs should name the tool the user configured, not a library.
 *  - the crush-side value shapes: this class keeps implementing
 *    {@see McpServer}, converts the library's McpTool rows into the product's
 *    {@see McpTool}, and keeps the FQCN every existing consumer
 *    ({@see McpClient}'s instanceof narrowing and construction) already names.
 *
 * The historical doc-blocks that made the argv-vs-shell spawn measurement, the
 * stderr-drain deadlock, and the close-pipes-before-signal ordering load-bearing
 * ride on in the library class; this file does not restate them.
 *
 * FORK SAFETY lives in the library too (audit B1/AG-1): servers start in the
 * TUI parent and are called from every forked turn, sub-agent and parallel
 * Task, so ONE server is shared by the process tree — with process-unique
 * request ids, each exchange serialised under a cross-process lock, the unread
 * stdout bytes kept in that lock's file, recovery after a holder is killed
 * mid-exchange, and teardown reserved to the process that started it. See
 * the library class's FORK SAFETY note.
 */
final class StdioMcpServer implements McpServer
{
    /**
     * Handshake ceiling default, DERIVED from the library constant so the two
     * cannot drift — the library doc-block explains why the number is
     * SIXTY SECONDS (cold `npx` package trees) and why it is overridable per
     * server from `.mcp.json` (`startTimeout`, in seconds) — see
     * {@see \SugarCraft\Crush\MCP\McpClient::startServer()}.
     */
    public const DEFAULT_START_TIMEOUT_SECONDS = \SugarCraft\Mcp\StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS;

    /**
     * Per-`tools/call` ceiling default (cl-4 FIX-C). The library exposes no
     * such constant — the number is crush's product decision: a hung third-
     * party stdio server used to wedge its turn FOREVER (an unbounded call
     * ignores every wait beat, and the turn's idle watchdog only fires when
     * the pump itself stalls). 120 s matches the turn's 120 s idle ceiling:
     * a call that outlasts the turn's own patience must not outlast the
     * session. Legitimately slow servers raise it per entry with
     * `toolTimeout`; there is deliberately no way to switch the bound off,
     * the same policy {@see DEFAULT_START_TIMEOUT_SECONDS} enforces for the
     * handshake — a hand-edited config must not disable a ceiling by accident.
     */
    public const DEFAULT_TOOL_TIMEOUT_SECONDS = 120.0;

    private readonly \SugarCraft\Mcp\StdioMcpServer $transport;

    /** Resolved per-call ceiling in seconds — always positive (cl-4 FIX-C). */
    private readonly float $toolTimeoutSeconds;

    /**
     * Credential-shaped names the last spawn plan withheld from the server
     * (item 0.14-b) — names only, for the launch notice.
     *
     * @var list<string>
     */
    private array $strippedSecretEnv = [];

    /**
     * @param array<int, string> $args argv AFTER the program name — the argv
     *        form, never a shell string; see the library's start() doc-block
     *        for the measured process-tree reason
     * @param array<string, string> $env
     * @param float|null $startTimeoutSeconds handshake budget in seconds; null
     *        takes {@see DEFAULT_START_TIMEOUT_SECONDS}
     * @param float|null $toolTimeoutSeconds the `.mcp.json` `toolTimeout`
     *        (item 0.5, decision D2, bounded-by-default per cl-4 FIX-C): a
     *        bound on each `tools/call`; null or a non-positive takes
     *        {@see DEFAULT_TOOL_TIMEOUT_SECONDS} — a call is never unbounded
     */
    public function __construct(
        public readonly string $name,
        string $command,
        array $args,
        array $env,
        ?float $startTimeoutSeconds = null,
        ?float $toolTimeoutSeconds = null,
    ) {
        $this->toolTimeoutSeconds = $toolTimeoutSeconds !== null && $toolTimeoutSeconds > 0.0
            ? $toolTimeoutSeconds
            : self::DEFAULT_TOOL_TIMEOUT_SECONDS;

        $this->transport = new \SugarCraft\Mcp\StdioMcpServer(
            name: $name,
            command: $command,
            args: $args,
            env: $env,
            startTimeoutSeconds: $startTimeoutSeconds,
            spawnPlanner: fn (string $name, array $argv, array $env): array => $this->spawnPlan($name, $argv, $env),
            clientInfo: ['name' => 'sugar-crush', 'version' => '1.0.0'],
        );
    }

    /**
     * Containment seam handed to the library: refuse, wrap, scrub — in that
     * order, all BEFORE proc_open sees a byte of it.
     *
     * E672: the wrapper-fronts-spawn pre-check, symmetric with
     * LspConnection::connect() and ProcessExecutor's worker spawn — a bogus
     * binary under `setsid` starts the WRAPPER fine and the exec failure would
     * otherwise surface only as a missing handshake after the full timeout.
     *
     * E672/E674: the choke-point spec + env pair — a third-party MCP server
     * from `.mcp.json` is exactly the untrusted command class containment is
     * for; the entry's own env rides as overrides so configured keys still win.
     *
     * 0.14-b (decision D3): the env is {@see ProcessContainment::mcpEnv()} —
     * inherited credentials are SCRUBBED unless the entry declares them in
     * its `env` block or `secretEnvAllowlist` releases them. The names held
     * back are recorded for {@see strippedSecretEnv()}.
     *
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return array{0: string|array<int,string>, 1: ?array<string,string>}
     */
    private function spawnPlan(string $name, array $argv, array $env): array
    {
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($argv[0], '/')
                ? is_executable($argv[0])
                : ProcessContainment::locateOnPath($argv[0]) !== '')
        ) {
            throw new \RuntimeException("Failed to start MCP server: {$name}");
        }

        $this->strippedSecretEnv = ProcessContainment::strippedSecretEnvNames($env);

        return [ProcessContainment::spawnSpec($argv), ProcessContainment::mcpEnv($env)];
    }

    /**
     * The inherited credential-shaped variables the last start() withheld
     * from this server, sorted — what a launch notice names so a server that
     * then fails to authenticate is explainable. Empty before start().
     *
     * @return list<string>
     */
    public function strippedSecretEnv(): array
    {
        return $this->strippedSecretEnv;
    }

    /**
     * @throws \RuntimeException when the program is refused by the pre-check,
     *         cannot be spawned, or the handshake does not complete in budget
     */
    public function start(): void
    {
        $this->transport->start();
    }

    public function stop(): void
    {
        $this->transport->stop();
    }

    /**
     * Caller-pumped stderr drain (E537: never loop-mounted). Forwards to the
     * library; dormant-safe before start() and after stop().
     */
    public function pumpStderr(): void
    {
        $this->transport->pumpStderr();
    }

    /**
     * E698 liveness readout for the `/mcp` panel — the library answers the
     * same proc_get_status question the product did in the process that
     * started the server, and probes the server pid from a forked one.
     */
    public function isUp(): bool
    {
        return $this->transport->isUp();
    }

    /**
     * Library tools converted into the product's {@see McpTool} rows.
     *
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        return array_map(
            static fn (\SugarCraft\Mcp\McpTool $tool): McpTool => new McpTool(
                $tool->name,
                $tool->description,
                $tool->inputSchema,
                $tool->serverName,
                // Roadmap 5.11-1: the server's `annotations` ride through, or a
                // stdio server's `readOnlyHint` would never reach the gate.
                $tool->annotations,
            ),
            $this->transport->listTools(),
        );
    }

    /**
     * BOUNDED BY DEFAULT, like {@see start()}'s handshake — see the library's
     * callTool(). Every call carries the entry's `toolTimeout` or
     * {@see DEFAULT_TOOL_TIMEOUT_SECONDS} (the constructor resolves both);
     * at the deadline the library sends `notifications/cancelled`, raises the
     * named timeout error, and the bridge turns it into an error payload for
     * the model — a wedged server costs one call, never the session. $onWait
     * — the library's wait beat (item 0.4-b) — still keeps a long call
     * visibly alive until that deadline.
     *
     * @param (\Closure(): void)|null $onWait
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args, ?\Closure $onWait = null): array
    {
        return $this->transport->callTool($toolName, $args, $onWait, $this->toolTimeoutSeconds);
    }

    /** The resolved per-call bound in seconds — never null since cl-4 FIX-C. */
    public function toolTimeoutSeconds(): float
    {
        return $this->toolTimeoutSeconds;
    }
}
