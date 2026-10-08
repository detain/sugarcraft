<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * Tool/server narrowing over a keyed map of MCP servers: deny patterns first,
 * then an explicit allow-list.
 *
 * Extracted from the sugar-crush MCP stack (src/MCP/McpRouter.php). The
 * product-coupled half stayed downstream: sugar-crush reads the allow-list out
 * of an AgentPreset; this library takes the already-extracted `list<string>`
 * of server keys, so routing is policy-free and any preset, config layer or
 * CLI flag can feed it.
 *
 * WHY deny-before-allow: an author who lists a server explicitly should not be
 * able to out-vote an operator's deny rule. Ordering the filter as
 * deny → allow makes the deny list the hard boundary it is documented as.
 *
 * Both sides match RAW config keys (e.g. "filesystem"), never the wire
 * spelling ("mcp__filesystem__read_file") — a pattern aimed at the wire name
 * would silently match nothing and read as an enforced rule.
 */
final class McpRouter
{
    /** @var array<string,string> pattern => action, normalised once in the constructor */
    private readonly array $denyActions;

    /**
     * @param array<string,McpServer> $servers keyed by server name
     * @param array<string,string|array{action:string}> $denyPatterns pattern =>
     *        action string (the sugar-crush config spelling, e.g. 'fs-*' => 'deny')
     *        or a spec array carrying a string "action"; only "deny" applies,
     *        other actions are ignored. Any other shape — including an array
     *        with no usable string action — throws at construction: a
     *        deny rule that silently reads as no rule widens the boundary,
     *        and the allow-list side of this class already refuses to guess.
     */
    public function __construct(
        private readonly array $servers,
        array $denyPatterns = [],
    ) {
        $actions = [];
        foreach ($denyPatterns as $pattern => $spec) {
            if (is_string($spec)) {
                $actions[(string) $pattern] = $spec;
                continue;
            }

            if (is_array($spec) && isset($spec['action']) && is_string($spec['action'])) {
                /** @var array{action:string} $spec */
                $actions[(string) $pattern] = $spec['action'];
                continue;
            }

            throw new \RuntimeException(sprintf(
                'MCP deny pattern "%s" must map to an action string or an array with a string "action", got %s.',
                (string) $pattern,
                get_debug_type($spec),
            ));
        }

        $this->denyActions = $actions;
    }

    /**
     * Every tool from every allowed server, after deny patterns and the
     * per-preset allow-list have had their say.
     *
     * @param list<string> $allowList empty list means "all servers allowed"
     * @return list<McpTool>
     */
    public function resolveAllowedTools(array $allowList = []): array
    {
        $tools = [];
        foreach ($this->resolveAllowedServers($allowList) as $server) {
            foreach ($server->listTools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The servers that survive deny patterns and the allow-list, keyed by name.
     *
     * @param list<string> $allowList empty list means "all servers allowed"
     * @return array<string,McpServer>
     */
    public function resolveAllowedServers(array $allowList = []): array
    {
        $allowed = $this->applyDenyPatterns($this->servers);

        return $this->applyAllowList($allowed, $allowList);
    }

    /**
     * @param array<string,McpServer> $servers
     * @return array<string,McpServer>
     */
    private function applyDenyPatterns(array $servers): array
    {
        if ($this->denyActions === []) {
            return $servers;
        }

        return array_filter(
            $servers,
            fn (string $name): bool => !$this->matchesAnyDenyPattern($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string,McpServer> $servers
     * @param list<string> $allowList
     * @return array<string,McpServer>
     */
    private function applyAllowList(array $servers, array $allowList): array
    {
        if ($allowList === []) {
            return $servers;
        }

        return array_filter(
            $servers,
            fn (string $name): bool => self::serverAllowed($name, $allowList),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function matchesAnyDenyPattern(string $name): bool
    {
        // One law: the public static below is what every caller outside the
        // router asks too, so the instance filter delegates rather than
        // mirroring. The constructor already reduced specs to action strings.
        return self::serverDenied($name, $this->denyActions);
    }

    /**
     * Whether ONE server name matches a deny-pattern map (pattern => "deny",
     * `fnmatch` wildcards, compared against the RAW config key exactly as
     * {@see serverAllowed()} is).
     *
     * Public static because the same question gets asked without a router in
     * sight: sugar-crush's McpClient applies the operator's deny map to the
     * servers it starts and to its unrestricted arm, which never builds a
     * router over the servers it refuses (lane-A2 fold of the sugar-crush
     * original — this class is now the single home of both predicates).
     *
     * A pattern that is not a non-empty string never matches. PHP casts
     * numeric-looking array keys back to int, and an int "pattern" — or the
     * literal "" a stray config colon leaves behind — is not a rule anyone
     * authored against a server name; reading it as one would be guessing.
     * An entry whose action is not the exact string "deny" is likewise not a
     * deny rule; other actions are ignored, never interpreted.
     *
     * @param array<array-key, mixed> $denyPatterns pattern => action, the raw
     *        config shape (or the constructor-normalised action map).
     */
    public static function serverDenied(string $server, array $denyPatterns): bool
    {
        foreach ($denyPatterns as $pattern => $action) {
            if ($action === 'deny' && \is_string($pattern) && $pattern !== '' && fnmatch($pattern, $server)) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE LAW, EXPOSED. Whether ONE server name passes an allow-list.
     *
     * `applyAllowList()` filters a whole server map; downstream gate checks
     * answer the same question one entry at a time — sugar-crush's sub-agent
     * roster narrows MCP bridges out of a preset's grant at resolution
     * (E696-α, `AgentManager::resolveGrantedTools()`). Both must read the same
     * law — empty list allows all, `*` entries `fnmatch`, everything else is
     * exact equality on the RAW config server key — or a preset's roster and
     * its router view diverge, which is the two-dialects defect a permission
     * layer refuses to host for tool names. One spelling of the membership
     * rule, one implementation; the filter delegates here rather than
     * mirroring it. (Lane-A2 fold: this is the sugar-crush original's wording
     * and doctrine, now the single home.)
     *
     * RAW BOTH SIDES ON PURPOSE: entries are compared against the config key
     * as authored (`.mcp.json` for sugar-crush), never against the sanitised
     * `mcp__<key>__` wire spelling (E42) — a caller holding only a wire name
     * must not route it in here.
     *
     * An empty list means allow-all — "no restriction configured" is distinct
     * from "restrict to nothing", and every product caller relies on that
     * reading. A `*` entry glob-matches via fnmatch(); anything else must
     * compare exactly.
     *
     * Non-string or empty-string entries throw. A blank rule would fnmatch()
     * nothing useful and an exact "" compares to nothing real: we refuse rather
     * than read either as a rule that matches nothing, because a config typo
     * that silently narrows (or widens) access is worse than a loud boot
     * failure.
     *
     * @param array<array-key, mixed> $allowList the caller's list, untrusted
     *        shape — a foreign import casts with `(array)` only, so entries
     *        are validated here at the boundary rather than trusted upstream.
     */
    public static function serverAllowed(string $name, array $allowList): bool
    {
        if ($allowList === []) {
            return true;
        }

        foreach ($allowList as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \RuntimeException(sprintf(
                    'An mcpServers allowlist entry must be a non-empty string, %s given; '
                    . 'a server allowlist is refused rather than read as a rule that matches nothing.',
                    get_debug_type($entry),
                ));
            }

            if (str_contains($entry, '*')) {
                if (fnmatch($entry, $name)) {
                    return true;
                }

                continue;
            }

            if ($entry === $name) {
                return true;
            }
        }

        return false;
    }
}
