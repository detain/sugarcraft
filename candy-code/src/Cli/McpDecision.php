<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\EnvBool;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\ProjectRoot;

/**
 * The MCP config-decision path: where each tier's server file is, what it
 * declares, and whether this launch may act on it — computed without
 * starting anything.
 *
 * WHY THIS CLASS EXISTS. {@see Bootstrap} discovered MCP config the same way
 * for twelve methods spread over a thousand lines of its own file, and the
 * discipline that made that discovery safe — ONE path, verdicts keyed
 * idempotently, listings that can never launch — lived only as prose in those
 * doc-blocks. The path itself is pure: a decision array in, rows and verdicts
 * out. What is NOT pure are the process-global stores it touches on the way:
 * the project-tier refusal collector, the per-process client memo and the
 * launch digests. Those stay in {@see Bootstrap}, behind the closures each
 * entry point receives, so this file can be read — and tested — as the whole
 * of the discovery law, while every historical caller keeps reaching it
 * through its one-line {@see Bootstrap} delegate.
 *
 * WHAT DOES NOT LIVE HERE. The five status constants, the file names and the
 * settings keys stay declared on {@see Bootstrap} beside the client memo they
 * describe: readers across `src/` and the panel name them there, and the
 * launch-side formatter that renders refusals is the mcpClient seam
 * ({@see Bootstrap::mcpClient()}). Neither does the builder: this class says
 * what a config MEANS; constructing the client that acts on it stays where
 * the memo and the server processes live.
 */
final class McpDecision
{
    /**
     * Where this project's `.mcp.json` is, and whether this launch is allowed
     * to start the servers it names — WITHOUT starting any of them.
     *
     * THE ONE DISCOVERY PATH. {@see Bootstrap::mcpClient()} and
     * {@see mcpServerInventory()} both come through here, so `candy-code mcp list`
     * cannot report a file, a containment verdict or a trust verdict that
     * differs from the one the launch acts on. Two readers with two copies of
     * this rule is a defect this package has shipped before; the whole reason
     * `mcp list` is not simply "json_decode(.mcp.json)" is that such a listing
     * would happily enumerate servers the trust gate refuses to run.
     *
     * Records the same {@see Bootstrap::$projectTierRefusals} entries the inline
     * version did, keyed by the same path, so calling it twice is idempotent.
     *
     * {@see Bootstrap::MCP_DISABLE_ENV} (or the `mcp.enabled: false` setting,
     * {@see mcpDisabled()}) short-circuits this whole gate to
     * {@see Bootstrap::MCP_DISABLED} before any stat or trust read — the decision
     * stays the one discovery path, it just answers the environment first.
     * And the switch answers for BOTH tiers: a disabled launch reads no user
     * file either, which is why that arm carries an empty `userPath`.
     *
     * MCP-3: THE USER TIER rides beside the project tier in the same decision.
     * `~/.candy-code/mcp.json` needs no entry in any trust list — the operator
     * wrote it, and the ownership gate in {@see mcpUserConfigPath()} is the
     * check (the same posture as user-tier commands and hooks). Only three
     * statuses can appear in `userStatus`: {@see Bootstrap::MCP_DISABLED} under
     * the switch, {@see Bootstrap::MCP_ABSENT} with no readable home or no file,
     * and {@see Bootstrap::MCP_TRUSTED}. Where BOTH tiers declare a name, the
     * PROJECT wins ({@see \SugarCraft\Code\MCP\McpClient::startServers()} applies
     * the precedence on the launch side; {@see mcpServerInventory()} mirrors it
     * on the listing side).
     *
     * @param \Closure(?string):string $requireRoot resolves the launch root or refuses, the one root reader {@see Bootstrap::requireRoot()}
     * @param \Closure(string):bool    $projectMcpIsTrusted the per-process trust memo, {@see Bootstrap::projectMcpIsTrusted()}
     * @param \Closure(string,string):void $recordRefusal records a path's refusal reason in the collector, {@see Bootstrap::$projectTierRefusals}
     *
     * @return array{path: string, root: string, canonicalRoot: string|false, status: string, userPath: string, userStatus: string}
     *   `status` is one of {@see Bootstrap::MCP_DISABLED}, {@see Bootstrap::MCP_ABSENT},
     *   {@see Bootstrap::MCP_OUTSIDE_TREE}, {@see Bootstrap::MCP_UNTRUSTED},
     *   {@see Bootstrap::MCP_TRUSTED}. `userPath` is the user tier's path, empty
     *   when the home is not this user's or the switch is on; `userStatus`
     *   carries no containment or trust verdicts because neither applies —
     *   see MCP-3 above.
     */
    public static function mcpConfigDecision(
        ?string $root,
        \Closure $requireRoot,
        \Closure $projectMcpIsTrusted,
        \Closure $recordRefusal,
    ): array {
        // The repository's `.mcp.json`, and the trust record keyed by the
        // repository, on a subdirectory launch too (audit 15d-13 (b)).
        $root = ProjectRoot::resolve($requireRoot($root));

        // CANONICAL, not as spelled, for the reason {@see Bootstrap::hookFiles()}
        // gives at its own `realpath()`: the trust decision below is made on the
        // resolved root, so keying the memo off the raw string would leave two
        // names for one decision. MEASURED against the raw-string version, four
        // spellings of ONE root — `$W/repo`, `$W/repo/`, `$W/repo/sub/..`,
        // `$W/repo/./` — produced 4 cached clients and EIGHT live server
        // processes, which is exactly the accumulation
        // {@see Bootstrap::$mcpClients} says memoization prevents.
        $canonicalRoot = realpath($root);
        $path = rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/')
            . '/' . Bootstrap::MCP_CONFIG_FILENAME;

        $decision = ['path' => $path, 'root' => $root, 'canonicalRoot' => $canonicalRoot];

        // E737 — the opt-out hatch, honored ABOVE every other gate and BEFORE
        // any stat, containment compare, trust read or refusal write. It
        // answers the SHAPE of a config-less miss — well-formed array, nothing
        // to launch — under its OWN status since ISS-093(a): `MCP_DISABLED`,
        // because ABSENT told an operator with a real `.mcp.json` that the
        // project declared none, and the panel's advice then pointed at adding
        // a file that existed. Every consumer's law is byte-unchanged: a
        // non-TRUSTED decision is "nothing to launch", so the environment
        // still silences the whole subsystem (zero launches, zero children) —
        // and still NO notice is recorded here: an operator who disabled MCP
        // does not want to be told to trust it on every launch.
        if (self::mcpDisabled()) {
            return $decision + [
                'userPath' => '',
                'userStatus' => Bootstrap::MCP_DISABLED,
                'status' => Bootstrap::MCP_DISABLED,
            ];
        }

        // MCP-3: the user tier, resolved for every launch that is not switched
        // off. The two stats this costs are the honest price of widening
        // discovery from one file to two; the project tier below still runs
        // its own single-stat fast path untouched.
        $userPath = self::mcpUserConfigPath();
        $tiers = [
            'userPath' => $userPath,
            'userStatus' => $userPath !== '' && is_file($userPath) ? Bootstrap::MCP_TRUSTED : Bootstrap::MCP_ABSENT,
        ];

        // is_file() FIRST, so the overwhelmingly common "no MCP config" case
        // costs one stat and reaches neither the containment compare, the trust
        // gate, nor the refusal report. A dangling symlink is not a file, so it
        // lands here.
        if (!is_file($path)) {
            return $decision + $tiers + ['status' => Bootstrap::MCP_ABSENT];
        }

        if (!ContainedPath::within($path, $root)) {
            // A REASON, NOT A SENTENCE, and it does not name the configured path:
            // the one notice that prints this composes `ignoring <path> — <reason>`
            // and already holds it, so naming it here put it in that line TWICE.
            // Same mid-clause "resolves to …" shape as every sibling feeder — see
            // {@see \SugarCraft\Code\Workflows\WorkflowRegistry::projectTierRefusal()},
            // whose doc-block records making this identical correction.
            $recordRefusal($path, sprintf(
                'resolves to %s, outside the project tree it was read from (%s); '
                . 'refusing to start servers named by a file outside the checkout.',
                realpath($path) === false ? 'nothing readable' : (string) realpath($path),
                $root,
            ));

            return $decision + $tiers + ['status' => Bootstrap::MCP_OUTSIDE_TREE];
        }

        // THE TRUST GATE, and it is checked AFTER containment so that an
        // out-of-tree config is reported as out-of-tree rather than as untrusted:
        // the two have different fixes and only one of them is "opt in".
        //
        // THE FIRST ARM IS DELIBERATE BELT-AND-BRACES, NOT DEAD CODE (E45a) —
        // written down because "unreachable guard" is precisely what a later
        // cleanup pass deletes, and a security gate that loses one branch
        // acquires a hole. The cost of keeping it is one arm; the shape of the
        // refusal below already expects it (`$canonicalRoot !== false ? … :
        // $root`). And the original unreachability premise — that the `is_file`
        // above can only have passed on a path composed from a RESOLVED root —
        // measured weaker than written: `$path` falls back to the raw `$root`
        // when `realpath()` fails, so the stat that let execution through is no
        // longer proof that the root canonicalised. Keep it for the direction it
        // fails in: the arm short-circuits into MCP_UNTRUSTED. Falling through
        // instead would hand `false` to the trust closure, whose parameter is
        // `string` under `declare(strict_types=1)`, and turn an impossible state
        // into a TypeError on `tools()` — the one class of failure on a path
        // every launch and every provider switch reaches.
        if ($canonicalRoot === false || !$projectMcpIsTrusted($canonicalRoot)) {
            $recordRefusal($path, sprintf(
                'starting the MCP servers it names means running programs this repository chose, '
                . 'every time you open it, before any tool call and in every permission mode. '
                . 'Add "%s" to "%s" in %s to opt in',
                rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/'),
                Bootstrap::TRUSTED_PROJECT_MCP_CONFIG_KEY,
                Bootstrap::userConfigPath(),
            ));

            return $decision + $tiers + ['status' => Bootstrap::MCP_UNTRUSTED];
        }

        return $decision + $tiers + ['status' => Bootstrap::MCP_TRUSTED];
    }

    /**
     * MCP-3: the USER tier's `.candy-code/mcp.json`, or the empty string when
     * there is no such place to look.
     *
     * `HomeDirectory::owned()` answers "is this home verifiably this user's"
     * — same door every other user-tier read comes through
     * ({@see Bootstrap::trustedConfigDirPath()}, foreign skill discovery,
     * presets), and here it is ALSO the trust ceremony: an operator who can
     * write `~/.candy-code` owns what runs from it, so no second allowlist
     * adds a decision anyone could actually make. An unowned, unresolved or
     * world-writable home yields `''` — the tier is silently absent, never an
     * error, because unlike the permission policy this file is optional and a
     * hostile-home launch must simply not read it.
     */
    public static function mcpUserConfigPath(): string
    {
        $home = HomeDirectory::owned();

        return $home === null ? '' : $home . '/.candy-code/' . Bootstrap::MCP_USER_CONFIG_FILENAME;
    }

    /**
     * THE SPELLING behind {@see Bootstrap::MCP_DISABLE_ENV}, one place so the
     * narrower truthy vocabulary — only `1`, `true`, `yes`, case-insensitive
     * — cannot drift into the flag-style default-everything-on convention.
     * The vocabulary itself is {@see EnvBool::isTrue()}, the single owner of
     * the narrow house law; this method is one of its consumers.
     * Deliberately NOT the `!== false && !== '' && !== '0'` shape the other
     * switches use: here the DEFAULT-ON side must be every value, so a
     * launcher that cannot control what its environment carries (a shard
     * runner inheriting an operator shell) still gets the documented default
     * unless it sees one of the three words.
     */
    public static function mcpDisabledByEnvironment(): bool
    {
        // W7-D fold (weld-7 flag): this was the second inline copy of the narrow
        // house law. `getenv(...)` stays spelled out at the call site — the env
        // rosters attribute the read to the literal it carries here.
        return EnvBool::isTrue(getenv(Bootstrap::MCP_DISABLE_ENV));
    }

    /**
     * Whether project MCP is off for this launch: {@see Bootstrap::MCP_DISABLE_ENV}, OR
     * the operator's `mcp.enabled: false` setting.
     *
     * EITHER ONE TURNS IT OFF; neither turns it back on. The variable's
     * vocabulary only ever says "off" (see {@see mcpDisabledByEnvironment()}),
     * so "the environment wins" reduces to this: a launcher that exports the
     * variable silences MCP whatever the settings say, and a user who switched
     * it off in their settings is not overruled by an environment that merely
     * lacks the variable. User tier only — a checkout's own settings file
     * cannot decide whether its `.mcp.json` runs; the trust list decides that.
     * Read through {@see \SugarCraft\Code\Config\Settings\UiSettings};
     * the client is built once per process, so a change applies at restart.
     */
    public static function mcpDisabled(): bool
    {
        return self::mcpDisabledByEnvironment()
            || !\SugarCraft\Code\Config\Settings\UiSettings::bool(Bootstrap::MCP_ENABLED_SETTING);
    }

    /**
     * What `.mcp.json` DECLARES, for `candy-code mcp list` — read, never run.
     *
     * NOTHING HERE CALLS `proc_open()`, and that is the entire design
     * constraint. {@see Bootstrap::mcpClient()} STARTS every configured server as
     * a side effect of being asked for the client, so routing a listing through
     * it would mean `candy-code mcp list` launches every program this repository
     * names — the exact act the trust gate exists to make deliberate, performed
     * by the command an operator runs precisely BECAUSE they do not yet trust
     * the file. So this reads the JSON and reports; `status` tells the caller
     * whether the launch would run these entries at all.
     *
     * Consequence, stated because it is a real limit rather than an oversight:
     * `enabled` reflects the CONFIG, not liveness. Nothing here can tell you a
     * declared server would fail to start — finding that out means starting it.
     *
     * @param array{path: string, status: string, userPath: string, userStatus: string} $decision the shared verdict this listing reports
     *
     * @return array{status: string, path: string, servers: list<array{name: string, type: string, detail: string}>, skipped: list<string>, error: string|null, userStatus: string, userPath: string, userServers: list<array{name: string, type: string, detail: string}>, userSkipped: list<string>, userError: string|null}
     *   `servers` is empty for every non-{@see Bootstrap::MCP_TRUSTED} status and
     *   for a file that is not decodable, in which case `error` says why.
     *   `skipped` (N-2) names every ENTRY that is not a named server object —
     *   the launch loop refuses those same entries with the same sentence
     *   (ISS-091), so `mcp list` must not swallow them silently: a skipped
     *   entry is an operator typo that would otherwise only surface as a
     *   missing tool.
     *
     * MCP-3: the five `user*` keys describe the USER tier with the same
     * sentences and the same shape. A name the project tier declares is not
     * listed twice — the project wins, so `userServers` carries only the
     * entries that would actually reach the launch. When the project file is
     * present but undecodable, the shadow set is empty: the launch aborts on
     * that file anyway, and the listing must not pretend to know which names
     * it would have declared.
     */
    public static function mcpServerInventory(array $decision): array
    {
        $base = [
            'status' => $decision['status'],
            'path' => $decision['path'],
            'servers' => [],
            'skipped' => [],
            'error' => null,
            'userStatus' => $decision['userStatus'],
            'userPath' => $decision['userPath'],
            'userServers' => [],
            'userSkipped' => [],
            'userError' => null,
        ];

        if ($decision['status'] !== Bootstrap::MCP_TRUSTED) {
            return array_merge($base, self::mcpUserTierRows($decision, []));
        }

        $contents = @file_get_contents($decision['path']);
        if (!is_string($contents)) {
            return array_merge(['error' => Lang::t('cli.mcp.config.unreadable')] + $base, self::mcpUserTierRows($decision, []));
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return array_merge(['error' => Lang::t('cli.mcp.config.invalid_json', ['error' => $e->getMessage()])] + $base, self::mcpUserTierRows($decision, []));
        }

        if (!is_array($data) || !is_array($data['mcpServers'] ?? null)) {
            return array_merge(['error' => Lang::t('cli.mcp.config.no_servers_object')] + $base, self::mcpUserTierRows($decision, []));
        }

        // MCP-3: both tiers build their rows through ONE builder, so the
        // listing cannot drift into saying different things about the two
        // files it read. The launch loop in
        // {@see \SugarCraft\Code\MCP\McpClient::startServers()} keeps its own
        // copy of the refusal sentence (ISS-091 pins the pair).
        [$servers, $skipped] = self::mcpConfigRows($data['mcpServers']);

        return array_merge(
            ['servers' => $servers, 'skipped' => $skipped] + $base,
            self::mcpUserTierRows($decision, array_column($servers, 'name')),
        );
    }

    /**
     * `candy-code mcp trust` — the user's explicit approval of a project's
     * `.mcp.json` AS IT IS NOW (audit MCP-5): lists `trustedProjectMcp` for the
     * canonical project root when it is not already there, and records every
     * enabled server's fingerprint ({@see McpTrustPins}), replacing the root's
     * previous record. Starts nothing.
     *
     * Each row says how the entry compares to what was recorded before —
     * `new`, `changed` or `unchanged` — so the command doubles as the review
     * the refusal notice asks for. Nothing is written for a config that is
     * absent, outside the tree, unreadable or not JSON.
     *
     * @param array{status: string, path: string, root: string, canonicalRoot: string|false} $decision the shared verdict this grant acts on
     * @param \Closure():array $permissionConfig the two user files, merged, {@see Bootstrap::permissionConfig()}
     * @param \Closure():string $trustedConfigDirPath the owned home config directory, {@see Bootstrap::trustedConfigDirPath()}
     * @param \Closure(array,string,string):list<string> $trustedProjectRoots the trust list reader, {@see Bootstrap::trustedProjectRoots()}
     *
     * @return array{status: string, path: string, root: string, servers: list<array{name: string, summary: string, change: string}>, removed: list<string>, invalid: list<string>, granted: bool, recorded: bool, error: string|null}
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    public static function trustProjectMcp(
        array $decision,
        \Closure $permissionConfig,
        \Closure $trustedConfigDirPath,
        \Closure $trustedProjectRoots,
    ): array {
        $trustRoot = $decision['canonicalRoot'] !== false ? $decision['canonicalRoot'] : $decision['root'];
        $report = [
            'status' => $decision['status'],
            'path' => $decision['path'],
            'root' => $trustRoot,
            'servers' => [],
            'removed' => [],
            'invalid' => [],
            'granted' => false,
            'recorded' => false,
            'error' => null,
        ];

        if (!\in_array($decision['status'], [Bootstrap::MCP_UNTRUSTED, Bootstrap::MCP_TRUSTED], true) || $decision['canonicalRoot'] === false) {
            return $report;
        }

        $contents = @file_get_contents($decision['path']);
        if (!is_string($contents)) {
            return ['error' => Lang::t('cli.mcp.config.unreadable')] + $report;
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || !is_array($data['mcpServers'] ?? null)) {
            return ['error' => Lang::t('cli.mcp.config.not_a_config')] + $report;
        }

        $current = McpTrustPins::pinsFor($data['mcpServers']);
        $pins = McpTrustPins::load($trustedConfigDirPath() . '/' . McpTrustPins::FILENAME);
        $before = $pins->forRoot($trustRoot) ?? [];

        foreach ($current['pins'] as $name => $pin) {
            $report['servers'][] = [
                'name' => $name,
                'summary' => $pin['summary'],
                'change' => !isset($before[$name])
                    ? 'new'
                    : (hash_equals($before[$name]['fingerprint'], $pin['fingerprint']) ? 'unchanged' : 'changed'),
            ];
        }
        $report['removed'] = array_values(array_diff(array_keys($before), array_keys($current['pins'])));
        $report['invalid'] = $current['invalid'];

        // The grant itself, through the one config writer — but only when it is
        // missing, so a hand-curated list is not rewritten for nothing.
        $isTrusted = static fn (): bool => \in_array($trustRoot, $trustedProjectRoots(
            $permissionConfig(),
            Bootstrap::TRUSTED_PROJECT_MCP_CONFIG_KEY,
            Lang::t('cli.warn.untrusted_mcp'),
        ), true);
        if (!$isTrusted()) {
            $listed = $permissionConfig()[Bootstrap::TRUSTED_PROJECT_MCP_CONFIG_KEY] ?? [];
            $listed = is_array($listed) ? array_values($listed) : [];
            $listed[] = $trustRoot;
            Bootstrap::writeUserConfig([Bootstrap::TRUSTED_PROJECT_MCP_CONFIG_KEY => $listed]);
        }
        $report['granted'] = $isTrusted();

        if ($report['granted']) {
            try {
                $pins->withRoot($trustRoot, $current['pins'])->save();
                $report['recorded'] = true;
            } catch (\Throwable $e) {
                $report['error'] = Lang::t('cli.mcp.config.pins_unwritable', ['path' => $pins->path(), 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    /**
     * What THIS PROCESS has actually started, for the `/mcp` panel — reading
     * the {@see Bootstrap::$mcpClients} memo, NEVER consulting
     * {@see Bootstrap::mcpClient()}.
     *
     * THE ONE THING THIS MUST NOT DO IS LAUNCH. A liveness readout asked on a
     * cold process would love nothing more than to "just check", and the only
     * way to check from outside the memo is to build the client — whose
     * constructor path STARTS every server. That turns a status question into
     * the exact un-deliberated `proc_open()` the trust gate above exists to
     * make one. So this reads the memo and nothing else: a miss is a null,
     * never a launch.
     *
     * @param array{path: string, userPath: string} $decision the shared verdict whose memo key this reads
     * @param \Closure(string):?\SugarCraft\Code\MCP\McpClient $memoClient the memo reader — a lookup, never a builder
     *
     * @return array<string, array{transport: string, up: bool|null, tools: int}>|null
     *   null when this process holds no session client for the rooted path at
     *   all (the one-shot CLI always lands here — its process never builds
     *   one); the empty map when a client exists but zero servers came up;
     *   else {@see \SugarCraft\Code\MCP\McpClient::startedSnapshot()}.
     */
    public static function mcpLivenessSnapshot(array $decision, \Closure $memoClient): ?array
    {
        // THE SAME DECISION PATH, for the property that matters: the memo is
        // keyed by the canonical decision path, so resolving any other way
        // (raw $root, or the config path without realpath) would read a key
        // mcpClient() never writes. The decision records nothing new on this
        // call — the render site has already asked it once for the very same
        // root, and its refusals are keyed idempotently by path.
        // MCP-3: the same composite key mcpClient() stores under — reading the
        // bare project path would miss every two-tier launch.
        return $memoClient(self::mcpConfigMemoKey($decision))?->startedSnapshot();
    }

    /**
     * MCP-3: the memo key for a decision. With NO resolvable user tier (an
     * unowned home, or the disabled switch) this is exactly the canonical
     * project path — the key every pre-MCP-3 reader and writer used. Whenever
     * the home resolves, the key is the two paths joined by a NUL whether or
     * not the user file exists yet: widening on resolvability rather than on
     * existence is what keeps a file created mid-session from missing the
     * memo and double-building the client. A path cannot contain NUL, so no
     * pair of real files can collide with a single-path key.
     *
     * @param array{path: string, userPath?: string} $decision
     */
    public static function mcpConfigMemoKey(array $decision): string
    {
        $userPath = (string) ($decision['userPath'] ?? '');

        return $userPath === '' ? $decision['path'] : $userPath . "\0" . $decision['path'];
    }

    /**
     * E703-α: has `.mcp.json` changed since the launch that memoised it?
     *
     * A DETECTION, NOT A FIX. The change cannot be applied in-session and this
     * method does not pretend otherwise: the trust decision is frozen per
     * process, and re-reading a repository-chosen file whose entries
     * `proc_open()` would resurrect the very threat the freeze closes (an
     * auto-reload under a still-valid grant re-arms prompt-injection →
     * execution with no new operator decision). So the honest seam stops at
     * naming the drift and pointing at the restart.
     *
     * @param array{path: string, userPath: string} $decision the shared verdict whose tiers are compared
     * @param \Closure(string):(array{sha256: string, mtime: int}|null) $storedDigest yields this process's launch digest for one tier path, or null
     *
     * @return bool|null null when THIS process has no launch digest for either
     *         tier path (no client built here, or the files were unreadable at
     *         the store instant) — the caller suppresses the line rather than
     *         claiming anything; true when either tier's current bytes differ
     *         from its launch bytes OR that file cannot be read any more
     *         (vanishing IS a change — the loud answer is the truthful one);
     *         false when both digests still match.
     */
    public static function mcpConfigChangedSinceLaunch(array $decision, \Closure $storedDigest): ?bool
    {
        // MCP-3: one verdict across both tiers. A digest exists only for a
        // tier THIS launch was allowed to read (mcpClient() stores per-tier),
        // so an untrusted project contributes nothing to compare — its drift
        // is not a drift of what runs.
        $verdict = null;
        foreach ([$decision['path'], (string) $decision['userPath']] as $tierPath) {
            if ($tierPath === '') {
                continue;
            }

            $digest = $storedDigest($tierPath);
            if ($digest === null) {
                continue;
            }

            $now = self::mcpConfigDigest($tierPath);
            if ($now === null || $now['sha256'] !== $digest['sha256']) {
                return true;
            }

            $verdict = false;
        }

        return $verdict;
    }

    /**
     * The digest of $path's CURRENT bytes, or null when they cannot be read.
     *
     * `file_get_contents` and not `hash_file`, deliberately: the read-path
     * census (`tests/Support/ReadPathCensusTest`) enumerates `file_get_contents`
     * as a sink spelling and sees nothing else of this shape — a `hash_file`
     * read would be the one read on this path with no row beside it. The
     * `.mcp.json` is a small project file; reading it to hash it costs nothing
     * and stays inside the instrumented vocabulary.
     */
    public static function mcpConfigDigest(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }

        return ['sha256' => hash('sha256', $contents), 'mtime' => (int) @filemtime($path)];
    }

    /**
     * MCP-3: the row builder both tiers of {@see mcpServerInventory()} read
     * through — one source for the skipped sentence (N-2/ISS-091) and for the
     * type/detail projection, so the listing says the same words about the
     * project file and the user file. Lives OUTSIDE the inventory's own span
     * on purpose: the AW documentation arm slices that span for its zero-sink
     * claim and quotes this pair's rules; a second method in the slice would
     * blur which body owes which pin.
     *
     * `$shadowed` is MCP-3's precedence made visible in the listing: names a
     * HIGHER tier (the project) already declares are dropped from the lower
     * tier's rows, because that is exactly what the launch will do — the
     * listing that showed them would promise servers that never start.
     *
     * @param array<array-key, mixed> $entries   one file's `mcpServers` map
     * @param list<string>            $shadowed
     * @return array{0: list<array{name: string, type: string, detail: string}>, 1: list<string>}
     */
    public static function mcpConfigRows(array $entries, array $shadowed = []): array
    {
        $servers = [];
        $skipped = [];
        foreach ($entries as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                // N-2: the SAME sentence {@see \SugarCraft\Code\MCP\McpClient::startServers()}
                // records for this shape (ISS-091), so `mcp list` and the
                // launch can never disagree about why an entry is missing.
                $skipped[] = sprintf(
                    '%s (entry is %s, not a named server object)',
                    is_string($name) && $name !== '' ? $name : json_encode($name, JSON_PARTIAL_OUTPUT_ON_ERROR),
                    get_debug_type($config),
                );

                continue;
            }

            // MCP-3: the project tier owns this name; the lower tier's entry
            // is invisible here because it is invisible to the launch.
            if (\in_array($name, $shadowed, true)) {
                continue;
            }

            // The same types {@see \SugarCraft\Code\MCP\McpClient::startServer()}
            // constructs, and the same `?? 'stdio'` default it applies, so an
            // entry listed as `stdio` here is the entry that would be started
            // as `stdio`. An unknown type is shown as written rather than
            // normalised: that string is what makes startServers() throw, so
            // hiding it would hide the diagnosis.
            $type = is_string($config['type'] ?? null) ? $config['type'] : 'stdio';
            $detail = match ($type) {
                'stdio' => trim(
                    (is_string($config['command'] ?? null) ? $config['command'] : '')
                    . ' ' . implode(' ', array_filter(
                        is_array($config['args'] ?? null) ? $config['args'] : [],
                        static fn ($a): bool => is_string($a),
                    ))
                ),
                'http' => is_string($config['url'] ?? null) ? $config['url'] : '',
                // MCP-4 relay (w5h): sse rides the same url detail as http.
                'sse' => is_string($config['url'] ?? null) ? $config['url'] : '',
                'git' => is_string($config['path'] ?? null) ? $config['path'] : '(this project)',
                // E699: the entry names nothing and the operator's path must
                // never reach a transcript-visible surface — the detail is a
                // fixed label, not data.
                'claude-mcp' => '(operator-supplied)',
                default => '',
            };

            $servers[] = ['name' => $name, 'type' => $type, 'detail' => $detail];
        }

        return [$servers, $skipped];
    }

    /**
     * MCP-3: the five `user*` listing keys, or the silent defaults when the
     * user tier has nothing to say. The same three broken-shape reports as
     * the project tier, through the same Lang sentences — an unreadable home
     * file is described exactly like an unreadable project file, because from
     * here it is the same kind of thing: bytes the operator cannot launch
     * until they can be read.
     *
     * @param array{userPath: string, userStatus: string} $decision
     * @param list<string> $shadowed names the project tier already declares
     * @return array{userServers: list<array{name: string, type: string, detail: string}>, userSkipped: list<string>, userError: string|null}
     */
    public static function mcpUserTierRows(array $decision, array $shadowed): array
    {
        $rows = ['userServers' => [], 'userSkipped' => [], 'userError' => null];

        if ($decision['userStatus'] !== Bootstrap::MCP_TRUSTED || (string) $decision['userPath'] === '') {
            return $rows;
        }

        $contents = @file_get_contents($decision['userPath']);
        if (!is_string($contents)) {
            $rows['userError'] = Lang::t('cli.mcp.config.unreadable');

            return $rows;
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $rows['userError'] = Lang::t('cli.mcp.config.invalid_json', ['error' => $e->getMessage()]);

            return $rows;
        }

        if (!is_array($data) || !is_array($data['mcpServers'] ?? null)) {
            $rows['userError'] = Lang::t('cli.mcp.config.no_servers_object');

            return $rows;
        }

        [$rows['userServers'], $rows['userSkipped']] = self::mcpConfigRows($data['mcpServers'], $shadowed);

        return $rows;
    }

    /**
     * E699: the OPERATOR-TIER grant behind a `claude-mcp` entry, or null when
     * the operator said nothing at all.
     *
     * RAW SHAPES ONLY — the spawn policy (absolute path, existing file,
     * executable bit, the repository-may-not-name rule) lives entirely in
     * {@see \SugarCraft\Code\MCP\ClaudeCodeMcpServer::fromGrant()}, so
     * every refusal this transport can raise is decided by one class and
     * unit-testable without any file writes. This method answers only two
     * questions: is the grant present, and is it the shape the operator
     * tier can carry?
     *
     * THE READ RIDES {@see Bootstrap::permissionConfig()} on purpose: the same
     * two user files, the same ownership gate, the same tier as
     * `trustedProjectMcp` — a PROJECT settings layer must never be able to
     * name this binary, so this is NOT {@see Bootstrap::readUserConfig()}'s
     * merged view. The once-per-process FREEZE is structural rather than a
     * second memo: the only production caller is the `claude-mcp` arm of
     * {@see \SugarCraft\Code\MCP\McpClient::buildServer()}, which runs inside
     * `startServers()`, which runs inside the per-(pid, path) client memo of
     * {@see Bootstrap::mcpClient()} — a config edit after that read cannot
     * re-spawn anything in this process, which is the E703-β rejection
     * argument applied to a grant that never re-reads.
     *
     * @param array<string, mixed> $config the permission-tier view of the user's config, passed in by the caller's own read
     *
     * @return array{binary: string, args: list<string>|null, env: array<string,string>|null}|null
     *
     * @throws \RuntimeException when a named key carries a shape the tier
     *         cannot express — a config error the launch catch reports, not a
     *         silent ignore
     */
    public static function claudeMcpGrant(array $config): ?array
    {
        if (!array_key_exists('claudeMcpBinary', $config)) {
            return null;
        }

        $binary = $config['claudeMcpBinary'];
        if (!is_string($binary)) {
            throw new \RuntimeException(Lang::t('cli.refuse.claude_mcp_binary'));
        }

        $args = null;
        if (array_key_exists('claudeMcpArgs', $config)) {
            $rawArgs = $config['claudeMcpArgs'];
            if (!is_array($rawArgs) || !array_is_list($rawArgs)) {
                throw new \RuntimeException(Lang::t('cli.refuse.claude_mcp_args'));
            }
            foreach ($rawArgs as $arg) {
                if (!is_string($arg) && !is_int($arg) && !is_float($arg)) {
                    throw new \RuntimeException(Lang::t('cli.refuse.claude_mcp_arg_entry'));
                }
            }
            $args = array_values(array_map(static fn (string|int|float $arg): string => (string) $arg, $rawArgs));
        }

        $env = null;
        if (array_key_exists('claudeMcpEnv', $config)) {
            $rawEnv = $config['claudeMcpEnv'];
            if (!is_array($rawEnv)) {
                throw new \RuntimeException(Lang::t('cli.refuse.claude_mcp_env'));
            }
            $env = [];
            foreach ($rawEnv as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \RuntimeException(Lang::t('cli.refuse.claude_mcp_env_entry'));
                }
                $env[$key] = $value;
            }
        }

        return ['binary' => $binary, 'args' => $args, 'env' => $env];
    }
}
