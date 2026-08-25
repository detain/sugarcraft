<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Crush\Agents\AgentPreset;

final class McpClient
{
    /** @var array<string, McpServer> */
    private array $servers = [];

    private Client $httpClient;

    /**
     * The preset of the agent currently driving this client, if any. When set,
     * listTools()/callTool()/callToolByName() are filtered through McpRouter
     * against $agentPreset->mcpServers.
     */
    private ?AgentPreset $agentPreset;

    /**
     * Escape hatch for callers that are genuinely not agent-scoped (e.g.
     * system/admin tooling). Fails CLOSED by default: with no agent preset
     * attached, listTools()/callTool() see and reach nothing at all, so a
     * caller that forgets to call setAgentPreset() is denied rather than
     * silently handed every configured server's every tool.
     */
    private bool $unrestricted;

    /** @var array<string, string> */
    private array $denyPatterns;

    public function __construct(
        private string $configPath,
        ?Client $httpClient = null,
        ?AgentPreset $agentPreset = null,
        bool $unrestricted = false,
        array $denyPatterns = [],
    ) {
        // Injectable so tests can supply a MockHandler-backed client; defaults to
        // a real client for production use.
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
        $this->agentPreset = $agentPreset;
        $this->unrestricted = $unrestricted;
        $this->denyPatterns = $denyPatterns;
    }

    /**
     * Set (or clear) the preset of the agent driving this client, so that
     * subsequent listTools()/callTool()/callToolByName() calls are routed
     * through McpRouter::resolveAllowedTools() against the preset's mcpServers
     * allowlist rather than exposing every configured server unconditionally.
     */
    public function setAgentPreset(?AgentPreset $agentPreset): void
    {
        $this->agentPreset = $agentPreset;
    }

    /**
     * Set the global wildcard deny-pattern map (server-name pattern => "deny")
     * forwarded to McpRouter on every routed call, alongside the active agent
     * preset's allowlist.
     *
     * @param array<string, string> $denyPatterns
     */
    public function setDenyPatterns(array $denyPatterns): void
    {
        $this->denyPatterns = $denyPatterns;
    }

    /**
     * Load and start MCP servers from config.
     *
     * EVERY ENTRY IS ATTEMPTED, and every one that comes up is registered, even
     * when an earlier or later entry fails — see {@see startServer()} for the
     * three measured routes by which one bad entry used to disable the whole
     * file, and for why the failures are still reported rather than swallowed.
     *
     * @throws \RuntimeException naming every entry whose CONFIG could not be
     *         built — an unrecognised `type` — AFTER all of them have been
     *         attempted, so the throw no longer decides which servers get to
     *         exist. A server whose `start()` merely fails is skipped silently,
     *         as it always was; see {@see startServer()} for why those two are
     *         not the same event. A caller that wants the working servers
     *         regardless catches this and carries on — which is what
     *         {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()} does, and
     *         why that seam is the one place in this package writing both
     *         `error_log()` and the transcript.
     */
    public function startServers(): void
    {
        $config = $this->loadConfig();
        $failures = [];

        foreach ($config['mcpServers'] ?? [] as $name => $serverConfig) {
            $failure = $this->startServer($name, $serverConfig);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        // AFTER the loop, deliberately: this is the whole fix. The throw used to
        // happen ON the offending entry, so entries after it were never even
        // constructed and the same broken file lost a different set of servers
        // depending purely on key order.
        if ($failures !== []) {
            throw new \RuntimeException(sprintf(
                '%d MCP server %s in this config could not be built: %s',
                count($failures),
                count($failures) === 1 ? 'entry' : 'entries',
                implode('; ', $failures),
            ));
        }
    }

    /**
     * Stop all MCP servers.
     */
    public function stopServers(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    /**
     * Start a single server, and NEVER let one entry's failure reach the loop
     * that is starting the others.
     *
     * ⚠️ THIS GUARD PROMISED SOMETHING IT DID NOT DELIVER, BY THREE SEPARATE
     * ROUTES, AND ALL THREE WERE MEASURED.
     * WHAT THIS SAID: "A single unreachable/misbehaving server must not abort
     * loading the rest. An unknown type is a config error and is thrown above,
     * before we get here." Both sentences were wrong in effect.
     * WHAT IS TRUE NOW: the `match` is INSIDE the guard and the guard catches
     * `\Throwable`. The three routes, each measured at this tree (PHP 8.3.6,
     * Linux 6.8) by driving `startServers()` over a two-server `.mcp.json` with
     * the offender FIRST and a well-formed server second:
     *
     *   1. `{"tools":[{"name":5}]}` from a `stdio` or `http` peer raised a
     *      `TypeError` out of `McpTool::fromArray()`. Not a `RuntimeException`,
     *      so it escaped: "tools visible: 0". Closed at source too, by
     *      {@see McpTool::tryFromArray()}.
     *   2. `"type": "sse"` threw `RuntimeException: Unknown MCP server type: sse`
     *      from the `default` arm, which sat OUTSIDE the try. "tools visible: 0".
     *      `sse` is not a typo hazard invented for the test — it is a transport
     *      the MCP specification defines and this port has not implemented, so
     *      the first `.mcp.json` carrying one silently disabled every OTHER
     *      server in the file.
     *   3. Anything else a constructor or a `start()` can raise that is not a
     *      `RuntimeException` — an `\Error` from a third party's output, a
     *      `JsonException`, a `ValueError` off a closed pipe.
     *
     * ⚠️ THE ORDER-DEPENDENCE IS WHY THIS READS AS INTERMITTENT. `startServers()`
     * iterates the config map, so servers listed BEFORE the offender had already
     * started and stayed started. The same broken `.mcp.json` therefore lost
     * everything, something, or nothing depending purely on key order.
     *
     * ⚠️ TWO CATCHES, NOT ONE, AND FLATTENING THEM INTO ONE WAS THIS FIX'S FIRST
     * CUT AND ITS FIRST MISTAKE. The original code distinguished the two classes
     * by WHERE the throw came from, and the distinction is principled:
     *
     *   CONFIG ERROR (the `match`): the file is wrong and only a human can fix
     *       it. Reported. `Bootstrap::mcpClient()` catches the report and writes
     *       it to `error_log()` AND the transcript — the one site in that class
     *       that uses both channels — then carries on with fewer tools.
     *   RUNTIME FAILURE (`start()`): the binary is missing, the host is down,
     *       the handshake timed out. Routine, expected, and SKIPPED SILENTLY, as
     *       it always has been.
     *
     * Collapsing both into one aggregate report was green across the MCP suites
     * and red across four rows that pin those two contracts. What was actually
     * wrong was never the reporting — it was that reporting ABORTED THE LOOP.
     * So both catches stay, both keep their old meaning, and the only change is
     * that the config-error report is deferred to {@see startServers()} until
     * every entry has been attempted. The runtime catch also widens from
     * `\RuntimeException` to `\Throwable`, which is route 3.
     *
     * @return string|null a description of a CONFIG error, to be reported after
     *         every entry has been attempted; null for a clean start and for a
     *         runtime failure alike
     */
    private function startServer(string $name, array $config): ?string
    {
        $type = $config['type'] ?? 'stdio';

        try {
            $server = $this->buildServer($name, $type, $config);
        } catch (\Throwable $e) {
            return sprintf('%s (%s)', $name, $e->getMessage());
        }

        try {
            $server->start();
        } catch (\Throwable) {
            return null;
        }

        $this->servers[$name] = $server;

        return null;
    }

    /**
     * Construct the {@see McpServer} one `.mcp.json` entry asks for.
     *
     * Split out of {@see startServer()} so that the `default` arm's throw is
     * inside that method's guard rather than beside it — see route 2 there.
     *
     * @param array<string, mixed> $config
     */
    private function buildServer(string $name, string $type, array $config): McpServer
    {
        return match ($type) {
            // `startTimeout` (seconds) is OPTIONAL and per-server: a locally
            // installed binary answers the handshake in milliseconds, while a
            // cold `npx -y @modelcontextprotocol/server-…` first has to fetch a
            // package tree. Only a positive number is honoured — anything else
            // (a string, 0, a negative) falls back to
            // {@see StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS}, because a
            // hand-edited config must not be able to turn the bound OFF by
            // accident. It bounds the HANDSHAKE only; `tools/call` stays
            // unbounded, see {@see StdioMcpServer::callTool()}.
            'stdio' => new StdioMcpServer(
                name: $name,
                command: $config['command'] ?? '',
                args: $config['args'] ?? [],
                env: $this->resolveEnv($config['env'] ?? []),
                startTimeoutSeconds: is_numeric($config['startTimeout'] ?? null)
                    ? (float) $config['startTimeout']
                    : null,
            ),
            'http' => new HttpMcpServer(
                name: $name,
                url: $config['url'] ?? '',
                headers: $this->resolveEnv($config['headers'] ?? []),
                httpClient: $this->httpClient,
            ),
            'git' => new GitMcpServer(
                name: $name,
                handlers: new GitCommandHandlers(
                    cwd: $config['path'] ?? null,
                ),
            ),
            default => throw new \RuntimeException("Unknown MCP server type: $type"),
        };
    }

    /**
     * List available tools, restricted to what the current agent preset (if
     * any) is allowed to see per McpRouter::resolveAllowedTools(). With no
     * preset attached, returns nothing unless $unrestricted was explicitly
     * set at construction time.
     *
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        if ($this->agentPreset !== null) {
            return $this->router()->resolveAllowedTools($this->agentPreset);
        }

        if (!$this->unrestricted) {
            return [];
        }

        $tools = [];

        foreach ($this->servers as $server) {
            $tools = array_merge($tools, $server->listTools());
        }

        return $tools;
    }

    /**
     * Call a tool on a specific server. Rejected when the current agent
     * preset's mcpServers allowlist does not cover $serverName, so a caller
     * cannot bypass listTools() filtering by naming a denied server directly.
     * With no preset attached, rejected unconditionally unless $unrestricted
     * was explicitly set at construction time.
     */
    public function callTool(string $serverName, string $toolName, array $args): array
    {
        $server = $this->servers[$serverName] ?? null;

        if ($server === null) {
            throw new \RuntimeException("Unknown MCP server: $serverName");
        }

        if (!$this->isServerAllowed($serverName)) {
            throw new \RuntimeException("MCP server not allowed for this agent: $serverName");
        }

        return $server->callTool($toolName, $args);
    }

    /**
     * Whether $serverName is reachable under the current routing mode: via
     * the active agent preset's allowlist (and any deny patterns), or via the
     * explicit $unrestricted escape hatch when no preset is attached.
     */
    private function isServerAllowed(string $serverName): bool
    {
        if ($this->agentPreset !== null) {
            return in_array($serverName, $this->router()->resolveAllowedServers($this->agentPreset), true);
        }

        return $this->unrestricted;
    }

    /**
     * Call a tool by name across all servers the current agent preset is
     * allowed to see (first match). Tools belonging to servers outside the
     * preset's allowlist are invisible here, not merely unlisted.
     */
    public function callToolByName(string $toolName, array $args): array
    {
        foreach ($this->listTools() as $tool) {
            if ($tool->name !== $toolName) {
                continue;
            }

            $server = $this->servers[$tool->serverName] ?? null;
            if ($server !== null) {
                return $server->callTool($toolName, $args);
            }
        }

        throw new \RuntimeException("Tool not found: $toolName");
    }

    /**
     * Build an McpRouter over this client's currently-started servers,
     * forwarding the configured global deny patterns alongside the servers
     * so both halves of McpRouter's enforcement (allowlist + deny patterns)
     * are actually wired through, not just the allowlist.
     */
    private function router(): McpRouter
    {
        return new McpRouter($this->servers, $this->denyPatterns);
    }

    private function loadConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function resolveEnv(array $env): array
    {
        $resolved = [];

        foreach ($env as $key => $value) {
            if (is_string($value) && preg_match('/^\$\{(.*?)(?::-(.*))?\}$/', $value, $matches)) {
                $resolved[$key] = getenv($matches[1]) ?: ($matches[2] ?? '');
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
