<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Util\TokenEstimate;
use SugarCraft\McpClient\McpRouter as LibraryMcpRouter;

/**
 * The frozen split of one launch's MCP tool roster into bridged and dispatched halves.
 *
 * MCP-7: a launch whose advertised schemas cost more than the lazy floor
 * would otherwise spend its schema tokens on EVERY request step whether or
 * not the model uses it. Deferred tools get no `mcp__server__tool` bridge;
 * they are reached instead through the two stable dispatcher tools —
 * `mcp_list` to read the roster (with each schema, on demand) and `mcp_call`
 * to run one of them. Everything else keeps the first-class bridge, same wire
 * names, same descriptions, byte-identical schema payloads.
 *
 * The deferral is per TOOL, not per server (#18). The rule, in full:
 *
 * 1. A server whose config entry sets `eager` keeps every tool first-class,
 *    and its schema cost is fixed: it counts toward the total but is never
 *    deferred. If that fixed cost alone reaches the floor, no candidate can
 *    bring the total under it, so every candidate is deferred.
 * 2. Every other tool is a candidate. If the total schema cost of all tools
 *    is under the floor (or the floor is 0), nothing is deferred.
 * 3. Otherwise candidates are deferred largest schema first, one at a time,
 *    until the eager total (fixed cost plus the candidates still eager) is
 *    under the floor. Largest-first defers the fewest tools that can bring
 *    the total under the floor. Ties keep declaration order.
 *
 * So a small server is never touched by a large neighbour unless it has to be,
 * and a single oversized tool is deferred alone while its siblings stay eager.
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
     * @param list<string> $deferredTools the deferred tools as `server/tool`, for the launch row
     */
    private function __construct(
        public readonly McpClient $client,
        public readonly bool $lazy,
        public readonly array $eagerDescriptors,
        public readonly array $dispatchedDescriptors,
        public readonly array $deferredTools = [],
    ) {}

    /**
     * Derive the shape for one launch from the tools the client reports.
     *
     * @param iterable<McpTool> $descriptors the client's live `listTools()` answer at this instant
     * @param array<string, array<string, mixed>> $entries server name => raw config entry (the `eager` flag rides here)
     */
    public static function derive(McpClient $client, iterable $descriptors, array $entries, int $floorTokens): self
    {
        // Sum each tool's schema cost in the exact shape the wire carries
        // (TokenEstimate::ofToolSchema is the same estimator ofToolSchemas
        // applies to built-ins), and record the read-only hints for EVERY
        // descriptor as we go — a dispatched tool must consult the same
        // readOnlyHint table the gate already consults for bridged ones, or
        // the dispatcher would silently degrade hint-driven Auto allows.
        $all = [];
        $costs = [];
        foreach ($descriptors as $descriptor) {
            $server = $descriptor->serverName;
            $costs[] = TokenEstimate::ofToolSchema(
                McpToolBridge::wireNameFor($server, $descriptor->name),
                $descriptor->description,
                $descriptor->inputSchema,
            );
            $all[] = $descriptor;
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

        // Rule 1: eager servers are fixed; everything else is a candidate.
        $fixed = 0;
        $candidates = [];
        foreach ($all as $index => $descriptor) {
            $entry = $entries[$descriptor->serverName] ?? null;
            if (is_array($entry) && self::entryIsEager($entry)) {
                $fixed += $costs[$index];
            } else {
                $candidates[] = $index;
            }
        }

        // Rules 2 and 3: defer the largest candidates until the eager total is
        // under the floor. The total is compared in the same direction the old
        // per-server check used (under the floor means eager).
        $deferred = [];
        $eagerTotal = $fixed + array_sum(array_map(static fn (int $i): int => $costs[$i], $candidates));
        if ($floorTokens > 0 && $eagerTotal >= $floorTokens) {
            $bySize = $candidates;
            usort($bySize, static fn (int $a, int $b): int => [$costs[$b], $a] <=> [$costs[$a], $b]);
            foreach ($bySize as $index) {
                if ($eagerTotal < $floorTokens) {
                    break;
                }
                $deferred[$index] = true;
                $eagerTotal -= $costs[$index];
            }
        }

        $eager = [];
        $dispatched = [];
        $deferredTools = [];
        foreach ($all as $index => $descriptor) {
            if (isset($deferred[$index])) {
                $dispatched[] = $descriptor;
                $deferredTools[] = $descriptor->serverName . '/' . $descriptor->name;
            } else {
                $eager[] = $descriptor;
            }
        }

        return new self($client, $dispatched !== [], $eager, $dispatched, $deferredTools);
    }

    /**
     * n24: the one row that tells the operator which tools the lazy schema
     * floor deferred this launch — the split was silent before, so a missing
     * `mcp__server__tool` bridge read as a broken server rather than a budget
     * decision. Names the deferred tools, not whole servers, so an operator
     * can see exactly which schema is the expensive one. Empty string when
     * nothing was deferred; the launch drain names the count and the key that
     * raises or disables the floor.
     */
    public function lazyDemotionNotice(): string
    {
        if ($this->deferredTools === []) {
            return '';
        }

        return Lang::t('mcp.lazy_schema_demoted', [
            'count' => (string) count($this->deferredTools),
            'servers' => implode(', ', $this->deferredTools),
        ]);
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

        return new self($client, $this->lazy, $this->eagerDescriptors, $dispatched, $this->deferredTools);
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
