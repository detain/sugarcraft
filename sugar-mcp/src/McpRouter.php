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
    /**
     * @param array<string,McpServer> $servers keyed by server name
     * @param array<string,array{action?:string}> $denyPatterns pattern => spec; only action "deny" applies
     */
    public function __construct(
        private readonly array $servers,
        private readonly array $denyPatterns = [],
    ) {
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
        if ($this->denyPatterns === []) {
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
        foreach ($this->denyPatterns as $pattern => $spec) {
            // A pattern spec whose action is not "deny" is not a deny rule;
            // ignore it rather than guess its intent.
            if (($spec['action'] ?? '') !== 'deny') {
                continue;
            }

            if (fnmatch((string) $pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this server name permitted by the allow-list?
     *
     * Public static because both the router and downstream gate checks ask the
     * same question about the same list shape.
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
     * @param list<string> $allowList
     */
    public static function serverAllowed(string $name, array $allowList): bool
    {
        if ($allowList === []) {
            return true;
        }

        foreach ($allowList as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \RuntimeException(sprintf(
                    'MCP allow-list entry for server "%s" must be a non-empty string, got %s.',
                    $name,
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
