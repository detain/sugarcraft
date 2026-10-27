<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Util\TokenEstimate;
use SugarCraft\McpClient\McpRouter as LibraryMcpRouter;

/**
 * The frozen split of one launch's MCP tool roster into bridged and dispatched halves.
 *
 * MCP-7: a server whose advertised schemas cost more than the lazy floor
 * would otherwise spend its schema tokens on EVERY request step whether or
 * not the model uses it. Such a server (unless its config entry opts into
 * `eager`) gets no `mcp__server__tool` bridge; its tools are reached instead
 * through the two stable dispatcher tools — `mcp_list` to read the roster and
 * `mcp_call` to run one of its entries. Everything below the floor keeps the
 * first-class bridge, so a typical small `.mcp.json` is untouched: same wire
 * names, same descriptions, byte-identical schema payloads.
 *
 * The shape is derived ONCE per client memo key (Bootstrap caches it) and the
 * dispatched roster it carries never re-derives mid-session — adoption of
 * servers or tools that appear later is next session, the same rule the
 * E703-α path applies to the bridge roster itself. `mcp_list` answers from
 * THESE descriptors, never from a fresh `listTools()`, so the two spellings
 * of one capability can never disagree about what exists.
 *
 * The eager/dispatched decision is per server against the same floor a config
 * total would face: servers individually under the floor are "small" and stay
 * first-class even in a config whose sum exceeds it, because the dispatcher
 * pair exists to defuse schema bombs, not to tax every server for a noisy
 * neighbour. The pair is declared only when at least one tool was actually
 * dispatched — an `mcp_list` with an empty roster would be pure overhead.
 */
final class McpToolShape
{
    /**
     * @param list<McpTool> $eagerDescriptors descriptors that keep first-class bridges
     * @param list<McpTool> $dispatchedDescriptors descriptors reachable only through mcp_call
     */
    private function __construct(
        public readonly McpClient $client,
        public readonly bool $lazy,
        public readonly array $eagerDescriptors,
        public readonly array $dispatchedDescriptors,
    ) {}

    /**
     * Derive the shape for one launch from the tools the client reports.
     *
     * @param iterable<McpTool> $descriptors the client's live `listTools()` answer at this instant
     * @param array<string, array<string, mixed>> $entries server name => raw config entry (the `eager` flag rides here)
     */
    public static function derive(McpClient $client, iterable $descriptors, array $entries, int $floorTokens): self
    {
        // Sum each server's schema cost in the exact shape the wire carries
        // (TokenEstimate::ofToolSchema is the same estimator ofToolSchemas
        // applies to built-ins), and record the read-only hints for EVERY
        // descriptor as we go — a dispatched tool must consult the same
        // readOnlyHint table the gate already consults for bridged ones, or
        // the dispatcher would silently degrade hint-driven Auto allows.
        $perServer = [];
        $order = [];
        $byServer = [];
        foreach ($descriptors as $descriptor) {
            $server = $descriptor->serverName;
            if (!isset($byServer[$server])) {
                $byServer[$server] = [];
                $perServer[$server] = 0;
                $order[] = $server;
            }
            $byServer[$server][] = $descriptor;
            $perServer[$server] += TokenEstimate::ofToolSchema(
                McpToolBridge::wireNameFor($server, $descriptor->name),
                $descriptor->description,
                $descriptor->inputSchema,
            );
            // The bridge constructor records the hint for the eager half when
            // bridges() builds them; the dispatched half never constructs one,
            // so its verdict is recorded here through the same funnel, under
            // the SAME rule readOnly() applies (hint AND-not-open-world) —
            // the re-merge against a re-observed value stays inside the bridge.
            McpToolBridge::recordReadOnlyHint(
                McpToolBridge::wireNameFor($server, $descriptor->name),
                $descriptor->readOnlyHint() && $descriptor->openWorldHint() !== true,
            );
        }

        $eager = [];
        $dispatched = [];
        foreach ($order as $server) {
            $entry = $entries[$server] ?? null;
            $forcesBridge = is_array($entry) && self::entryIsEager($entry);
            $underBudget = $floorTokens <= 0 || $perServer[$server] < $floorTokens;
            foreach ($byServer[$server] as $descriptor) {
                if ($forcesBridge || $underBudget) {
                    $eager[] = $descriptor;
                } else {
                    $dispatched[] = $descriptor;
                }
            }
        }

        return new self($client, $dispatched !== [], $eager, $dispatched);
    }

    /**
     * C-AGT-3: this shape narrowed to one preset's `mcpServers` allowlist —
     * the scoped copy {@see AgentManager::resolveGrantedTools()} hands a
     * sub-agent in place of the launch dispatcher, so `mcpServers` restricts
     * `mcp_list`/`mcp_call` exactly as it already restricts the bridges.
     *
     * Two halves move together and neither mutates the launch shape:
     *
     * - the client is re-bound via {@see McpClient::forAgentPreset()} — a
     *   clone carrying a preset over the SAME server handles, so a call the
     *   frozen roster let through still fails closed at
     *   `McpClient::callTool()` on the membership law (defence in depth: one
     *   law, {@see LibraryMcpRouter::serverAllowed()}, checked twice);
     * - the dispatched half is filtered by that same law, RAW server keys on
     *   both sides (E42), so `mcp_list` prints only what `mcp_call` can run.
     *
     * The eager half and the lazy flag pass through unchanged: bridges() is
     * resolved out of the launch roster (the narrowing at grant-resolution
     * already drops the disallowed ones), and re-deriving the split per agent
     * would let one preset's schema floor disagree with another's about what
     * exists — the shape is a launch fact, the scope is a view on it. A
     * non-string allowlist entry throws from the shared law, the same loud
     * refusal the bridge filter gives it at resolution.
     *
     * @param array<array-key, mixed> $mcpServers the preset's allowlist; the
     *        caller reaches here only for a non-empty list, and an empty one
     *        would be the router's documented allow-all reading anyway
     */
    public function scopedTo(array $mcpServers): self
    {
        $client = $this->client->forAgentPreset(new AgentPreset(
            name: 'mcp-agent-scope',
            description: '',
            mcpServers: $mcpServers,
        ));

        $dispatched = [];
        foreach ($this->dispatchedDescriptors as $descriptor) {
            if (LibraryMcpRouter::serverAllowed($descriptor->serverName, $mcpServers)) {
                $dispatched[] = $descriptor;
            }
        }

        return new self($client, $this->lazy, $this->eagerDescriptors, array_values($dispatched));
    }

    /**
     * The first-class bridges this launch publishes (the eager half).
     *
     * @return list<Tool>
     */
    public function bridges(): array
    {
        $tools = [];
        foreach ($this->eagerDescriptors as $descriptor) {
            $tools[] = new McpToolBridge($this->client, $descriptor);
        }

        return $tools;
    }

    public function listTool(): Tool
    {
        return new McpListTool($this);
    }

    public function callTool(): Tool
    {
        return new McpCallTool($this);
    }

    public function findDispatched(string $server, string $tool): ?McpTool
    {
        foreach ($this->dispatchedDescriptors as $descriptor) {
            if ($descriptor->serverName === $server && $descriptor->name === $tool) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Whether one config entry opts its server's tools into first-class
     * bridges regardless of schema cost. Truthy spellings follow the house
     * flag convention; anything else (including absent) means "not eager".
     *
     * @param array<string, mixed> $entry
     */
    private static function entryIsEager(array $entry): bool
    {
        $eager = $entry['eager'] ?? null;
        if (is_bool($eager)) {
            return $eager;
        }

        return is_string($eager) && in_array(strtolower($eager), ['1', 'true', 'yes', 'on'], true);
    }
}
