<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * SERVER narrowing over a keyed map of MCP servers (tool rosters follow
 * servers): deny patterns first, then an explicit allow-list.
 *
 * Extracted from the candy-code MCP stack (src/MCP/McpRouter.php). The
 * product-coupled half stayed downstream: candy-code reads the allow-list out
 * of an AgentPreset, and narrows individual tool rosters there by matching
 * preset grants against the sanitised wire names; this library takes the
 * already-extracted `list<string>` of server keys, so routing is policy-free
 * and any preset, config layer or CLI flag can feed it.
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
     * @var array<array-key,string> pattern => action, normalised once in the
     *      constructor — a digit-SPELLED pattern lands on an INT key (PHP
     *      array-key normalisation), so the domain is array-key, not string;
     *      the readers below cast back to the config spelling before matching
     */
    private readonly array $denyActions;

    /**
     * @param array<array-key,McpServer> $servers keyed by server name — a
     *        digit-named server sits on an int key for the same normalisation,
     *        and the filter closures spell names back with (string) casts
     * @param array<array-key, string|array{action:string}> $denyPatterns two
     *        config notations are legal: a PLAIN
     *        LIST of glob patterns — `['scratch', 'untrusted_*']` — where every
     *        entry denies, or a pattern => action map (the candy-code config
     *        spelling, e.g. 'fs-*' => 'deny') where the action is a bare string
     *        or a spec array carrying a string "action"; only "deny" applies,
     *        other actions are ignored. Any other shape — including a
     *        non-string list entry or an array with no usable string action —
     *        throws at construction: a deny rule that silently reads as no
     *        rule widens the boundary, and the allow-list side of this class
     *        already refuses to guess.
     */
    public function __construct(
        private readonly array $servers,
        array $denyPatterns = [],
    ) {
        $this->denyActions = self::normalizeDenyActions($denyPatterns);
    }

    /**
     * Reduce either documented deny-config notation to ONE normalised
     * pattern => action map.
     *
     * The list discriminator is the plan law {@see array_is_list()}: a plain
     * list carries pattern strings and denies each of them; anything else is
     * the pattern => action map, validated entry by entry.
     *
     * DOCUMENTED COLLISION: JSON cannot tell `["deny"]` from `{"0": "deny"}` —
     * decoded by PHP both are the list `[0 => 'deny']`. The list law wins, so
     * such a map-shaped config reads as the PATTERN "deny", never as a rule
     * for a server named "0". Deny a digit-named server with the list spelling
     * `['0']` (list entries carry the literal name); a map that is not
     * list-shaped (e.g. `{ "64": "deny" }`, or two or more named keys) keeps
     * the map reading byte-for-byte.
     *
     * @param array<array-key, mixed> $denyPatterns the raw config shape
     * @return array<array-key,string> pattern => action
     */
    private static function normalizeDenyActions(array $denyPatterns): array
    {
        if (array_is_list($denyPatterns)) {
            $listed = [];
            foreach ($denyPatterns as $position => $pattern) {
                if (!is_string($pattern)) {
                    throw new \RuntimeException(sprintf(
                        'MCP deny pattern list entry %d must be a pattern string, got %s.',
                        $position,
                        get_debug_type($pattern),
                    ));
                }

                $listed[$pattern] = 'deny';
            }

            return $listed;
        }

        $actions = [];
        foreach ($denyPatterns as $pattern => $spec) {
            // (string) is the config SPELLING used in diagnostics; the array
            // write re-normalises a digit-spelled pattern to its int key —
            // the truth the widened annotations above carry, matched back as
            // a string by denyMapMatches().
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

        return $actions;
    }

    /**
     * Every tool from every allowed server, after the deny patterns and the
     * server allow-list have had their say. Tool rosters arrive whole: the
     * filter's unit is the SERVER.
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
     * @return array<array-key,McpServer> keyed by the config name; a name
     *         that is a decimal integer arrives as an int key (PHP array
     *         normalisation — compare keys as strings, and note that deny
     *         rules and allow entries DO reach such a server)
     */
    public function resolveAllowedServers(array $allowList = []): array
    {
        // Review N1: PHP's array law casts decimal-integer keys to int on
        // the way IN and no re-spelling keeps them strings on the way OUT,
        // so a server literally named "0" rides an int key through the map.
        // The filters below read every key at its STRING spelling (the old
        // `fn (string $name)` either TypeErrored the view or, behind an
        // early return, let a rule for "0" silently miss it); the @return
        // documents the normalisation honestly instead of pretending.
        $allowed = $this->applyDenyPatterns($this->servers);

        return $this->applyAllowList($allowed, $allowList);
    }

    /**
     * @param array<array-key,McpServer> $servers
     * @return array<array-key,McpServer>
     */
    private function applyDenyPatterns(array $servers): array
    {
        if ($this->denyActions === []) {
            return $servers;
        }

        return array_filter(
            $servers,
            fn (int|string $name): bool => !$this->matchesAnyDenyPattern((string) $name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<array-key,McpServer> $servers
     * @param list<string> $allowList
     * @return array<array-key,McpServer>
     */
    private function applyAllowList(array $servers, array $allowList): array
    {
        if ($allowList === []) {
            return $servers;
        }

        return array_filter(
            $servers,
            fn (int|string $name): bool => self::serverAllowed((string) $name, $allowList),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function matchesAnyDenyPattern(string $name): bool
    {
        // One law: the public static below is what every caller outside the
        // router asks too, so both doors share ONE raw-map implementation.
        // The constructor already reduced the config to action strings, and
        // the normalised map is matched directly — never re-parsed through
        // the list law, which would misread a stored pattern named "0"
        // (decoded list-shaped) back as the pattern "deny".
        return self::denyMapMatches($name, $this->denyActions);
    }

    /**
     * Whether ONE server name matches a normalised deny map (pattern =>
     * action strings, `fnmatch` wildcards, compared against the RAW config
     * key exactly as {@see serverAllowed()} is). Shared by both doors of the
     * deny law; takes the map the constructor produced, never raw config.
     *
     * @param array<array-key,string> $denyActions normalised map — a
     *        digit-spelled pattern stands on an int key and is cast back to
     *        its config spelling by the loop below before matching
     */
    private static function denyMapMatches(string $server, array $denyActions): bool
    {
        foreach ($denyActions as $pattern => $action) {
            $spelling = is_int($pattern) ? (string) $pattern : (\is_string($pattern) ? $pattern : '');

            if ($action === 'deny' && $spelling !== '' && fnmatch($spelling, $server)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether ONE server name matches a deny-pattern config.
     *
     * Public static because the same question gets asked without a router in
     * sight: candy-code's McpClient applies the operator's deny map to the
     * servers it starts and to its unrestricted arm, which never builds a
     * router over the servers it refuses (folded from the candy-code
     * original; this class is now the single home of both predicates).
     *
     * The argument is the RAW config shape, so BOTH documented notations
     * work here exactly as in the constructor — plain list of patterns
     * (each denies) or pattern => action map — and a malformed entry
     * throws, here as at construction: the static is the door downstream
     * gates read, so it may not answer a plain list by failing open.
     *
     * An empty pattern never matches: the literal "" a stray config colon
     * leaves behind is not a rule anyone authored against a server name.
     * Numeric-looking array keys arrive from PHP as ints and are read at
     * their string spelling — a server literally named "64" is addressable
     * by the map rule `{"64":"deny"}` (not list-shaped, keeps the map
     * reading), while the single-entry `{"0":"deny"}` IS list-shaped and
     * reads as the pattern "deny" per the collision law on
     * {@see normalizeDenyActions()}. Patterns are GLOBS honored by fnmatch
     * with all its semantics: backslash escapes are live, so a pattern
     * authored as a regular expression (`\d+`, `.*`) is a foot-gun — the
     * deny side is glob by contract. An entry whose action is not the exact
     * string "deny" is likewise not a deny rule; other actions are ignored,
     * never interpreted.
     *
     * @param array<array-key, mixed> $denyPatterns the raw config shape
     *        (list of pattern strings, or pattern => action map).
     */
    public static function serverDenied(string $server, array $denyPatterns): bool
    {
        return self::denyMapMatches($server, self::normalizeDenyActions($denyPatterns));
    }

    /**
     * THE LAW, EXPOSED. Whether ONE server name passes an allow-list.
     *
     * `applyAllowList()` filters a whole server map; downstream gate checks
     * answer the same question one entry at a time — candy-code's sub-agent
     * roster narrows MCP bridges out of a preset's grant at resolution
     * (the sub-agent roster narrowing in `AgentManager::resolveGrantedTools()`). Both must read the same
     * law — empty list allows all, `*` entries `fnmatch`, everything else is
     * exact equality on the RAW config server key — or a preset's roster and
     * its router view diverge, which is the two-dialects defect a permission
     * layer refuses to host for tool names. One spelling of the membership
     * rule, one implementation; the filter delegates here rather than
     * mirroring it. (Folded from the candy-code original's wording and
     * doctrine; this class is the single home.)
     *
     * RAW BOTH SIDES ON PURPOSE: entries are compared against the config key
     * as authored (`.mcp.json` for candy-code), never against the sanitised
     * `mcp__<key>__` wire spelling (the raw-key law) — a caller holding only a wire name
     * must not route it in here.
     *
     * An empty list means allow-all — "no restriction configured" is distinct
     * from "restrict to nothing", and every product caller relies on that
     * reading. The two sides of the wall deliberately speak TWO GLOB
     * DIALECTS, and the difference is the fail-closed direction: the DENY
     * side (serverDenied) fnmatches EVERY pattern, because a denial is only
     * dangerous when it UNDER-matches — reading `q?` or `[ab]*` as a
     * literal there would let a refused server through. The ALLOW side is
     * strict: only entries containing `*` glob at all, anything else must
     * compare exactly, because an allow rule is dangerous when it
     * OVER-matches — a typo like `q?` read literally narrows nothing
     * silently, while fnmatch-ing it would quietly widen the grant to
     * `qq`. Both laws are pinned in McpRouterTest: `q?` denies `qq` and
     * does not allow it.
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
