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

    private readonly \SugarCraft\Mcp\StdioMcpServer $transport;

    /**
     * @param array<int, string> $args argv AFTER the program name — the argv
     *        form, never a shell string; see the library's start() doc-block
     *        for the measured process-tree reason
     * @param array<string, string> $env
     * @param float|null $startTimeoutSeconds handshake budget in seconds; null
     *        takes {@see DEFAULT_START_TIMEOUT_SECONDS}
     * @param float|null $toolTimeoutSeconds the `.mcp.json` `toolTimeout`
     *        (item 0.5, decision D2): an OPT-IN bound on each `tools/call`;
     *        null — the default — keeps every call unbounded
     */
    public function __construct(
        public readonly string $name,
        string $command,
        array $args,
        array $env,
        ?float $startTimeoutSeconds = null,
        private readonly ?float $toolTimeoutSeconds = null,
    ) {
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

        return [ProcessContainment::spawnSpec($argv), ProcessContainment::env($env)];
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
            ),
            $this->transport->listTools(),
        );
    }

    /**
     * UNBOUNDED BY DEFAULT, unlike {@see start()}'s handshake — see the
     * library's callTool(). A server whose entry sets `toolTimeout` opts in
     * to a per-call bound (the library cancels the request at it); without
     * one, $onWait — the library's wait beat (item 0.4-b) — keeps a long
     * call visibly alive instead of giving it a deadline.
     *
     * @param (\Closure(): void)|null $onWait
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args, ?\Closure $onWait = null): array
    {
        return $this->transport->callTool($toolName, $args, $onWait, $this->toolTimeoutSeconds);
    }

    /** The configured per-call bound in seconds, or null when unbounded. */
    public function toolTimeoutSeconds(): ?float
    {
        return $this->toolTimeoutSeconds;
    }
}
