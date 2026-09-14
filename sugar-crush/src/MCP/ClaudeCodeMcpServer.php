<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;

/**
 * E699 — the `claude-mcp` transport: the repository ASKS, the operator's
 * pinned binary ANSWERS.
 *
 * This adapter is the ONLY site under `src/` that constructs
 * {@see \SugarCraft\Crush\ClaudeCodeMcpClient}, and it reaches that class
 * ONLY through the `claude-mcp` arm of
 * {@see \SugarCraft\Crush\MCP\McpClient::buildServer()} behind a double
 * opt-in: a `.mcp.json` entry of the type AND an operator-tier
 * `claudeMcpBinary` absolute path in the user config. The entry itself
 * carries nothing else — `command`, `args` and `env` on the entry are
 * refused as config errors, because the repository does not name this
 * binary. What makes this transport different from `stdio` is exactly WHO
 * holds the binary and its argv: `stdio` lets the repository name any
 * program (and the trust list is what gates that); `claude-mcp` lets the
 * repository request the operator's pinned agent-with-credentials — the
 * tool it could point crush at is another crush, so the grant moved UP a
 * tier rather than the trust ruling being amended.
 *
 * THE GATES THAT THE DORMANCY NOTE DEMANDED, and where each landed:
 *  - a path anchor: NOT {@see \SugarCraft\Crush\Support\ContainedPath} —
 *    containment is the answer to repository-chosen paths, and this path is
 *    operator-authored in the user tier whose ownership
 *    {@see \SugarCraft\Crush\Cli\Bootstrap::userConfigPath()} already
 *    enforces. There is no anchor to check against, so the policy is the
 *    shape itself: absolute path, existing file, executable bit. A bare
 *    name is refused EVEN THOUGH the client's `resolveExecutable()` would
 *    PATH-search it — that branch is a test-double convenience and is not
 *    trust on a wiring path.
 *  - a PermissionGate in front of the forwarded calls: the bridges this
 *    server advertises are {@see McpToolBridge}s, and every call rides the
 *    PreToolUse chain exactly like a `stdio` bridge's; plan mode already
 *    denies every `mcp__*` name.
 *
 * ⚠️ STARTING IS THE EXECUTION, AND PreToolUse IS NOT THE BOUNDARY FOR IT.
 * The same two-controls law {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}
 * states for `stdio` holds here byte-true: a grant that lets this arm build
 * a server has already let a `proc_open()` happen, before any tool call and
 * in every permission mode. What bounds THAT is tier one higher again — the
 * operator wrote `claudeMcpBinary`, and the grant is read once per process
 * inside the launch-frozen client memo, so a mid-session config edit cannot
 * re-arm it. No reload, no panel toggle, no second WRITE seam.
 *
 * The operator's binary PATH is never echoed: every refusal this class
 * raises names the CONFIG KEY, never the value, and
 * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpServerInventory()} reports the
 * detail as the fixed string `(operator-supplied)`.
 *
 * @see \SugarCraft\Crush\Tests\ClaudeCodeMcpClientTest::testTheOnlyPathToThisSeamIsTheGatedFactoryArm()
 */
final class ClaudeCodeMcpServer implements McpServer
{
    /** The `.mcp.json` `type` value this transport answers to. */
    public const TYPE = 'claude-mcp';

    /**
     * The argv spawned when the operator grants `claudeMcpBinary` without
     * `claudeMcpArgs`, and the ONLY default this class will ever invent.
     * Deliberately operator-settable and never repository-settable: repo
     * args could flip the CLI into a permissionless mode, which is why the
     * entry shape is `type`-only in the first place.
     */
    public const DEFAULT_ARGS = ['--mcp'];

    /**
     * Keys a `.mcp.json` entry is not allowed to carry on this transport —
     * checked with `array_flip` rather than `isset($entry[...])` indexing
     * so the factory-arm slice stays free of `$config['key']` text the
     * type-table pin reads as this transport's config surface.
     */
    private const REPOSITORY_MUST_NOT_NAME = ['command' => true, 'args' => true, 'env' => true];

    /**
     * The START-TIME tool cache, mirrored from {@see StdioMcpServer}: it is
     * what {@see \SugarCraft\Crush\MCP\McpClient::startedSnapshot()} counts
     * without touching the wire, and what the panel pairs with `exited`
     * when the child dies later.
     *
     * @var list<McpTool>
     */
    private array $tools = [];

    /**
     * @param list<string> $spawnArgs the argv as gated — for the operator-facing
     *        truth that the factory honours the default and the override
     */
    private function __construct(
        public readonly string $name,
        private readonly ClaudeCodeMcpClient $client,
        public readonly array $spawnArgs,
    ) {}

    /**
     * Gate every path to construction; throw a CONFIG error the launch
     * catch reports on both channels, degraded and never refused.
     *
     * @param array<string, mixed> $entry the raw `.mcp.json` entry — ONLY
     *        its shape is judged here; nothing in it names the spawn
     * @param array{binary?: mixed, args?: mixed, env?: mixed}|null $grant
     *        the operator-tier triple, null when the operator said nothing
     *
     * @throws \RuntimeException on any gate failure — a repository-named
     *         spawn, an absent grant, a non-absolute or unusable binary
     */
    public static function fromGrant(string $name, array $entry, ?array $grant): self
    {
        if (array_intersect_key($entry, self::REPOSITORY_MUST_NOT_NAME) !== []) {
            throw new \RuntimeException(sprintf(
                'the claude-mcp entry "%s" names command/args/env; the repository does not name this '
                . 'binary — remove those keys, the spawn comes from the operator-tier claudeMcpBinary',
                $name,
            ));
        }

        if ($grant === null) {
            throw new \RuntimeException(sprintf(
                'the claude-mcp entry "%s" needs the operator-tier claudeMcpBinary key in the user '
                . 'config (same tier as trustedProjectMcp); no operator grant, no spawn',
                $name,
            ));
        }

        $binary = $grant['binary'] ?? null;
        if (!is_string($binary) || $binary === '') {
            throw new \RuntimeException('claudeMcpBinary is set but empty; the operator-tier grant has to name a path');
        }

        if (!str_starts_with($binary, '/')) {
            throw new \RuntimeException(
                'claudeMcpBinary must be an absolute path; a bare name would be found through $PATH, '
                . 'and $PATH is not a grant',
            );
        }

        if (!is_file($binary) || !is_executable($binary)) {
            // The PATH is never named: this message reaches the transcript.
            throw new \RuntimeException('claudeMcpBinary does not point at an existing executable file');
        }

        $args = $grant['args'] ?? self::DEFAULT_ARGS;
        if (!is_array($args) || $args === []) {
            $args = self::DEFAULT_ARGS;
        } else {
            $args = array_values(array_map(static fn (mixed $arg): string => (string) $arg, $args));
        }

        $env = is_array($grant['env'] ?? null) ? $grant['env'] : [];

        return new self(
            name: $name,
            client: new ClaudeCodeMcpClient(
                command: $binary,
                args: $args,
                env: array_map(static fn (mixed $v): string => (string) $v, $env),
            ),
            spawnArgs: $args,
        );
    }

    /**
     * Spawn through the client's gated connect, then complete the
     * START-TIME `tools/list` the cache exists to hold. An unanswered list
     * throws inside the ~1s poll the client already bounds — the same
     * runtime-failure family {@see \SugarCraft\Crush\MCP\McpClient::startServer()}
     * skips silently, as opposed to {@see fromGrant()}'s config errors,
     * which are reported.
     */
    public function start(): void
    {
        $this->client->connect();

        $response = $this->client->listTools();

        $toolDefs = $response->result['tools'] ?? [];
        if (!is_array($toolDefs)) {
            $toolDefs = [];
        }

        $tools = [];
        foreach ($toolDefs as $def) {
            if (!is_array($def)) {
                continue;
            }
            $tool = McpTool::tryFromArray($def, $this->name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }

        $this->tools = $tools;
    }

    /**
     * The bounded ladder lives in the client: one last stderr drain, close
     * the pipes for the EOF-exit courtesy, then the reaper with the
     * CONTAINMENT GROUP id, because this child is a CLI that spawns
     * grandchildren of its own — a bare pid signal would orphan them.
     */
    public function stop(): void
    {
        $this->client->disconnect();
    }

    /**
     * @return list<McpTool> the start-time cache; no wire traffic, same law
     *         as every sibling transport feeding `startedSnapshot()`
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<mixed> the same shape discipline
     *         {@see StdioMcpServer::callTool()} established: a result object
     *         passes through, an error answer becomes the readable
     *         `['error' => ...]` tool result rather than an exception.
     */
    public function callTool(string $toolName, array $args): array
    {
        $response = $this->client->callTool($toolName, $args);

        if ($response->error !== null || !$response->resultSet) {
            return ['error' => 'Tool call failed'];
        }

        if (!is_array($response->result)) {
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

    /** E698 law, same as the stdio sibling: connected AND the child is running. */
    public function isUp(): bool
    {
        return $this->client->isUp();
    }

    /**
     * Bounded stderr pump for the between-turns idle readout —
     * {@see \SugarCraft\Crush\MCP\McpClient::pumpStderr()} reaches it only
     * through its own instanceof arm, never through the interface.
     */
    public function pumpStderr(): void
    {
        $this->client->pumpStderr();
    }

    /** The child's stderr tail, for a caller explaining a silent server. */
    public function stderrTail(): string
    {
        return $this->client->stderrTail();
    }
}
