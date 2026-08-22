<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\LspTool;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\McpToolBridge;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * Wires up the CLI's shared, side-effecting collaborators: backend
 * selection, the built-in coding tools, and the real on-disk
 * SessionStore/MemoryStore/InstructionFileLoader that back /branch,
 * /rewind, and /memory (P6.S9/S11/S12/S15).
 *
 * R19: extracted out of `bin/sugarcrush`'s construction IIFE so this wiring
 * can be exercised from a PHPUnit test. `bin/sugarcrush` cannot be
 * `require`d directly in a test — it unconditionally ends in
 * `Program::run()`, which attaches to a real TTY and blocks. Pulling the
 * *construction* logic (everything before `run()`) into this ordinary
 * class, with the bin script reduced to `require autoload` +
 * `Bootstrap::chat()` + `run()`, was the smallest change that made the
 * wiring independently testable without touching the CLI's runtime
 * behaviour at all.
 */
final class Bootstrap
{
    /**
     * The per-invocation override and the persisted key for the launch's
     * permission mode, plus the mode used when neither says anything. See
     * {@see permissionGate()} for why the default is the permissive one.
     */
    private const PERMISSION_MODE_ENV = 'SUGARCRUSH_PERMISSION_MODE';

    private const PERMISSION_MODE_CONFIG_KEY = 'permissionMode';

    private const PERMISSION_RULES_CONFIG_KEY = 'permissionRules';

    /**
     * The keys {@see permissionSettingsLayer()} accepts from the user's
     * hand-authored `settings.json` — Phase 6 item 4.
     *
     * A WHITELIST, so that file cannot reach the OTHER things
     * {@see permissionConfig()}'s return value decides. `trustedProjectHooks`,
     * `trustedProjectMcp`, `trustedProjectCommands` and
     * `trustedProjectSettings` are deliberately not here: they are the grants
     * that make a repository's files policy at all, and the file they are
     * written in should be the one the CLI itself writes and the one a user
     * edits knowing it is the root of trust — not a second file that happens to
     * be read for a mode.
     *
     * @var list<string>
     */
    private const PERMISSION_SETTINGS_KEYS = [
        self::PERMISSION_MODE_CONFIG_KEY,
        self::PERMISSION_RULES_CONFIG_KEY,
    ];

    /**
     * The per-user opt-in that makes a PROJECT `.sugar-crush/hooks.yaml`
     * eligible to run at all — a list of project roots, in the same
     * `~/.sugar-crush/config.json` {@see PERMISSION_RULES_CONFIG_KEY} is read
     * from and parsed with the same warn-and-skip-the-bad-entry tolerance.
     * See {@see hookFiles()} for why the default has to be "no".
     */
    private const TRUSTED_PROJECT_HOOKS_CONFIG_KEY = 'trustedProjectHooks';

    /**
     * The SIBLING opt-in that makes a PROJECT `.mcp.json` eligible to start its
     * servers — same file, same shape, same parser as
     * {@see TRUSTED_PROJECT_HOOKS_CONFIG_KEY}. See {@see mcpClient()} for why
     * starting an MCP server is code execution and therefore needs a gate at all.
     *
     * A SEPARATE KEY, and reusing the hooks one was considered and refused:
     * every root a user has already listed under `trustedProjectHooks` would
     * silently acquire the right to spawn arbitrary long-lived processes, which
     * is a security grant being widened by an upgrade rather than by the user.
     * The two decisions are also genuinely different — "run the shell commands
     * this repo checked into `hooks.yaml`" is not "let this repo start whatever
     * servers it names and keep them running for the session".
     */
    private const TRUSTED_PROJECT_MCP_CONFIG_KEY = 'trustedProjectMcp';

    /**
     * The THIRD opt-in of the same shape: it makes a PROJECT command file's
     * `` !`cmd` `` form eligible to run at all
     * ({@see \SugarCraft\Crush\Chat::refuseCommandShell()}).
     *
     * A THIRD SEPARATE KEY, for the reason {@see TRUSTED_PROJECT_MCP_CONFIG_KEY}
     * gives for the second: folding it into either existing key would hand every
     * root already listed there a new capability by upgrade rather than by
     * decision. And the decision genuinely differs again — "run what this repo
     * put in hooks.yaml" fires on tool use, whereas this fires when the operator
     * types a `/name` the popup offered them, which is the one place a shell
     * execution looks like a menu selection.
     */
    private const TRUSTED_PROJECT_COMMANDS_CONFIG_KEY = 'trustedProjectCommands';

    private const DEFAULT_PERMISSION_MODE = PermissionMode::BypassPermissions;

    /**
     * The most launch notices one launch may seed a transcript with.
     *
     * A CAP ON A LIST THAT NOTHING ELSE BOUNDS. {@see $launchNotices} feeds
     * {@see Chat::withLaunchNotices()}, which turns each entry into a
     * {@see Role::System} row of the conversation — so the list is not merely
     * rendered, it is SENT TO THE MODEL on the first turn and on every turn
     * after it. That makes an unbounded list a per-token cost for the whole
     * session, not a scrolling nuisance.
     *
     * Most of the sources are bounded at one per launch: the skill-skip count,
     * the untrusted `hooks.yaml`, the empty tool set, the project tier's tool
     * removals, the two agent-preset degradations, the two provider fallbacks
     * and — since E78 round 42 — {@see reportPrunedSessions()}'s retention
     * SUMMARY: nine. (This paragraph said EIGHT and stopped at the provider
     * fallbacks; the retention summary is a ninth bounded source, and it is
     * bounded precisely because its per-session rows were deliberately left off
     * this seam.) {@see reportProjectTierRefusals()} adds one per refused
     * DIRECTORY, and its doc-block names eight feeding subsystems.
     * {@see permissionRules()} adds one whole-key complaint. So 18 is the most a
     * launch reaches without a per-ENTRY fan-out, and 24 clears that with
     * headroom while still refusing to let a config with fifty malformed rules
     * become the transcript. The overflow is COUNTED and reported as one
     * trailing row — see {@see launchNotices()} — rather than dropped, because a
     * silently truncated warning list is the defect this seam exists to end.
     */
    private const LAUNCH_NOTICE_LIMIT = 24;

    /**
     * The most characters one launch notice may contribute to the transcript.
     *
     * The messages routed here interpolate values NOTHING bounds — a path from
     * the user's config, a glob from a project's `disabledTools`, an exception
     * message from a preset registry. MEASURED against the longest legitimate
     * message this class can build today, {@see hookFiles()}'s untrusted-file
     * notice at 283 characters with a realistic pair of absolute paths; the
     * tool-removal report is 179 and the rest are under 190. 400 clears every
     * one of them, so no honest warning is ever clipped, and it still bounds a
     * hostile one.
     *
     * THE STDERR COPY IS NOT CLIPPED. That channel is the complete record and
     * costs no tokens — see {@see warnPermissionConfigInTranscript()}, which is
     * why the clipped row says where the full text is.
     */
    private const LAUNCH_NOTICE_MAX_CHARS = 400;

    /**
     * Appended to a clipped notice, and counted against
     * {@see LAUNCH_NOTICE_MAX_CHARS} so the row never exceeds it.
     */
    private const LAUNCH_NOTICE_CLIP_SUFFIX = '… (clipped; full text on stderr)';

    /**
     * Project hook files this process has already reported as skipped, keyed
     * by path — see the notice in {@see hookFiles()} for why it may only fire
     * once per launch. Static because the duplication comes from ONE launch
     * building two hook chains, which is a property of the process rather than
     * of any instance.
     *
     * @var array<string, true>
     */
    private static array $reportedUntrustedHookFiles = [];

    /**
     * Permission-config warnings this process has already printed, keyed by
     * message — see {@see warnPermissionConfigOnce()}.
     *
     * @var array<string, true>
     */
    private static array $reportedPermissionConfigWarnings = [];

    /**
     * Launch warnings this LAUNCH wants shown INSIDE the TUI, in the order they
     * were raised — {@see Chat::withLaunchNotices()}'s input.
     *
     * SCOPED TO THE LAUNCH, and deliberately NOT keyed the way
     * {@see $reportedPermissionConfigWarnings} is. That map answers "has this
     * process already printed this sentence", which stays true across a second
     * {@see chat()} in the same process; this list answers "what does the
     * transcript being built right now need to carry", which does not. The
     * asymmetry is the same one {@see $projectTierRefusals} keeps against the
     * same map, and {@see chat()} resets both in the same place.
     *
     * De-duplicated on VALUE because the launch raises some of these more than
     * once — {@see app()} builds the tool set a second time for the shell's
     * tool list, so {@see reportProjectTierToolRemovals()} runs twice with an
     * identical message and the transcript must not say it twice.
     *
     * @var list<string>
     */
    private static array $launchNotices = [];

    /**
     * The notices this launch raised past {@see LAUNCH_NOTICE_LIMIT}, keyed by
     * message.
     *
     * Kept rather than merely refused, so {@see launchNotices()} can say "and N
     * more" instead of ending the list where the cap happened to fall. Reset in
     * {@see chat()} beside the list it belongs to — what THIS launch could not
     * fit is a fact about the launch, exactly as the list is.
     *
     * A SET, NOT A COUNTER, and that is not tidiness. MEASURED: {@see chat()}
     * reaches {@see permissionRules()} twice (through {@see permissionGate()}
     * and through {@see agentManager()}), so every message it raises is raised
     * twice. {@see $launchNotices} de-duplicates the ones that FIT, by value;
     * an integer counter had no such memory and charged the overflow twice —
     * a 30-rule config reported "and 12 more" for 6 it could not fit. Keying on
     * the message gives the dropped half the same de-duplication the kept half
     * already had.
     *
     * ITS OWN BOUND IS THE ONE {@see $reportedPermissionConfigWarnings} ALREADY
     * HAS — one entry per DISTINCT message — and it is worth naming because
     * {@see chat()} is the only thing that resets it. A `-p` run never calls
     * chat(), so a host that loops {@see \SugarCraft\Crush\Cli\NonInteractive::run()}
     * in one process accumulates keys here exactly as it already accumulates
     * them in that map. Bounded by the number of distinct malformed config
     * entries, not by the number of runs.
     *
     * @var array<string, true>
     */
    private static array $launchNoticesDropped = [];

    /**
     * The config FILE `--config` named, or null to discover
     * `~/.sugar-crush/config.json` — see {@see useConfigPath()}.
     */
    private static ?string $configPathOverride = null;

    /**
     * The model `--model` named, or null to fall back to `$SUGARCRUSH_MODEL`
     * and then the provider default — see {@see useModel()}.
     */
    private static ?string $modelOverride = null;

    /**
     * The RAW, UNVALIDATED string `--permission-mode` named, or null when the
     * flag was absent — see {@see usePermissionMode()} for why it is stored
     * unvalidated.
     */
    private static ?string $permissionModeOverride = null;

    /**
     * The trusted project roots this process resolved, keyed by the
     * `config.json` they came out of — see {@see trustedRootsForThisProcess()}
     * for why the answer may not be recomputed mid-session.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedRoots = [];

    /**
     * The same, for {@see TRUSTED_PROJECT_MCP_CONFIG_KEY} — a SEPARATE map
     * because it holds a separate key's answer, frozen for the same reason
     * {@see trustedRootsForThisProcess()} gives: a session that prompt-injects a
     * line into the user's config must not be able to make that line take effect
     * in the session that wrote it.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedMcpRoots = [];

    /**
     * The same again, for {@see TRUSTED_PROJECT_COMMANDS_CONFIG_KEY}, frozen for
     * the reason {@see trustedRootsForThisProcess()} gives: a session that gets
     * a line appended to the user's config must not be able to make that line
     * take effect in the session that wrote it.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedCommandRoots = [];

    /**
     * The same again, for {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY},
     * frozen for {@see trustedRootsForThisProcess()}'s reason and for that
     * reason ALONE. The freeze is per resolved config PATH, so a test that
     * repoints `--config` gets a fresh answer.
     *
     * NO EXTRA THREAT OVER ITS THREE SIBLINGS, stated because the first cut of
     * this doc-block claimed one: that "a project's own `settings.local.json`
     * is writable by anything running in the checkout, so without the freeze a
     * turn could append a trust line and have its own later reads honour it".
     * That is not reachable, and the review that caught it measured why. This
     * key is not in {@see LayeredSettings::LAYERED_KEYS}, so
     * `LayeredSettings::only()` drops it from every settings file at every
     * tier — a project's, and the user's own `settings.json` too — and the list
     * itself is read from `config.json` through {@see permissionConfig()},
     * which the layering does not touch. A write to a settings file therefore
     * cannot add a trust entry at all, frozen or not. What the freeze actually
     * buys here is what it buys for hooks, MCP and commands: one answer per
     * process, so a mid-session edit to the user's OWN `config.json` cannot
     * widen the grant a launch already decided.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedSettingsRoots = [];

    /**
     * The project root {@see readUserConfig()}'s project layer is read from, or
     * null for "no project named yet" — see
     * {@see useProjectRootForSettings()}.
     */
    private static ?string $projectRootForSettings = null;

    /**
     * The hook entries this process read, keyed by file path — see
     * {@see hookFileEntries()} for why the file is read once per launch.
     *
     * @var array<string, array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>>
     */
    private static array $hookFileEntries = [];

    /**
     * Every SKILL.md the launch's skill scan gave up on, keyed by path — the
     * diagnostic {@see \SugarCraft\Crush\Skills\SkillLoader::recordSkip()}
     * keeps instead of writing to stderr, hoisted here so it survives past the
     * manager that produced it. See {@see skillSkips()}.
     *
     * @var array<string, string>
     */
    private static array $skillSkips = [];

    /**
     * The subset of {@see $skillSkips} already put in front of the user — see
     * {@see reportSkillSkips()}.
     *
     * @var array<string, true>
     */
    private static array $reportedSkillSkips = [];

    /**
     * Every directory this launch refused to read, keyed by the path as
     * configured — see {@see reportProjectTierRefusals()}.
     *
     * "PROJECT-TIER" IS NOW NARROWER THAN THE CONTENTS, and the name is kept
     * while the claim is corrected rather than the reverse. FOUR subsystems feed
     * it — the count was two for one round after a third arrived, and three until
     * crush_code.md Phase 1 item 3 wired the fourth: the workflow registry
     * ({@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()}),
     * the skill loader
     * ({@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()}), the
     * native agent-preset registry
     * ({@see \SugarCraft\Crush\Agents\AgentPresetRegistry::refusedDirectories()}),
     * merged in {@see agentPresets()} on both its return and its degradation
     * paths, and the FOREIGN one
     * ({@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::refusedDirectories()}),
     * merged in {@see foreignAgentPresets()} on both of its paths for the same
     * reason, and the custom-command loader
     * ({@see \SugarCraft\Crush\Commands\CommandLoader::refusedDirectories()}),
     * drained in {@see chat()} AFTER the {@see \SugarCraft\Crush\Chat}
     * construction that performs the walk (crush_code.md Phase 2 item 4).
     *
     * SIX SEAMS, THEREFORE, NOT FIVE: the workflow registry exposes a SECOND
     * one, {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::userTierRefusal()},
     * drained in {@see workflowEngine()} — so four feeders expose five seams. Its
     * subject is `~/.sugar-crush/workflows`
     * — the user's OWN directory, whose location no repository chose — so it is
     * the one entry here that is not a project tier. It is drained into this map
     * anyway, because the map's real subject is "directories this launch will not
     * read, and why", the notice that prints it is worded `ignoring <path> —
     * <reason>`, and the user tier is the tier whose files are `require`d: a
     * refusal there costs the user workflows they wrote themselves and must be
     * the LOUDEST entry, not an absent one. Renaming the collector would touch
     * every reader of {@see projectTierRefusals()} and is not this change-set;
     * the mismatch is recorded here instead of being left for the next reader to
     * infer from the values.
     *
     * FIVE OTHER HOLDERS of a repository-chosen path do NOT feed this, and each
     * is named rather than counted, because "five feeders" quietly becoming
     * "five feeders and three things nobody drains" is the drift this collector
     * keeps producing. It was FOUR until crush_code.md Phase 1 item 3 wired
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}, and THREE until
     * Phase 2 item 4 wired {@see \SugarCraft\Crush\Commands\CommandLoader} — which
     * is what a named gap is for, twice over. It is FIVE now, and the three
     * that arrived with Phase 6 items 1+2 are NOT of the same kind as the two
     * that were already here, so "dormant and gated" is no longer the whole
     * list's property and is stated per entry instead:
     *
     *  - {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}
     *    (`.opencode/memory`) is DORMANT — nothing in `src/` or `bin/`
     *    constructs it — and GATED, which dormant does not imply and for one
     *    round did not mean. It exposes `refusedDirectories()` with nothing
     *    reading it yet;
     *  - `.sugar-crush/hooks.yaml` has its own trust gate
     *    ({@see projectHooksAreTrusted()}) and refuses the LAUNCH rather than
     *    degrading, so a collector entry would be unreachable;
     *  - `.sugar-crush/config.json` as read by
     *    {@see \SugarCraft\Crush\Agents\WorktreeConfig::readConfig()} became
     *    repository-chosen when {@see \SugarCraft\Crush\Agents\WorktreeManager}
     *    started passing the repository under management as its config
     *    directory. DORMANT: nothing in `src/` constructs a `WorktreeManager`,
     *    so a refusal recorded there would have no reader;
     *  - `.sugar-crush/settings.json` and
     *  - `.sugar-crush/settings.local.json`
     *    ({@see \SugarCraft\Crush\Config\LayeredSettings}) are the opposite of
     *    dormant — {@see readUserConfig()} reads them, and `EngineBackend`
     *    calls that once per TURN. They are gaps because of the FREQUENCY, not
     *    the wiring: an entry per read would either repeat every turn or make
     *    "this project is not opted in" — the ordinary state of every project
     *    the user has not listed under
     *    {@see \SugarCraft\Crush\Config\LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}
     *    — read as a failure in a doctor report. Both are gated
     *    ({@see projectSettingsTrusted()} plus two `ContainedPath` compares);
     *    neither is reported.
     *
     * The full enumeration and its derivation live in
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest}.
     *
     * One collector rather than four because the user does not care which class
     * noticed that their repository's directory was rejected.
     *
     * SIX WRITERS NOW, not five feeders: {@see mcpClient()} writes here DIRECTLY
     * rather than through a `refusedDirectories()`-style seam, because `.mcp.json`
     * is a FILE and there is no registry class between the read and this
     * collector. It is the only entry that is not a directory, which is worth
     * knowing when reading a refusal message from here — and it is also the only
     * one INVISIBLE to the derivation that produces the counts in
     * {@see projectTierRefusals()}, which sees `.<dir>/<segment>` literals and so
     * cannot see a bare dot-file. That doc-block says so where the numbers are;
     * the two writers `mcpClient()` registers here are "outside the tree" and
     * "not trusted", and both are gated before any `proc_open()`.
     *
     * WHAT IT DOES NOT COLLECT, so the absence is a decision: a `.mcp.json` that
     * was granted and then failed to START does not land here. That is a
     * different subject — a file read and honoured, not one declined — and it
     * would collide on this map's key with the two refusals above. See
     * {@see mcpClient()}'s catch for where that diagnostic goes and for the four
     * broken shapes that currently produce none at all.
     *
     * @var array<string, string>
     */
    private static array $projectTierRefusals = [];

    /**
     * The subset of {@see $projectTierRefusals} already reported.
     *
     * @var array<string, true>
     */
    private static array $reportedProjectTierRefusals = [];

    /**
     * The MCP clients whose servers are running, keyed FIRST by the pid that
     * started them and then by the `.mcp.json` path they were built from.
     *
     * MEMOIZED BECAUSE STARTING IS A SIDE EFFECT. One launch calls
     * {@see tools()} at least twice — {@see app()} once for the shell's tool
     * list and once more through {@see chat()} -> {@see backend()} — and a
     * Ctrl+P provider switch calls {@see backendFor()} again. Building a fresh
     * client each time would `proc_open()` every configured stdio server once
     * per call, so a session would accumulate duplicate third-party processes
     * for as long as it ran, and {@see stopMcpServers()} would only ever reach
     * the last set.
     *
     * KEYED BY PATH rather than a single slot: `chat($repoA)` followed by
     * `chat($repoB)` in one process is a supported shape (the tests do it), and
     * a single slot would hand repo B repo A's servers. The path is the
     * CANONICAL one — see {@see mcpClient()}, where four spellings of one root
     * used to make four clients and eight server processes.
     *
     * KEYED BY PID FIRST, because a `pcntl_fork()`ed child inherits this whole
     * map along with the rest of the parent's memory while the PROCESSES in it
     * remain the PARENT's. Two failures came out of a single flat map:
     *
     *  - a child exiting through PHP's normal shutdown ran the inherited hook and
     *    stopped the LIVE session's servers;
     *  - a child that started servers of its OWN — which
     *    {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::executeTask()}
     *    does, via `chdir()` -> {@see backend()} -> {@see tools()} — had them in
     *    a map the parent's {@see stopMcpServers()} never iterates, so they
     *    outlived the worker with nothing left to stop them.
     *
     * Both are the same bug: ownership was recorded once for the process that
     * happened to register first, not per client. The pid key IS the ownership
     * record, and {@see stopMcpServers()} stops exactly this pid's bucket.
     *
     * A SIDE EFFECT WORTH NAMING: a child that asks for the root its PARENT
     * already has a client for gets a MISS and starts its own servers. That is
     * correct rather than wasteful — the inherited client's pipes and process
     * handles are the parent's, and two processes taking turns reading one pipe is
     * a corrupted protocol stream, not a shared connection.
     *
     * @var array<int, array<string, McpClient>>
     */
    private static array $mcpClients = [];

    /**
     * Whether this process IMAGE has registered the shutdown hook that stops the
     * servers in {@see $mcpClients}.
     *
     * A PLAIN BOOLEAN IS ENOUGH, and it is worth saying why, because it used to be
     * paired with a `$mcpOwnerPid` guard that made the hook a NO-OP in any forked
     * child — which is what left a worker's own servers with nothing to stop them.
     * The hook is inherited across `pcntl_fork()` along with the rest of memory,
     * and {@see stopMcpServers()} acts on `getmypid()`'s bucket of
     * {@see $mcpClients} and no other. So the inherited hook does exactly the
     * right thing in a child — stops what the CHILD started, cannot touch the
     * parent's — and the pid discrimination belongs on the map key, where the
     * ownership actually is, not on the registration.
     *
     * @see registerMcpShutdown() for why there is no other seam.
     */
    private static bool $mcpShutdownRegistered = false;

    /**
     * Build the fully-wired Chat model the CLI binary hands to Program.
     *
     * $root defaults to getcwd() — the directory the CLI was invoked from —
     * matching the original inline IIFE's behaviour.
     */
    public static function chat(?string $root = null): Chat
    {
        // `?: null` rather than a bare `getcwd()`: the call can fail (deleted
        // cwd, permissions), every $root consumer below is typed ?string, and
        // the one layer that genuinely needs a string — {@see requireRoot()},
        // reached through backend() -> tools() — reports the missing root as a
        // clear error rather than handing a path jail a `false`.
        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings($root);

        // A LAUNCH's refusals, not a PROCESS's. This map is what
        // {@see projectTierRefusals()} advertises to a doctor report or a debug
        // pane, and without the reset `chat($badRepo)` followed by
        // `chat($goodRepo)` still reported $badRepo's directory as refused —
        // harmless for the one launch a real binary makes, wrong for every
        // consumer the accessor's doc-block invites.
        //
        // {@see $reportedProjectTierRefusals} is deliberately NOT reset with it,
        // and the asymmetry is the point rather than a half-done one: that set is
        // process-scoped noise control of the same kind
        // {@see warnPermissionConfigOnce()} keeps — "say this once per process"
        // stays true across launches, while "these are the directories refused"
        // is a fact about the launch being built.
        self::$projectTierRefusals = [];

        // Reset HERE, for {@see $projectTierRefusals}'s reason and in the same
        // breath: what the transcript being built has to carry is a fact about
        // this launch. It has to happen before `backend()` below, which is what
        // reaches {@see filterToolSet()} and raises the notice.
        self::$launchNotices = [];
        self::$launchNoticesDropped = [];

        // RESOLVED FOR ITS REFUSAL, NOT FOR ITS VALUE, and resolved FIRST.
        // {@see trustedConfigDirPath()} throws when this process cannot tell
        // whose home it is in, which is what stops the launch reading policy
        // out of `sys_get_temp_dir()`. It used to be reached through
        // permissionGate() — five calls further down, after sessionStore() and
        // skillRegistry() had already run — so a launch with `HOME` unset and
        // `TMPDIR` pointed at an attacker-owned directory still refused, but
        // only after CREATING `session.db` inside it. Naming the directory
        // before anything is built is what makes the refusal precede the side
        // effects it exists to prevent.
        self::trustedConfigDirPath();

        $userConfig = self::readUserConfig();

        // The `statusLine` command, installed for the whole process before any
        // frame is painted. Wired HERE rather than in {@see app()} because
        // `app()` calls this method, so this is the one funnel both interactive
        // entry points share — and because a standalone `Chat` (the shell-less
        // path {@see \SugarCraft\Crush\Renderer}'s docblock names as also
        // live) has to get it too.
        //
        // ALWAYS CALLED, including when nothing is configured: the call CLEARS
        // as well as sets, and a process that builds a second Chat against
        // different settings must not keep painting the first one's text. In a
        // test suite that runs many launches in one process this is the only
        // thing standing between them.
        //
        // $root, not `getcwd()`: a `git`-shaped status command must report the
        // repository the session was launched against. `--root <lib>` in a
        // monorepo is exactly the case where the two differ.
        StatusLineCommand::configure($userConfig, $root);

        // ONE store instance, seeded before the Chat is built: seedSession()
        // is what makes /sessions, the tab strip, /branch and the auto-title
        // call reachable at all on a real run (crush_feat.md §5 E1).
        $sessionStore = self::sessionStore();
        [$sessionId, $sessionName] = self::seedSession($sessionStore, ...self::selectedProviderLabel());

        // ONE registry across the engine and the sub-agents, for the same
        // reason {@see tools()} shares one across Read/Edit/Glob: two
        // independently scanned registries would let a skill disabled on one
        // still be invocable through the other, and a sub-agent granted a
        // skill the main loop was told not to offer is the wrong side of that
        // to be on. It also keeps the launch to ONE disk scan rather than one
        // per consumer.
        $skills = $root === null ? null : self::skillRegistry($root);

        // Construction time, before Program takes the terminal — see
        // {@see reportSkillSkips()} for why here and nowhere else.
        self::reportSkillSkips();

        // ONE gate for the whole launch, for the reason the registry above is
        // one: PermissionGate's Auto-mode circuit breaker is per-INSTANCE
        // state, so two gates would each need three strikes before either
        // escalated, and a user watching one counter would be watching half
        // the session's refusals (crush_code.md Phase 1 item 2). It is also
        // ONE read of the config, so the engine and Chat's own tool path can
        // never end up enforcing two different modes — including across a
        // Ctrl+P provider switch, which carries this instance rather than
        // rebuilding one (see Chat::selectPaletteProvider()).
        //
        // Do not over-read the circuit-breaker half of that on the TUI path:
        // EngineBackend::completeAsync() runs the turn in a pcntl_fork()ed
        // child, so the strike increments a turn makes die with the child.
        // Sharing the instance is what unifies the MODE and the RULES today;
        // "one counter for the session" needs the gate's state to cross the
        // fork boundary, which is a separate queued step. See
        // PermissionGateHook::NAME.
        $permissionGate = self::permissionGate();

        // Built here rather than inside the constructor call so its refusals
        // survive the statement — see the `commandLoader:` argument below.
        $commandLoader = new CommandLoader();

        $chat = new Chat(
            backend: self::backend($root, $skills, $permissionGate),
            memoryStore: self::memoryStore(),
            sessionStore: $sessionStore,
            currentSessionId: $sessionId,
            currentSessionName: $sessionName,
            titleBackend: self::titleBackend(),
            // crush_code.md Phase 5 item 6. Without this argument `/compact`
            // reaches only the heuristic summarizer, whose stage-2 output for a
            // long exchange was the literal string "[exchanged information]" —
            // a compaction that preserved nothing of what a compaction exists to
            // preserve.
            summaryBackend: self::summaryBackend(),
            // crush_code.md Phase 5 item 7. Null unless the launch set a cap;
            // `/budget` can set one at runtime either way.
            maxCostUsd: self::maxCostUsd(),
            themeName: is_string($userConfig['theme'] ?? null) ? $userConfig['theme'] : 'dark',
            onConfigChange: static fn(string $key, string $value) => self::writeUserConfig([$key => $value]),
            mosaic: ToolResult::mosaic(),
            // The same built-in guard chain backend()/backendFor() hand the
            // engine backend. Without it, Chat's own registerTool() calls
            // would still be the one unguarded tool path in the live binary
            // (crush_feat.md §1 E1) - hooks that a call gets gated by on the
            // engine pipeline would silently not apply on this one.
            hooks: self::hooks($permissionGate, $root),
            // Without a supervisor instance /bg answers "Background sessions
            // not configured" on every real run, which leaves crush_feat.md
            // §5 E3 (`/bg` dispatching onto BackgroundSupervisor) implemented
            // everywhere except where a user can reach it. One supervisor per
            // launch: it owns the spawned sessions' IPC table, and a second
            // instance would not know about the first's children.
            backgroundSupervisor: new BackgroundSupervisor(),
            // The same root the tools above are jailed to. Chat's own
            // pipeline builds hook contexts and spawns background sessions
            // without an App or Runtime in reach, so it needs its own copy
            // or `--root` stops at the tool boundary (crush_code.md Phase 0
            // item 6).
            projectRoot: $root,
            // crush_code.md Phase 0 item 13's second half. Every provider on
            // the engine path already streamed, and {@see Chat} already had a
            // `$streaming` flag - but nothing ever turned it on, so
            // {@see Chat::scheduleBackendCompletion()} passed a null $onToken
            // to the backend on every real run and the reply arrived in one
            // piece after a silent "thinking…" spinner, having paid the full
            // SSE-parsing cost for nothing.
            //
            // No `onToken:` closure alongside it: that field is an OPTIONAL
            // extra observer for embedders (see its docblock), and the live
            // TUI rendering is driven off the shared inbox instead. Passing
            // one here would only duplicate what the pump already does.
            streaming: true,
            // crush_code.md Phase 1 item 1. Until now this argument was never
            // passed on the one construction path `bin/sugarcrush` runs, so
            // `/agents`, Ctrl+A, the transcript's agent strip,
            // AgentDashboardPane's agent rows, PermissionGate (whose only
            // consumer is the sub-agent path) and the whole
            // TeamManager/worktree stack downstream of them were built,
            // tested, and unreachable — `Chat::handleAgentsCommand()` answered
            // "Agent manager not configured" on every real run.
            agentManager: self::agentManager($root, $skills),
            // crush_code.md Phase 2 item 3. `/workflow run|pause|resume|status|
            // list` answered "Workflow engine not configured" on every real run
            // because this argument was never passed — the 2,200-line
            // Workflows/ subsystem, the shipped `workflows/deep-research.php`
            // and `examples/workflows/lint-then-fix.yaml` included, was
            // reachable only from its own tests.
            //
            // Chat's constructor is what links the two: an engine that arrives
            // without a manager is given this launch's, so a parallel stage's
            // sub-agents register where the renderer reads telemetry from — and
            // it can only do that with both in hand. (Both are NAMED arguments
            // and both are evaluated before the constructor body runs, so the
            // order they appear in here is style, not mechanism.)
            workflowEngine: self::workflowEngine($root, $permissionGate),
            // crush_code.md Phase 2 item 4. Until now nothing in src/ or bin/
            // constructed a CommandLoader at all, so `~/.sugar-crush/commands`
            // and `<root>/.sugar-crush/commands` were directories the loader
            // knew how to walk and no launch ever asked it to — a `*.md` command
            // file was inert on every real run.
            //
            // THE INSTANCE IS HELD, not inlined into the argument, because the
            // refusals it accumulates are drained off it below. An anonymous
            // `new CommandLoader()` here would report a commands directory that
            // resolves outside the checkout to `error_log()` and nowhere else,
            // which on a full-screen TUI is nowhere the user will look.
            commandLoader: $commandLoader,
            // The `` !`cmd` `` gate for the PROJECT tier of that loader. Resolved
            // here rather than inside Chat so it is answered once, at launch,
            // from the frozen trust list — see
            // {@see projectCommandShellIsTrusted()}. A null $root cannot be
            // trusted by a list of absolute paths, so it is false without asking.
            projectCommandsTrusted: $root !== null && self::projectCommandShellIsTrusted($root),
        );

        // Drained AFTER construction for the same reason the workflow registry's
        // refusals are: the walk happens inside the constructor, so there is
        // nothing to collect until it has run. Adding this makes commands the
        // EIGHTH feeder of {@see $projectTierRefusals} — see
        // `ProjectTierRefusalInventoryTest`, which pins the feeder/gap split so
        // a directory cannot quietly stop being either.
        self::$projectTierRefusals = [
            ...self::$projectTierRefusals,
            ...$commandLoader->refusedDirectories(),
        ];

        // And the per-FILE refusals: a command file that tried to take over a
        // control-plane name ({@see \SugarCraft\Crush\Commands\CommandRegistry::CONTROL_PLANE}).
        // Path-keyed at the source, so it spreads in like every other feeder,
        // and it introduces no new repository-chosen DOT-PATH — the file lives
        // under `.sugar-crush/commands`, already the eighth feeder above.
        self::$projectTierRefusals = [
            ...self::$projectTierRefusals,
            ...$commandLoader->refusedCommands(),
        ];

        // AFTER the construction above, not beside reportSkillSkips() further
        // up: the workflow registry that decides whether this project's
        // `.sugar-crush/workflows` is readable is built inside
        // workflowEngine(), which is one of the named arguments to the
        // constructor call. Still construction time, so still before Program
        // takes the terminal — the requirement reportSkillSkips()'s doc-block
        // states.
        self::reportProjectTierRefusals();

        // LAST, so every warning the build raised is in hand — including
        // reportProjectTierRefusals() immediately above, which is one of the
        // SIXTEEN call sites now routed onto the transcript seam. This said
        // FIFTEEN, counting reportPrunedSessions()'s retention summary (E78,
        // round 42) as the last one; E86 (round 43) added the sixteenth, in
        // mcpClient()'s start-then-throw catch. Both of those are the reason
        // this line is LAST rather than merely tidy: the retention summary is
        // raised from sessionStore() far EARLIER in this method, and the MCP
        // one is raised far LATER, transitively through backend() -> tools() ->
        // mcpTools(), so only a read at the END has both in hand. The count is
        // a grep of this file for `self::` immediately followed by the seam's
        // name — deliberately not spelled out here, because a comment quoting
        // that literal makes itself the seventeenth hit. No line numbers
        // either, for the same reason one insertion above decays them.
        // See {@see Chat::withLaunchNotices()}.
        //
        // THE ACCESSOR, not the raw list: {@see launchNotices()} appends the
        // "and N more" row when a launch overflowed {@see LAUNCH_NOTICE_LIMIT},
        // and reading the property directly here would hand the transcript a
        // silently truncated list — the exact failure mode the cap was added
        // with a counter rather than as a bare array_slice().
        return $chat->withLaunchNotices(self::launchNotices());
    }

    /**
     * The {@see WorkflowEngine} `/workflow` dispatches onto, reading workflows
     * from this user's `~/.sugar-crush/workflows` and, when the launch has a
     * root, that project's `.sugar-crush/workflows`.
     *
     * {@see trustedConfigDirPath()} rather than {@see configDirPath()}, and the
     * distinction is not bookkeeping: {@see WorkflowRegistry::load()} reaches a
     * `.php` workflow through `require`, so the user tier is a directory whose
     * contents get EXECUTED, which is the same class of thing as `hooks.yaml`
     * and not the class of thing the session store is. Under the stand-in home
     * a local user could pre-create `/tmp/.sugar-crush/workflows/deploy.php`
     * and own the session the moment its owner typed `/workflow run deploy`.
     * {@see chat()} already refuses that launch on its first line, so this is
     * not a second gate — it is the requirement stated where the directory it
     * applies to is named, so a future second caller of this method inherits
     * the refusal rather than having to remember it.
     *
     * The project tier needs no such refusal because it is `.yaml`-only by
     * construction — see {@see WorkflowRegistry::__construct()} for why a
     * checked-in `.php` workflow is not honoured at all.
     *
     * Both `$model` and `$provider` come from the launch's own selection rather
     * than {@see WorkflowEngine}'s defaults; see that constructor for what the
     * defaults were and why they are wrong for any session that did not select
     * them.
     *
     * @param PermissionGate $gate The launch's ONE gate — the same instance
     *        {@see chat()} hands the backend and the hook chain, for the reason
     *        stated there. Required rather than defaulted precisely because a
     *        default would let a future caller build a SECOND gate here without
     *        noticing: two gates from one config enforce the same mode but split
     *        PermissionGate's per-instance Auto-mode strike counter, which is the
     *        thing {@see chat()} building exactly one exists to prevent. Passing
     *        it is what makes a workflow-spawned
     *        {@see \SugarCraft\Crush\Agents\SubAgent} carry a gate at all
     *        (before this, every one of them was constructed with
     *        `permissionGate: null`) and what lets the engine refuse a stage
     *        whose DECLARED tools this session's mode denies. Read
     *        {@see WorkflowEngine::__construct()} for the precise boundary of
     *        what that does and does not enforce today — it is narrower than
     *        "workflow tool calls are gated", and the difference is stated there
     *        rather than implied here.
     */
    private static function workflowEngine(?string $root, PermissionGate $gate): WorkflowEngine
    {
        [$provider, $model] = self::selectedProviderLabel();

        $userConfigDir = self::trustedConfigDirPath();

        $registry = new WorkflowRegistry(
            $userConfigDir . '/workflows',
            $root === null ? null : rtrim($root, '/') . '/.sugar-crush/workflows',
            // The root is passed, not merely used to build the path above,
            // because it is the boundary the project workflows DIRECTORY is
            // held inside — and it is the complete boundary only when the
            // registry is told it. Without it the registry falls back to
            // that directory's own parent, which a committed
            // `.sugar-crush -> /elsewhere` walks straight past. See
            // {@see WorkflowRegistry::readableProjectDir()}.
            projectRoot: $root,
            // THE SAME ANCHOR, FOR THE TIER THAT IS `require`d, and the reason it
            // is passed rather than left to the registry's parent-directory
            // fallback is the reason $root above is: the fallback catches a link
            // AT `workflows` and walks past one at `.sugar-crush`. Derived from
            // the ONE trusted resolution the directory itself came from — the
            // same construction {@see agentPresetTiers()} uses — so the workflows
            // directory and the home it is held inside can never be two
            // different homes. Measured before this existed: a tarball-delivered
            // `~/.sugar-crush/workflows -> <outside>` had `/workflow run` execute
            // arbitrary PHP as the launching uid; see
            // {@see WorkflowRegistry::__construct()}.
            userHome: \dirname($userConfigDir),
        );

        // Asked EAGERLY, here, rather than left for the first `/workflow list`
        // to discover: the refusal is otherwise invisible in every direction a
        // user looks — the not-found message stops naming the directory,
        // `projectWorkflowsPath()` still reports it, and the listing simply has
        // fewer names in it. See {@see reportProjectTierRefusals()}.
        $refusal = $registry->projectTierRefusal();
        if ($refusal !== null && $registry->projectWorkflowsPath() !== null) {
            self::$projectTierRefusals[$registry->projectWorkflowsPath()] = $refusal;
        }

        // AND THE USER TIER'S, which is the one refusal in this collector that is
        // not about a repository-chosen directory — see the collector's own
        // doc-block for why it is drained here anyway. A user whose own
        // `~/.sugar-crush/workflows` this launch will not `require` out of has
        // otherwise nothing at all telling them so: `list()` is simply shorter and
        // the not-found message names a directory the loader never opened.
        $userRefusal = $registry->userTierRefusal();
        if ($userRefusal !== null) {
            self::$projectTierRefusals[$registry->workflowsPath()] = $userRefusal;
        }

        return new WorkflowEngine(
            $registry,
            model: $model,
            provider: $provider,
            permissionGate: $gate,
        );
    }

    /**
     * Every project-tier directory this launch declined to read, keyed by the
     * configured path, mapped to why.
     *
     * The pull-based seam {@see skillSkips()} is, for the other half of the same
     * question: a repository chooses where these paths point, and one that
     * resolves out of the checkout is refused wholesale. Kept where a doctor
     * report or a debug pane can ask for it, with
     * {@see reportProjectTierRefusals()} putting one bounded line in front of
     * the user at launch.
     *
     * THIRTEEN repository-chosen DOT-DIRECTORY paths exist in `src/` — and the
     * qualifier is the number's domain rather than decoration. What the
     * derivation counts is a string literal of the shape `.<dir>/<segment>`:
     * TWENTY-THREE distinct ones on this tree, thirteen of them classified
     * repository-chosen. This list said FOUR, then FIVE, both hand-written; it is
     * now DERIVED from `src/` by
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest}, which
     * reds when a fourteenth appears.
     *
     * IT WENT TEN -> THIRTEEN IN ONE CHANGE-SET, from three different causes, and
     * they are worth separating because only one of them is a new path:
     *
     *  - `.sugar-crush/settings.json` and `.sugar-crush/settings.local.json` are
     *    genuinely new — the project tier of
     *    {@see \SugarCraft\Crush\Config\LayeredSettings}.
     *  - `.sugar-crush/config.json` was RECLASSIFIED, not added. It was
     *    package-relative for as long as {@see \SugarCraft\Crush\Agents\WorktreeManager}
     *    built its config with a bare `WorktreeConfig::new()`; that constructor
     *    now passes the repository under management, so the same literal reads a
     *    tree the operator cloned.
     *
     * The DISTINCT count had also drifted on its own: it read "twenty" while
     * `src/` held twenty-one, because no assertion stood behind it — a figure in
     * prose decays silently. {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest::testBothCensusFiguresThisDocBlockQuotes()}
     * now pins both numbers, which is the only thing that keeps a sentence like
     * this one true.
     *
     * THE SHAPE EXCLUDES A BARE DOT-FILE, and one of those is in this map. A
     * literal with no directory component — `src/` holds exactly two,
     * `.mcp.json` in {@see mcpClient()} and `.phpunit.cache` in `IgnoreRules` —
     * cannot match, so `.mcp.json` is repository-chosen, feeds this collector, and
     * is not one of the ten. EIGHT repository-chosen paths therefore produce
     * entries here: the seven below plus `.mcp.json`, which
     * {@see $projectTierRefusals} records as the only entry that is not a
     * directory. Stating the two figures without their domain is how the count
     * that this whole enumeration exists to prevent gets made in the sentence
     * describing it.
     *
     * The SEVEN of the ten whose refusals reach THIS map:
     *
     *   `.sugar-crush/workflows`  `.sugar-crush/skills`  `.claude/skills`
     *   `.opencode/skills`        `.sugar-crush/agents`  `.claude/agents`
     *   `.opencode/agents`
     *
     * The last two joined in crush_code.md Phase 1 item 3, which wired
     * {@see foreignAgentPresets()} and gave that registry's refusal seam its first
     * reader; the split was five and five before it.
     *
     * The FIVE that are gated elsewhere and named as gaps rather than counted
     * here — `.opencode/memory`, `.sugar-crush/hooks.yaml`,
     * `.sugar-crush/config.json`, `.sugar-crush/settings.json`,
     * `.sugar-crush/settings.local.json` — are itemised on
     * {@see $projectTierRefusals}, all five of them.
     *
     * `.sugar-crush/commands` IS NOT ONE OF THEM, and this sentence used to say
     * it was — a gap list that went stale when crush_code.md Phase 2 item 4
     * wired {@see \SugarCraft\Crush\Commands\CommandLoader} and
     * {@see chat()} started draining `refusedDirectories()` straight into
     * {@see $projectTierRefusals}. It is the EIGHTH feeder. The stale sentence
     * then very nearly reclassified the live code to match itself, which is the
     * direction this project's recurring defect always runs: prose is easier to
     * believe than a drain twenty lines long.
     *
     * The last three of the five joined with the settings layering and the
     * `WorktreeConfig` reclassification above, and all three are gaps
     * DELIBERATELY rather than pending work. `.sugar-crush/config.json` is read
     * by a class nothing in `src/` constructs, so a refusal it recorded would
     * have no reader. The two `settings*.json` files are read by
     * {@see readUserConfig()}, which `EngineBackend` calls once per TURN: an
     * entry per read would either repeat or, worse, make "this project is not
     * opted in" — the ordinary state of every project the user has not listed —
     * look like a failure in a doctor report. Both are gated; neither is
     * reported.
     *
     * @return array<string, string> configured path => why it was refused
     */
    public static function projectTierRefusals(): array
    {
        return self::$projectTierRefusals;
    }

    /**
     * Tell the user, once per refused directory, that a project directory their
     * repository ships was not read.
     *
     * NAMED INDIVIDUALLY, unlike {@see reportSkillSkips()}'s bare count, and the
     * asymmetry is deliberate: a skipped SKILL.md is somebody else's malformed
     * file and there can be dozens, while a refused directory is a deliberate
     * refusal of something this repository committed, there is at most one per
     * subsystem, and the path plus the reason is the whole of what a user needs
     * to fix it. A count alone ("1 directory was refused") would be the same
     * silence the notice exists to end.
     *
     * Construction time only, for the reason {@see reportSkillSkips()} states:
     * stderr under a live alt screen lands inside a frame the renderer believes
     * it owns.
     */
    public static function reportProjectTierRefusals(): void
    {
        $new = array_diff_key(self::$projectTierRefusals, self::$reportedProjectTierRefusals);

        foreach ($new as $path => $reason) {
            self::$reportedProjectTierRefusals[$path] = true;
            // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. A
            // refused directory is this repository's skills, commands, agents
            // or workflows absent from the session, and the user meets that by
            // typing a name that does not resolve. The path plus the reason is
            // the whole of what they need, which is why this stayed a per-path
            // line rather than a count.
            //
            // THE ONE PER-PATH SOURCE ON THE SEAM, and the reason
            // {@see LAUNCH_NOTICE_LIMIT} exists: the eight feeders named above
            // are bounded, but $commandLoader->refusedCommands() is one entry
            // per refused FILE and nothing caps that.
            self::warnPermissionConfigInTranscript(sprintf('ignoring %s — %s', $path, rtrim($reason, '.')));
        }
    }

    /**
     * The {@see AgentManager} a launch delegates through, with the run's
     * roster already registered.
     *
     * Built here rather than inside {@see Chat} because a manager needs a
     * {@see ProviderInterface} and a {@see SkillRegistry} — Chat holds neither
     * (it holds the unrelated {@see Backend} interface), which is the exact
     * reason `Renderer.php`'s R20.fix note gave for the wiring never having
     * landed. Both are already built here for other consumers, so this is a
     * construction-wiring method, not new machinery.
     *
     * @param SkillRegistry|null $skills Pass the caller's registry to avoid a
     *        second disk scan; the sub-agents this manager runs resolve their
     *        `skills:` names against it, so sharing the instance also means a
     *        skill disabled in the user config is disabled for sub-agents too.
     * @param \Closure(\SugarCraft\Crush\ToolCall, \SugarCraft\Crush\Agents\SubAgent): bool|null $approver
     *        Settles a sub-agent's {@see \SugarCraft\Crush\Permissions\PermissionDecision::Ask}.
     *        Null leaves it failing closed — see
     *        {@see AgentManager::evaluateToolCalls()}. Nothing supplies one
     *        yet: the caller that should is {@see Chat}, which owns the
     *        blocking prompt UI, and that wiring is its own change.
     *
     * @throws PermissionConfigException when the permission config is present
     *         and unusable — raised HERE, on the launch path, rather than from
     *         inside the gate factory at {@see AgentManager::createSubAgent()}
     *         time. See the eager read in the body.
     */
    public static function agentManager(?string $root = null, ?SkillRegistry $skills = null, ?\Closure $approver = null): AgentManager
    {
        $root = self::requireRoot($root);
        [$provider, $model] = self::provider();

        // Read here, EAGERLY, rather than inside the factory closure below.
        // The closure runs at createSubAgent() time, which — once `/agents`
        // dispatches onto it — is mid-TUI, where a PermissionConfigException's
        // only handler is `bin/sugarcrush`'s and its exit(2) would abandon the
        // terminal in alt-screen/raw mode with the message painted over the
        // frame. Reading at CONSTRUCTION puts the same refusal on the launch
        // path, before Program::run() has taken the screen, which is where
        // every other permission-config refusal already lands.
        //
        // The consequence is deliberate: the policy a launch starts with is
        // the policy its sub-agents get, even if the file is edited underneath
        // a running session. That is the same "one config source for the whole
        // launch" the main-loop gate already commits to.
        $permissionRules = self::permissionRules(self::permissionConfig());

        $manager = new AgentManager(
            $provider,
            $skills ?? self::skillRegistry($root),
            // Sub-agent gates are built from the SAME config the main loop's
            // gate is (crush_code.md Phase 1 item 2's "one config source"),
            // rather than AgentManager's own bare `new PermissionGate($mode)`
            // fallback: that fallback passes no SafetyClassifier, and
            // PermissionGate::evaluateAuto() fails closed without one — so a
            // preset declaring `permissionMode: auto` got a gate that asked
            // about literally every call instead of classifying any of them.
            // The MODE still comes from the preset, not from the config: an
            // agent that declares its own mode means it.
            permissionGateFactory: static fn(PermissionMode $mode): PermissionGate => new PermissionGate(
                $mode,
                $permissionRules,
                new SafetyClassifier(),
                // Named honestly: this gate's mode came from the preset, not
                // from the precedence chain permissionGate() walks, and a
                // sub-agent's `/permissions` must not claim otherwise.
                "this agent preset's permissionMode",
            ),
            permissionApprover: $approver,
        );

        // A snapshot captured at $root for every agent, which is what closes
        // the "sub-agents are told the process cwd, not the configured root"
        // gap: Agent::systemPrompt()'s own last-resort
        // EnvironmentBlock::capture(getcwd()) is documented as the fallback for
        // callers holding no session snapshot, and this caller holds one. A
        // `--root candy-shine` run now orients its sub-agents at candy-shine
        // rather than at wherever the binary was invoked from.
        //
        // Captured PER AGENT, at that agent's own model. The block renders a
        // `Model:` line into the prompt, so one shared instance stamped the
        // SESSION's model onto every agent: a preset declaring
        // `model: gpt-5-turbo` was handed to a sub-agent whose system prompt
        // said `Model: echo`. Sharing bought nothing in exchange either --
        // EnvironmentBlock::render() is not memoised, so its git shell-out
        // happens once per systemPrompt() call whichever instance it is called
        // on, and capture() itself only stores three values.
        foreach (self::agentRoster($root, self::selectedProviderName() ?? 'echo', $model) as $agent) {
            $manager->register($agent->withEnvironment(EnvironmentBlock::capture($root, $agent->model)));
        }

        return $manager;
    }

    /**
     * Every agent a launch can delegate to: the six built-in
     * {@see AgentDefinition} templates, then any `.md`+frontmatter preset
     * discovered under `{root}/.sugar-crush/agents` or `~/.sugar-crush/agents`.
     *
     * Presets are applied second and by name, so a project that ships its own
     * `reviewer.md` replaces the built-in `reviewer` rather than adding a
     * duplicate row to `/agents`.
     *
     * Everything is registered INACTIVE. On {@see Agent} active means
     * "currently working" — the renderers turn it into the literal word — so a
     * roster registered active would paint an agent strip on every launch
     * claiming six agents were working on a session where nothing has been
     * delegated. {@see AgentManager::active()} derives the live value from
     * running sub-agents instead.
     *
     * Foreign presets (`.claude/agents`, `.opencode/agents`, via
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}) ARE merged
     * here as of crush_code.md Phase 1 item 3 — see
     * {@see foreignAgentPresets()}. They go in FIRST, which makes the roster's
     * precedence three layers deep, lowest first:
     *
     *     foreign imports  <  the six built-in definitions  <  native presets
     *
     * NATIVE WINS AT EVERY TIER, built-ins included, and that is the merge
     * direction {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} already
     * established for skills rather than a second convention: it registers the
     * foreign trees first and lays the native manifests — built-in, user AND
     * project — over the top. Applied to agents the argument is the same one and
     * slightly sharper, because `reviewer`, `coder`, `tester`, `architect`,
     * `debugger` and `devops` are the names `/agents`, Ctrl+A and the agent strip
     * are documented against: cloning a repository that ships
     * `.claude/agents/reviewer.md` must not silently re-point `reviewer` at
     * somebody else's prompt. Additive is the only safe direction for a new
     * discovery source.
     *
     * WHAT THE IMPORT CARRIES INTO THE ROSTER USED TO BE NARROWER THAN THE
     * PRESET, AND NO LONGER IS — read this before relying on the sentence it
     * replaced, which said {@see Agent::fromPreset()} reads six fields "and
     * NOTHING ELSE" and that an imported `permissionMode:` "does not travel
     * this path at all". Both were true when written and are now false:
     * `fromPreset()` reads all sixteen of the preset's fields. Stated where
     * the merge happens rather than left to be inferred, because THIS method
     * is the one that performs it.
     *
     * `permissionMode` IS GATED ON PROVENANCE, which is what replaced the
     * accidental bound rather than nothing. {@see Agent::fromPreset()} copies
     * a NATIVE preset's mode and forces every foreign one to
     * {@see \SugarCraft\Crush\Permissions\PermissionMode::Default}, so the
     * `bypass-permissions` the foreign registry's own measurement showed
     * reaching a preset still cannot reach a rostered `Agent` from
     * `.claude/agents` or `.opencode/agents`. MEASURED end-to-end through this
     * method before the gate landed: a `.claude/agents` preset produced an
     * `Agent` carrying `BypassPermissions`. Asserted by
     * {@see \SugarCraft\Crush\Tests\Integration\ForeignAgentPresetWiringTest::testAnImportedPresetsPermissionModeIsForcedToDefaultOnTheRoster()}
     * — on the VALUE now, through this method's real path, rather than on
     * `Agent`'s field list, which is what the old assertion checked and what a
     * refactor could change without touching either file.
     *
     * The other fifteen fields are carried unconditionally and are UNREAD
     * rather than unrepresentable: nothing outside `Agent` itself consumes
     * them yet. That is the weaker of the two kinds of bound and it is worth
     * naming as such — it holds because of a census
     * ({@see \SugarCraft\Crush\Tests\Integration\ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode()})
     * rather than because of the type. It bounds THIS path and nothing else:
     * {@see \SugarCraft\Crush\Agents\AgentPreset} still carries every field, so a
     * future consumer reading presets directly inherits them ungated.
     *
     * {@see \SugarCraft\Crush\Agents\AgentPreset::$source} NOW HAS A CARRIER
     * and still has no reader. {@see Agent} carries a `$source` field and
     * `fromPreset()` copies it, so the tag survives onto the roster — but the
     * palette does not badge an imported row yet, so the remaining half of
     * Phase 1 item 3 is a call site rather than a field.
     *
     * @return list<Agent>
     */
    public static function agentRoster(string $root, string $provider, string $model): array
    {
        // RESOLVED FIRST, THOUGH IT IS CONSUMED LAST. agentPresets() is the call
        // that routes the user tier through {@see trustedConfigDirPath()}, which
        // THROWS when this process cannot establish whose home it is in — so
        // asking for it before any foreign directory is read keeps "refuse the
        // launch rather than read policy a stranger may have written" true of
        // this method on its own, not only of {@see chat()}, which happens to
        // resolve the same gate on its first line. The precedence documented
        // above is decided by the ORDER THESE ARE INSERTED into $agents below,
        // which is independent of the order they are fetched in.
        $native = self::agentPresets($root);

        $agents = [];

        // LOWEST PRIORITY: everything after this overwrites a shared key.
        foreach (self::foreignAgentPresets($root) as $name => $preset) {
            $agents[$name] = Agent::fromPreset($preset, $provider, $model);
        }

        foreach ([
            AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS,
        ] as $type) {
            $definition = AgentDefinition::fromType($type, $type);
            if ($definition !== null) {
                $agents[$definition->name] = Agent::fromDefinition($definition, $provider, $model);
            }
        }

        foreach ($native as $name => $preset) {
            $agents[$name] = Agent::fromPreset($preset, $provider, $model);
        }

        return array_values($agents);
    }

    /**
     * The agent presets other coding CLIs left on disk — Claude Code's
     * `.claude/agents` and opencode's `.opencode/agents`, project tier and user
     * tier — mapped onto {@see \SugarCraft\Crush\Agents\AgentPreset} and keyed by
     * filename stem, the same key space {@see agentPresets()} uses.
     *
     * crush_code.md Phase 1 item 3. Until this method existed nothing in `src/`
     * or `bin/` constructed
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}: an agent
     * authored for either tool was read by that class's own unit tests and by
     * nothing else, so dropping a `reviewer.md` under `~/.claude/agents` had zero
     * observable effect on a real run. The class's doc-block said so plainly,
     * which is the only reason this gap was cheap to find.
     *
     * SEPARATE FROM {@see agentPresets()} RATHER THAN MERGED INTO IT, and the
     * split is the precedence decision rather than tidiness. That method's
     * contract is "the NATIVE presets", and its result is applied over the six
     * built-in definitions in {@see agentRoster()} — so folding the imports into
     * its return value would have ranked a cloned repository's
     * `.claude/agents/reviewer.md` ABOVE the built-in `reviewer`, which is the
     * opposite of the native-wins rule stated on `agentRoster()` and of the merge
     * {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} performs for skills.
     * Two return values let the caller insert three layers in one order.
     *
     * DEGRADES, NEVER THROWS. Every per-file failure is already contained inside
     * the registry (one malformed foreign `.md` is `error_log`ged and skipped), so
     * the only escape left is something unforeseen from the walk itself; a
     * `Throwable` there costs the launch its imported agents and nothing more.
     * The native sibling degrades for the same reason and says so at more length.
     *
     * REFUSALS ARE DRAINED INTO THE LAUNCH COLLECTOR, on both paths. A repository
     * that committed `.claude/agents` or `.opencode/agents` as a link out of the
     * checkout gets the tree dropped, and until this drain existed the seam
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::refusedDirectories()}
     * exposes had no reader at all — a refused directory was indistinguishable
     * from an empty one, which is the silence crush_code.md Phase 1 item 3 and
     * Phase 2 item 6 both exist to end. See {@see $projectTierRefusals} for the
     * other four seams and {@see reportProjectTierRefusals()} for the notice.
     *
     * WHAT IS NOT DRAINED, stated rather than left as an absence:
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::warnings()} —
     * opencode's per-command `permission:` rules collapsing to one allow/ask/deny.
     * That is a lossy MAPPING, not a refused directory, and the collector's notice
     * is worded `ignoring <path> — <reason>`, which would misdescribe it. Those
     * notices reach `error_log` from inside the registry and nothing else; giving
     * them a surface of their own is a separate change.
     *
     * THE USER TIER'S OWN REFUSAL HAS NO ENTRY HERE, and cannot: when
     * {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} returns null the
     * registry omits that tier and records nothing
     * ({@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::userDir()}).
     * On every path that reaches this method the condition is already unreachable
     * — {@see trustedConfigDirPath()} throws on exactly `owned() === null`, and
     * {@see agentRoster()} resolves it before calling here — so the user does not
     * get a quieter roster, they get a refused launch naming the home and the
     * reason. That is a louder surface than a collector line, not a missing one.
     *
     * @return array<string, \SugarCraft\Crush\Agents\AgentPreset> keyed by preset filename stem
     */
    public static function foreignAgentPresets(string $root): array
    {
        $registry = new ForeignAgentPresetRegistry();

        try {
            $presets = $registry->discover($root);
        } catch (\Throwable $e) {
            // BOTH CHANNELS, and the stderr bytes are unchanged — see
            // {@see warnPermissionConfigInTranscript()}. A launch whose
            // `.claude/agents` presets did not load is a launch whose `/agents`
            // roster is short, and the operator finds that out by typing a
            // name that is not there. Raised from agentRoster(), reached
            // through agentManager(), which is one of chat()'s named
            // constructor arguments — so it is recorded before chat() reads the
            // list on its way out.
            self::warnPermissionConfigInTranscript(
                "foreign agent presets unavailable ({$e->getMessage()}); continuing without them",
            );
            $presets = [];
        }

        // Collected whether the walk finished or not: refusedDirectories() is
        // filled as each tier is rejected, before any file is parsed, so a walk
        // that recorded a refusal and then tripped over something else must not
        // lose the refusal to the degradation. Same argument, same shape, as the
        // native sibling's throwing path.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$registry->refusedDirectories()];

        return $presets;
    }

    /**
     * The native agent presets on disk, project directory first so a checked-in
     * preset overrides a same-named one in the user's home.
     *
     * NATIVE ONLY, deliberately: the imports from other tools' conventions are
     * {@see foreignAgentPresets()}, a separate call whose result
     * {@see agentRoster()} inserts BENEATH both these presets and the built-in
     * definitions. See that method for why the two are not one.
     *
     * Resolved off {@see configDirPath()}, never {@see configDir()}: listing
     * agents is a read, and a read must not be what creates ~/.sugar-crush.
     *
     * A malformed preset degrades to "no presets this launch" with a warning
     * on stderr rather than an exception. {@see AgentPresetRegistry::list()}
     * throws on the first file with missing or invalid frontmatter, and these
     * files are hand-authored — letting that escape would make one bad `.md`
     * in a repo enough to stop `bin/sugarcrush` from starting at all, which is
     * a far worse failure than losing the roster's optional half. stderr is
     * where this class already reports provider fallbacks and pruned sessions.
     *
     * The project half is ANCHORED to $root, and that is a containment boundary
     * rather than a tidiness rule: `<root>/.sugar-crush/agents` is a path the
     * repository chose, so a committed `.sugar-crush/agents -> <outside>` used to
     * relocate the per-entry check instead of tripping it — measured on this host
     * against the pre-fix build, a fixture whose only content was that one line
     * had this method return a preset carrying an outside file's description, its
     * body as `initialPrompt`, and `permissionMode: bypass-permissions`. See
     * {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}.
     *
     * THE USER HALF IS ANCHORED TOO, to `$HOME`, and the sentence it replaces —
     * "the user half is NOT anchored: `~/.sugar-crush/agents` is the user's own
     * directory" — was a claim about who chose the LOCATION, made by code that
     * only checked who owned the home. See {@see agentPresetTiers()} for the
     * measurement that refuted it and for what the anchor costs.
     *
     * @return array<string, \SugarCraft\Crush\Agents\AgentPreset> keyed by preset name
     */
    public static function agentPresets(string $root): array
    {
        // trustedConfigDirPath(), NOT configDirPath(), and resolved OUTSIDE
        // the catch below. A preset carries `permissionMode:` and `tools:`
        // ({@see \SugarCraft\Crush\Agents\AgentPreset}), which is policy by the
        // same definition {@see hookFiles()} uses — so the user half of this
        // pair may no more be read out of a world-writable `/tmp` stand-in than
        // `config.json` may. Outside the catch because that arm degrades to the
        // built-in agents, and "this process cannot tell whose home this is" is
        // not a degradable condition: it is the launch refusal
        // {@see trustedConfigDirPath()} exists to raise.
        [$searchPaths, $anchors] = self::agentPresetTiers($root);

        $registry = new AgentPresetRegistry(
            $searchPaths,
            // Keyed by the paths AS SPELLED in agentPresetTiers(), which is why
            // they are returned rather than rebuilt here. A key that names no
            // search path is refused at construction rather than silently
            // anchoring nothing — see {@see AgentPresetRegistry::__construct()}.
            anchors: $anchors,
        );

        try {
            $presets = $registry->list();
        } catch (\Throwable $e) {
            // The native sibling of the foreign-preset degradation above, and
            // routed for the same reason: the roster the user configured is not
            // the roster they got.
            self::warnPermissionConfigInTranscript(
                "agent presets unavailable ({$e->getMessage()}); continuing with the built-in agents",
            );

            // Collected on the throwing path TOO: list() records its refusals
            // before it parses anything, so a launch that both refused a
            // directory and then tripped over a malformed preset elsewhere must
            // not lose the refusal to the degradation.
            self::$projectTierRefusals = [...self::$projectTierRefusals, ...$registry->refusedDirectories()];

            return [];
        }

        // The agents third of the project-tier refusal, joining the workflow
        // registry's and the skill loader's in one collector — see
        // {@see projectTierRefusals()}.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$registry->refusedDirectories()];

        return $presets;
    }

    /**
     * The agent-preset search paths and their trust anchors, for $root.
     *
     * THE `.git` DISCRIMINATOR THIS REPLACES RESTED ON A FALSE PREMISE, stated
     * in the code it guarded: *"`$HOME/.git` is the discriminator because the
     * escape needs a COMMITTED symlink and nothing can be committed without a
     * repository."* A symlink does not need to be committed to arrive. `tar`,
     * `zip`, `rsync -a`, `degit` and "download the release tarball" all carry
     * one and carry no `.git`, and the discriminator was defeated three
     * further ways: a bare-repo dotfiles layout leaves no `.git` at `$HOME`
     * (this was the stated bound), and a DANGLING `.git` symlink answers
     * `file_exists()` false while being every bit a checkout.
     *
     * THE MEASUREMENT THAT SETTLED IT, on this host, `$HOME` mode 0700 and
     * owned, its only content `.sugar-crush/agents -> <outside>` delivered by
     * `tar xzf` — four launch shapes, the discriminator working exactly as
     * designed:
     *
     *     no .git,  agentPresets($HOME)    presets=["pwned"] mode=bypass-permissions refusals=[]
     *     no .git,  agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
     *     .git dir, agentPresets($HOME)    presets=[]        refusals=["…outside the checkout…"]
     *     .git dir, agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
     *
     * The third row is the only one the discriminator ever changed. Row FOUR is
     * the point: with `.git` present — the check firing correctly — the escape
     * is fully live the moment the user launches from any directory that is not
     * their home, which is every ordinary launch. The discriminator defended one
     * launch shape out of four and the rationale for it was false, so it is
     * gone rather than patched.
     *
     * NO RELIABLE DISCRIMINATOR EXISTS for the question it was asking. "Did a
     * repository choose this content" is not answerable from the filesystem: a
     * tarball-delivered dotfiles tree and a hand-authored one are byte-identical.
     * So the question is replaced with one that IS answerable and that makes the
     * old claim true rather than assumed — *is this directory inside the home
     * this process established as the user's?* The user tier is anchored to
     * `$HOME`. Every row above becomes a refusal, in every launch shape.
     *
     * WHAT IT COSTS, stated rather than implied away, because it is a real
     * working layout that stops working: `~/.sugar-crush/agents` symlinked to a
     * path OUTSIDE `$HOME` — a network share, `/opt/team-agents` — is now
     * refused. The layout the old sentence actually named as its justification,
     * a link to `~/.claude/agents`, is INSIDE `$HOME` and is unaffected; so is
     * every roster that is a real directory. {@see \SugarCraft\Crush\Tests\Agents\AgentPresetHomeRootTest}
     * pins both halves.
     *
     * WHY THE ANCHOR AND NOT THE OWNERSHIP CHECK. {@see HomeDirectory::owned()}
     * already establishes that `$HOME` is this user's, and in the measurement
     * above it was — the user extracted a hostile tarball into their own home.
     * Ownership answers "whose directory is this"; it cannot answer "who chose
     * where this link points", and only containment does.
     *
     * `$sameDirectory` survives as pure DE-DUPLICATION and no longer decides
     * anything: when `$root` IS `$HOME` the two expressions are one directory
     * and one anchor, so scanning it twice would record the same refusal under
     * the same key. Resolved identity as well as spelled, so `$root` reached
     * through a symlink to `$HOME` is the same launch.
     *
     * @return array{0: list<string>, 1: array<string, string>} search paths in
     *         precedence order, and their anchors keyed by the SAME spellings
     */
    private static function agentPresetTiers(string $root): array
    {
        $projectAgents = rtrim($root, '/') . '/.sugar-crush/agents';

        // Both derived from the ONE trusted resolution, so the agents directory
        // and the home it is anchored to can never be two different homes.
        $userConfigDir = self::trustedConfigDirPath();
        $userAgents = $userConfigDir . '/agents';
        $userHome = \dirname($userConfigDir);

        $sameDirectory = $projectAgents === $userAgents
            || (realpath($projectAgents) !== false && realpath($projectAgents) === realpath($userAgents));

        if ($sameDirectory) {
            return [[$userAgents], [$userAgents => $userHome]];
        }

        return [
            [$projectAgents, $userAgents],
            [$projectAgents => $root, $userAgents => $userHome],
        ];
    }

    /**
     * Build the pane shell the CLI binary runs interactively: an {@see App}
     * hosting the very {@see chat()} model this class already builds
     * (crush_feat.md §5 E7, the MERGE branch).
     *
     * §5 E7 gave two ways out of sugar-crush's two-parallel-UI-systems drift
     * risk — delete the `App`/`Pane` layer, or move the app onto it — and
     * conditioned the delete on there being no plan to switch. There is one,
     * and this method is the last link in it: until now nothing in `src/` or
     * `bin/` ever constructed an `App` with a hosted `Chat`, so the shell
     * (menu bar, pane focus, {@see \SugarCraft\Crush\Tui\KeyboardHandler}'s
     * agent-view keys, session tabs) was unreachable from a real run in
     * exactly the "built but never wired" way §5D describes.
     *
     * The `Chat` is taken WHOLE from {@see chat()} rather than rebuilt: it
     * already carries the seeded session row, the title backend, the memory
     * store and the guard chain, and seeding it twice would create a second
     * session row per launch. The App is the frame around it and copies no
     * state out of it — {@see App::withSessionId()} is the one exception, and
     * it is read back off the hosted chat rather than re-derived, so the two
     * can never disagree.
     *
     * The shell's own panes are populated here for the same reason the whole
     * step exists: an empty Tools/Skills sidebar in a freshly-wired shell is
     * the failure mode being fixed, not a neutral default. These are the
     * shell's DISPLAY copies — the engine's authoritative tool list and skill
     * registry live inside the hosted chat's backend, which builds its own;
     * handing both the same instances would mean reshaping {@see backend()}'s
     * internals, which this step does not touch.
     */
    public static function app(?string $root = null): App
    {
        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings($root);

        $chat = self::chat($root);
        [$provider, $model] = self::provider();

        // ONE registry for the shell's Skills pane and the Skill tool in its
        // displayed tool list: scanning twice would show the user a roster
        // that the tool could disagree with.
        $skills = self::skillRegistry($root);

        // RAISED AFTER chat() HAS ALREADY READ THE LIST, which is why the
        // length is taken here. {@see chat()} seeds the transcript on its way
        // out (see its last statement), so anything the second scan below
        // records into {@see $launchNotices} would land in a static that
        // nothing reads again — a migration onto the transcript seam that looks
        // like a fix and is a no-op. This is the interactive path, so it is the
        // one where that matters most.
        $noticesBeforeSecondScan = \count(self::launchNotices());

        // This is a SECOND scan — chat() above already reported what its own
        // scan skipped — and it can find skills that one did not, because it
        // runs after it and reads the same trees. reportSkillSkips() only
        // prints what has not been printed yet, so the common case is silence
        // and the case this adds is a skill file that would otherwise have been
        // collected into skillSkips() and never mentioned to anybody.
        self::reportSkillSkips();
        // Same argument for the second scan's directory refusals.
        self::reportProjectTierRefusals();

        // HOISTED out of the `withTools()` argument below rather than inlined:
        // tools() reaches filterToolSet(), which is itself a transcript source,
        // so its notices have to be in hand before the delta is taken.
        $tools = self::tools($root, null, $skills);

        // THE DELTA, never the whole list. {@see Chat::withLaunchNotices()}
        // APPENDS, and $chat already carries everything chat() had recorded —
        // passing the full list again would put every launch warning in the
        // transcript twice. {@see $launchNotices} is append-only within a
        // launch, so a slice from the old length is exactly what these three
        // calls added, in the order they added it.
        //
        // ONE KNOWN UNDERSTATEMENT, named rather than engineered around: if the
        // launch had ALREADY overflowed {@see LAUNCH_NOTICE_LIMIT} before this
        // point, $chat carries an "and N more" row whose N does not grow to
        // cover what the second scan then dropped. That needs a launch with 24+
        // distinct warnings before the shell is built, and the row still says
        // the full list is on stderr, where it is.
        $chat = $chat->withLaunchNotices(array_slice(self::launchNotices(), $noticesBeforeSecondScan));

        return App::new($provider, $model)
            ->withChat($chat)
            ->withSessionId($chat->currentSessionId())
            ->withTools($tools)
            ->withAvailableSkills($skills)
            // Same string the tools/skill scan above used, so the shell's
            // Settings pane, the environment block the model reads and every
            // hook context all name one directory.
            ->withRoot($root);
    }

    /**
     * The run's selected provider and model as real objects, for the two
     * consumers that need a {@see ProviderInterface} rather than the
     * {@see Backend} the hosted chat runs on: {@see App} (which uses it as a
     * label source for the status bar's provider name and never calls it) and
     * {@see agentManager()} (which genuinely drives it, for sub-agent
     * completions).
     *
     * Built from the same selection {@see selectedProviderLabel()} reports, and
     * falls back to the offline {@see EchoProvider} whenever this run has no
     * provider or the provider cannot be constructed: {@see backend()} has
     * already warned on stderr AND seeded the transcript in that case (see
     * {@see warnPermissionConfigInTranscript()}), and refusing to launch the TUI
     * over an unusable label would be a worse outcome than showing "echo".
     *
     * @return array{0: ProviderInterface, 1: string}
     */
    public static function provider(): array
    {
        [$name, $model] = self::selectedProviderLabel();
        $providerName = self::selectedProviderName();

        if ($providerName !== null) {
            try {
                $factory = new ProviderFactory();
                $config = $factory->defaultConfig($providerName);
                $config['model'] = $model;

                return [$factory->create($config), $model];
            } catch (\Throwable) {
                // fall through to Echo, same degradation backend() applies
            }
        }

        return [new EchoProvider(), $name === 'command' ? $model : 'echo'];
    }

    /**
     * Backend selection, in priority order:
     *
     *   1. $SUGARCRUSH_PROVIDER (+ provider env, e.g. $OPENAI_API_KEY) — run the
     *      full agent engine: that provider driven by the Runtime with the
     *      built-in coding tools (Bash/Read/Edit/Glob/Grep/WebFetch) gated by the
     *      safety hooks. $SUGARCRUSH_MODEL overrides the model.
     *   2. $SUGARCRUSH_BACKEND_CMD — dependency-free shell-out: a command that
     *      reads JSON history on stdin and writes the reply to stdout, which
     *      is returned as-is but for one `trim()` at the ends
     *      ({@see CommandBackend}).
     *   3. $SUGARCRUSH_BACKEND_CMD_STREAM — the same shell-out under the
     *      OTHER stdout contract, a TOKEN STREAM rather than prose: one token
     *      per TERMINATED line, a TERMINATED BLANK line meaning a literal
     *      newline in the answer, an unterminated empty remainder meaning
     *      nothing, and the tokens joined with the EMPTY STRING — the newline
     *      between two tokens is framing, not text
     *      ({@see StreamingCommandBackend}). `$onToken` is called once per
     *      token as the command produces it, and the TUI now repaints as they
     *      arrive: the display half of that claim used to be withdrawn here
     *      because the read loop ran to completion inside one
     *      `Loop::futureTick` and blocked the render loop for the whole
     *      round-trip. It is driven from a periodic timer now (see that class's
     *      `completeAsync()` for the before/after measurement), so tiers 2 and
     *      3 are as non-blocking as tier 1.
     *      Ranked BELOW tier 2 rather than above it so tier 2 stays
     *      byte-identical for everyone who already uses it, including a run
     *      with both variables exported — the two protocols are genuinely
     *      different and neither serves the other (a PROSE wrapper run through
     *      tier 3 loses every newline it emitted, and each blank line it
     *      emitted comes back as ONE newline rather than two, so a paragraph
     *      break, a list and a code fence do not survive), so a run that sets
     *      both is ambiguous and the older, documented meaning wins. Ranked
     *      above tier 4 for the same reason tier 2 is: an exported variable is
     *      a decision made about THIS run, a persisted provider is one made
     *      about some earlier one.
     *   4. A provider name persisted by a previous Ctrl+P "Switch model"
     *      (see writeUserConfig()) — makes that choice survive a restart
     *      without needing $SUGARCRUSH_PROVIDER exported every time.
     *   5. (default) the offline EchoProvider, still run through the engine so the
     *      binary launches with zero network and zero config.
     *
     * For BOTH shell-out variables, absence means unset, empty OR
     * whitespace-only — see {@see backendCommandEnv()}, which is also what
     * {@see backendCommandTierIsSelected()} asks, so the tier this method
     * selects and the tier the label helpers report can never disagree.
     *
     * Also the process's one startup sweep of abandoned forked-tool payloads
     * ({@see ToolIpcFiles::sweepOnce()}) — every real run reaches this method
     * or {@see backendFor()} exactly once, and the files being swept are by
     * definition ones whose owning process was killed before it could clean up
     * after itself.
     *
     * @param SkillRegistry|null $skills The registry to thread into the
     *        engine and its tools; defaults to a fresh scan of $root. Pass the
     *        caller's so a launch scans once and every consumer sees the same
     *        enabled/disabled set (see {@see chat()}).
     * @param PermissionGate|null $gate The launch's safety gate, threaded into
     *        {@see EngineBackend::withPermissionGate()}. Pass the caller's so
     *        the engine and Chat's own tool path share ONE circuit breaker;
     *        defaults to a gate built fresh from the same config.
     * @param bool $consolePermissionPrompt Attach
     *        {@see HeadlessPermissionPrompt} as the engine's approver, so a
     *        {@see \SugarCraft\Crush\Hooks\HookResult::ask()} on the engine
     *        path is PUT TO SOMEONE instead of failing closed.
     *
     *        Opt-in, and defaulted OFF, because this method has two kinds of
     *        caller and only one of them owns a console. {@see chat()} and
     *        {@see \SugarCraft\Crush\Chat}'s provider switch reach here from
     *        inside a TUI that holds the terminal in raw mode and alt-screen:
     *        a closure that blocks on `fgets(STDIN)` from in there would eat
     *        the keystrokes the render loop is reading and print its question
     *        underneath the frame. {@see NonInteractive} — the `-p` one-shot —
     *        owns stdin outright and passes true. See the class docblock on
     *        {@see HeadlessPermissionPrompt} for why the TUI needs a different
     *        mechanism rather than this one wired more widely.
     */
    public static function backend(?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null, bool $consolePermissionPrompt = false): Backend
    {
        // Same first-line refusal {@see chat()} makes, for the same ordering
        // reason: this method is the `-p` path's entry point, where nothing
        // else resolves the config directory until hooks() does — five calls
        // and one full skill scan later. See {@see trustedConfigDirPath()}.
        self::trustedConfigDirPath();

        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings($root);

        $providerType = getenv('SUGARCRUSH_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                return self::backendFor($providerType, $root, $skills, $gate, $consolePermissionPrompt);
            } catch (PermissionConfigException $e) {
                // Not a provider problem, and not survivable by degrading:
                // the echo fallback below builds the very same gate and would
                // throw again, after blaming the provider for it on stderr.
                //
                // Written out even though PHP already lets an unmatched type
                // through the `\Throwable` arm below — it is NOT unmatched, it
                // is a SUBTYPE, and without this arm the degrade-to-echo
                // handler swallows it. Static analysis flags the rethrow as
                // redundant; it is load-bearing.
                throw $e;
            } catch (\Throwable $e) {
                // THE MOST USER-VISIBLE DEGRADATION THIS CLASS REPORTS, and
                // until now it was reported only to the channel an interactive
                // launch paints over 0.47s later. "Your model silently became
                // EchoProvider" is not something an operator should have to
                // infer from the replies. Both channels — see
                // {@see warnPermissionConfigInTranscript()}. backend() is
                // chat()'s FIRST named constructor argument, so this lands well
                // before chat() reads the list.
                self::warnPermissionConfigInTranscript(
                    "provider '{$providerType}' unavailable ({$e->getMessage()}); falling back to echo",
                );
            }
        }

        $cmd = self::backendCommandEnv('SUGARCRUSH_BACKEND_CMD');
        if ($cmd !== null) {
            return new CommandBackend($cmd);
        }

        $streamCmd = self::backendCommandEnv('SUGARCRUSH_BACKEND_CMD_STREAM');
        if ($streamCmd !== null) {
            // No idle deadline argument: the default is "none", which is the
            // parity CommandBackend above has always had. See
            // {@see StreamingCommandBackend::__construct()} for why the old
            // 120-second total cap is gone rather than merely raised. Pinned by
            // {@see \SugarCraft\Crush\Tests\Cli\BootstrapShellOutTierTest::testTheStreamingTierIsConstructedWithNoIdleDeadline()},
            // which reflects on the instance this line returns — a comment
            // cannot stop someone passing a 1 here, and a test on the class's
            // DEFAULT does not see the call site at all.
            return new StreamingCommandBackend($streamCmd);
        }

        $persisted = self::readUserConfig()['provider'] ?? null;
        if (is_string($persisted) && $persisted !== '') {
            try {
                return self::backendFor($persisted, $root, $skills, $gate, $consolePermissionPrompt);
            } catch (PermissionConfigException $e) {
                // See the env-var branch above: this arm exists to keep the
                // `\Throwable` degrade-to-echo arm below from catching it.
                throw $e;
            } catch (\Throwable $e) {
                // The env-var tier's twin, and reachable in the SAME launch as
                // it: an env-var provider that throws falls through to here,
                // and a persisted provider that also throws degrades to echo.
                // Two distinct sentences, so both are recorded.
                self::warnPermissionConfigInTranscript(
                    "persisted provider '{$persisted}' unavailable ({$e->getMessage()}); falling back to echo",
                );
            }
        }

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        $engine = (new EngineBackend(new EchoProvider(), 'echo'))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks(null, $root))
            // `??=`, not `??`, and INSIDE the chain rather than hoisted above
            // it: the approver below needs the gate that was actually
            // installed, and moving the resolution to its own statement would
            // move permissionGate()'s PermissionConfigException ahead of
            // tools()/hooks(), changing which failure a broken launch reports
            // first. Assigning in place keeps the evaluation order byte-for-byte
            // what it was and still leaves $gate non-null afterwards.
            ->withPermissionGate($gate ??= self::permissionGate())
            ->withSkillRegistry($skills)
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(self::memoryStoreOrNull());

        return self::withConsolePermissionPrompt($engine, $gate, $consolePermissionPrompt);
    }

    /**
     * Build a Backend for an explicit, already-known provider name - the
     * same construction backend() does for $SUGARCRUSH_PROVIDER, extracted
     * so a caller (the Ctrl+P palette's Switch Model action) can request a
     * specific provider directly rather than only via an env var read once
     * at process start.
     *
     * Unlike backend()'s env-var path, which catches failures and falls
     * back to Echo with a warning, this throws on an invalid/unreachable
     * $providerName - a caller here asked for this provider explicitly and
     * should see the real error rather than silently getting something else.
     *
     * @param SkillRegistry|null $skills See {@see backend()}.
     * @param PermissionGate|null $gate See {@see backend()}.
     * @param bool $consolePermissionPrompt See {@see backend()} — same opt-in,
     *        same reason it is not on by default here either: this method is
     *        also what Chat's Ctrl+P provider switch calls, mid-TUI.
     *
     * @throws \Throwable
     */
    public static function backendFor(string $providerName, ?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null, bool $consolePermissionPrompt = false): Backend
    {
        // See backend(): whichever of the two a run enters through, the sweep
        // happens once, and the config directory is named before any store or
        // skill scan can resolve it out of a `/tmp` stand-in. sweepOnce()
        // latches, so backend() delegating here does not sweep twice.
        self::trustedConfigDirPath();
        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings($root);
        $factory = new ProviderFactory();
        $provider = $factory->create($factory->defaultConfig($providerName));
        // --model wins over $SUGARCRUSH_MODEL wins over the provider default.
        $model = self::selectedModelName() ?? ($factory->defaultConfig($providerName)['model'] ?? 'gpt-4o');

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        $engine = (new EngineBackend($provider, (string) $model))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks(null, $root))
            // See backend(): `??=` in place, so the approver below can read the
            // mode off the gate that was installed without re-reading the
            // config or reordering the failures.
            ->withPermissionGate($gate ??= self::permissionGate())
            ->withSkillRegistry($skills)
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(self::memoryStoreOrNull());

        return self::withConsolePermissionPrompt($engine, $gate, $consolePermissionPrompt);
    }

    /**
     * Attach the console approver, or hand the engine back untouched.
     *
     * One place rather than two so {@see backend()} and {@see backendFor()}
     * cannot drift on the thing that decides whether an ASK is answerable.
     * The mode comes off the gate the engine actually got — not from a second
     * {@see permissionGate()} call, which would re-read both policy files and
     * re-print any per-rule warnings they produce.
     */
    private static function withConsolePermissionPrompt(EngineBackend $engine, PermissionGate $gate, bool $enabled): EngineBackend
    {
        if (!$enabled) {
            return $engine;
        }

        return $engine->withPermissionApprover(
            (new HeadlessPermissionPrompt($gate->mode()))->approver(),
        );
    }

    /**
     * Every provider name currently selectable, for the Ctrl+P palette's
     * Switch Model action: the built-in provider types {@see
     * ProviderFactory::availableTypes()} knows about, plus every name
     * declared under 'providers' in the project's
     * .sugar-crush/config.dev.json (e.g. 'dev-sglang') - reusing
     * ProviderFactory's existing lookups rather than adding new discovery
     * logic. Silently returns just the built-ins when that config file is
     * absent/unreadable/invalid, matching {@see
     * ProviderFactory::projectProviderConfig()}'s own tolerance for a
     * missing project config.
     *
     * @return array<string, array<string, mixed>> name => defaultConfig()'s config array
     */
    public static function availableProviders(): array
    {
        $factory = new ProviderFactory();
        $providers = [];

        foreach ($factory->availableTypes() as $type) {
            $providers[$type] = $factory->defaultConfig($type);
        }

        // readableDefaultConfigPath(), NOT defaultConfigPath(): this reads the
        // package's own `__DIR__`-relative `.sugar-crush/config.dev.json` AT LAUNCH,
        // and under a composer install that file arrives with the dependency rather
        // than being chosen by this session. See
        // {@see ProviderFactory::readableDefaultConfigPath()} for the two boundaries
        // and for why an ungated `__DIR__` climb is the same read path
        // {@see \SugarCraft\Crush\Agents\WorktreeConfig} was closed for.
        $configPath = ProviderFactory::readableDefaultConfigPath();
        if ($configPath !== null && is_file($configPath)) {
            $contents = file_get_contents($configPath);
            $data = $contents !== false ? json_decode($contents, true) : null;
            if (is_array($data) && is_array($data['providers'] ?? null)) {
                foreach ($data['providers'] as $name => $config) {
                    if (is_string($name) && is_array($config)) {
                        $providers[$name] = $config;
                    }
                }
            }
        }

        return $providers;
    }

    /**
     * ~/.sugar-crush/config.json — per-user persisted UI choices
     * (`provider`/`theme`, written by the Ctrl+P palette's Switch Model/
     * Switch Theme actions) plus hand-authored settings the CLI never writes
     * back, currently the `instructions` glob array {@see
     * forcedInstructions()} reads. Distinct from {@see
     * ProviderFactory::defaultConfigPath()}'s project-level
     * .sugar-crush/config.dev.json dev/test fixture — that one is checked
     * into the repo and shared; this one is a real per-user runtime state
     * file, same directory convention as {@see sessionStore()}/{@see
     * memoryStore()}.
     *
     * Resolved off {@see configDirPath()}, NOT {@see configDir()}: naming the
     * config file must never be what creates ~/.sugar-crush. Every read path
     * runs through here — including {@see \SugarCraft\Crush\Backend\EngineBackend}'s
     * per-turn dispatch settings — and a process that only ever reads its
     * config should leave no directory behind on a box where the user never
     * persisted anything. {@see writeUserConfig()} creates the directory at
     * the point it actually has something to put in it.
     */
    public static function userConfigPath(): string
    {
        // `--config <path>` wins over discovery — see {@see useConfigPath()}.
        return self::$configPathOverride ?? self::configDirPath() . '/config.json';
    }

    /**
     * Point every reader of the per-user `config.json` at $path instead of the
     * discovered `~/.sugar-crush/config.json` — what `--config <path>` does.
     *
     * PROCESS-WIDE STATIC, not a parameter, because this class is a static
     * facade whose config readers sit five and six calls below the two entry
     * points `bin/sugarcrush` calls ({@see app()} and, through
     * {@see NonInteractive::run()}, {@see backend()}): threading a path
     * through every one of them would touch far more of this file than the
     * flag is worth, and would still leave {@see readUserConfig()}'s other
     * callers — `EngineBackend`'s per-turn dispatch settings among them —
     * reading the discovered file while the rest of the process read the
     * chosen one. `bin/sugarcrush` sets it once, after
     * {@see ArgvParser::configError()} has established the file is readable
     * and before either dispatch. Tests must reset it with null.
     *
     * WHAT IT DOES NOT MOVE, stated exactly: this names one FILE. The agents/,
     * skills/, workflows/ and hooks trees, the session store and the memory
     * store all still resolve off `~/.sugar-crush`. Nor does it relax the home
     * -ownership gate — {@see permissionConfig()} still calls
     * {@see trustedConfigDirPath()} for its throw before honouring the
     * override, so a process that cannot establish whose home it is refuses to
     * start whether or not a config file was named on the command line.
     */
    public static function useConfigPath(?string $path): void
    {
        self::$configPathOverride = $path;
    }

    /**
     * Register `--model <name>` for this process.
     *
     * A PROCESS-WIDE STATIC for the same reason {@see useConfigPath()} is one,
     * and deliberately following that precedent rather than inventing a third
     * mechanism: `bin/sugarcrush` has THREE dispatch paths — `Subcommands`,
     * `NonInteractive::run()` and `Program`/{@see app()} — and only the last
     * goes through {@see chat()}. A flag threaded as a parameter into `chat()`
     * would silently do nothing for `sugarcrush -p "..."`, which enters at
     * {@see backend()}/{@see backendFor()} instead. One static registered
     * before either dispatch is read identically by all three.
     *
     * WHAT `--model` MEANS, because the surrounding code disagrees with itself
     * about the word: it names a MODEL, the same domain as `$SUGARCRUSH_MODEL`
     * ("Model name (overrides provider default)" on the help screen), and it is
     * resolved by {@see selectedModelName()}. It is NOT a provider. The Ctrl+P
     * palette's entry labelled "Switch model" calls
     * {@see backendFor()}, whose first parameter is a PROVIDER name, and that
     * label is the reason this needed settling in writing: the two are
     * independent axes and this flag is the model one.
     *
     * No `model` key is added to {@see \SugarCraft\Crush\Config\LayeredSettings::LAYERED_KEYS}
     * to back it. That was considered and rejected: the docblock on that const
     * records that a top-level `model` key would be "surface with no reader",
     * and a launch flag does not need one — it is read here, at the same two
     * sites `$SUGARCRUSH_MODEL` already is.
     *
     * ## TEST ISOLATION — read this before calling either setter from a test
     *
     * This static and {@see usePermissionMode()}'s LIVE FOR THE WHOLE PROCESS
     * and are NOT reset between test cases or between test classes. A class
     * that sets one and does not clear it decides the launch of every test that
     * runs after it, in any file, for the rest of the suite.
     *
     * There is deliberately no automatic reset. That matches
     * {@see useConfigPath()}, which has behaved this way since it was written
     * and which `BootstrapConfigPathOverrideTest` and
     * `BootstrapLayeredSettingsTest` each clear in their own `tearDown()`.
     * Generalising the reset was considered and declined: it would mean a
     * shared trait or a suite-wide hook that, to be coherent, would have to
     * take over `useConfigPath()` too — a new mechanism for three statics whose
     * existing convention already works and is followed everywhere it applies.
     *
     * **So the convention is the contract: clear what you set.**
     * `Tests\Cli\LaunchFlagsTest` clears both in `setUp()` AND `tearDown()`,
     * which is the pattern to copy — the `setUp()` half also makes that class
     * immune to a leak from somewhere else.
     */
    public static function useModel(?string $model): void
    {
        self::$modelOverride = ($model === null || $model === '') ? null : $model;
    }

    /**
     * Register `--permission-mode <mode>` for this process. See
     * {@see useModel()} for why this is a static rather than a parameter.
     *
     * STORED RAW AND VALIDATED LATER, on purpose. {@see permissionModeFrom()}
     * THROWS {@see PermissionConfigException} for a value that is not a mode,
     * and `bin/sugarcrush` catches that exception around its dispatch block —
     * but registration happens BEFORE that `try`, so validating here would let
     * the exception escape as an uncaught fatal instead of the exit-2 usage
     * error (with the JSON error document under `--output-format json`) that
     * every other bad permission value produces. Deferring the check to
     * {@see permissionGate()} puts it inside the `try` and reuses the one
     * error message shape, which then names `--permission-mode` as its source
     * exactly as it names `$SUGARCRUSH_PERMISSION_MODE` or the config file.
     *
     * DOCUMENTED CONSEQUENCE: a subcommand that never builds a gate (`completion`,
     * `--help`, `--version`) does not validate the value, so
     * `sugarcrush --permission-mode nonsense completion bash` succeeds. The flag
     * is inert on those paths anyway; failing them would cost a second, eager
     * validation site that could drift from this one.
     */
    public static function usePermissionMode(?string $mode): void
    {
        self::$permissionModeOverride = ($mode === null || $mode === '') ? null : $mode;
    }

    /**
     * The model name this run should use, or null to let the caller fall back
     * to the provider's own default.
     *
     * ONE resolver rather than two `??` chains, because there are two readers —
     * {@see backendFor()}, which builds the backend that actually runs, and
     * {@see selectedProviderLabel()}, which produces the status-bar caption.
     * If only the first honoured `--model`, the status bar would name a
     * different model than the one answering, which is precisely the "a value
     * true of one path displayed as a property of another" defect.
     */
    private static function selectedModelName(): ?string
    {
        if (self::$modelOverride !== null) {
            return self::$modelOverride;
        }

        $env = getenv('SUGARCRUSH_MODEL');

        return ($env === false || $env === '') ? null : $env;
    }

    /**
     * Reads the persisted user config, tolerantly: a missing, unreadable,
     * or invalid-JSON file returns [] rather than throwing - there is
     * nothing yet to persist on a fresh install, and a corrupt file
     * shouldn't block the CLI from starting.
     *
     * The read is `@`-silenced because the `false` branch below already IS the
     * handling for an unreadable file: without it a config the user has
     * chmod'ed away leaks a `Permission denied` warning into the TUI's own
     * output (and fails any `failOnWarning` suite) on a path that then goes on
     * to degrade gracefully anyway.
     *
     * LAYERED SINCE {@see LayeredSettings} — the return value is this file's
     * contents with that class's whitelisted keys backfilled from
     * `~/.sugar-crush/settings.json` and, for a TRUSTED project root only, from
     * `<root>/.sugar-crush/settings.json` and `settings.local.json`. For every
     * key `LayeredSettings` does not name, the answer is unchanged: this
     * file, alone, exactly as before. See that class for the precedence order
     * and for why the user's files outrank the project's.
     *
     * STILL TOLERANT AND STILL NON-THROWING, which the layering must not
     * change: `EngineBackend` calls this once per turn, and the trust lookup it
     * now performs goes through {@see permissionConfig()}, which throws on an
     * unusable config and on an unknowable home. {@see projectSettingsTrusted()}
     * swallows that into `false`, so every uncertainty costs the project layer
     * and nothing else.
     *
     * THE USER LAYER DOES NOT FOLLOW `--config`, which is the one asymmetry
     * worth naming here because the first cut of this method got it wrong.
     * `settings.json` is resolved from {@see userSettingsDirOrNull()} — the
     * home-owned `~/.sugar-crush` — and NOT from `dirname(userConfigPath())`.
     * MEASURED against the first cut: `--config /tmp/anything/config.json`
     * made `/tmp/anything/settings.json` a USER-TIER file, so a `provider` and
     * an `instructions` list — the two keys this whole design refuses to a
     * project at any trust level — came out of a directory with no containment
     * check, no home-ownership check and no trust gate. A repository shipping
     * `crush.json` alongside a `settings.json` and a README saying
     * `sugarcrush --config ./crush.json` would have had the user tier.
     * {@see useConfigPath()} already documents that the flag names ONE FILE and
     * moves nothing else in `~/.sugar-crush`; the settings file is one of the
     * things it does not move.
     *
     * @return array<string, mixed>
     */
    public static function readUserConfig(): array
    {
        return self::mergedConfig(true);
    }

    /**
     * {@see readUserConfig()}, with the project tier optionally left out.
     *
     * ONE COPY OF THE LAYERING, and that is the whole reason this exists rather
     * than a second `LayeredSettings::merge(...)` call beside its caller.
     * {@see reportProjectTierToolRemovals()} needs the same stack MINUS layers
     * 1+2 so it can diff the two, and a hand-rolled "same thing but without the
     * project layer" would be free to disagree with this one about which layer
     * wins — the precedence bug this class has already been bitten by twice.
     *
     * @return array<string, mixed>
     */
    private static function mergedConfig(bool $withProjectTier): array
    {
        $root = self::$projectRootForSettings;
        $userSettingsDir = self::userSettingsDirOrNull();

        return LayeredSettings::merge(
            self::rawUserConfig(),
            $userSettingsDir === null ? [] : LayeredSettings::userLayer($userSettingsDir),
            $root === null || !$withProjectTier
                ? []
                : LayeredSettings::projectLayer($root, self::projectSettingsTrusted($root)),
        );
    }

    /**
     * The directory {@see LayeredSettings::USER_FILE} is read from, or null
     * when this process cannot establish whose home it is.
     *
     * {@see trustedConfigDirPath()}, NOT {@see configDirPath()} and NOT
     * `dirname(userConfigPath())`, and the two exclusions have different
     * reasons:
     *
     *  - not `dirname(userConfigPath())`, because that follows `--config` — see
     *    {@see readUserConfig()} for the measurement;
     *  - not `configDirPath()`, because that resolves through
     *    {@see homePath()}'s world-writable STAND-IN when no home is knowable,
     *    and a `settings.json` may carry `provider` and `instructions`. Those
     *    are the same policy tier `hooks.yaml` and the agent presets are in, so
     *    they get the same gate those already have.
     *
     * NULL RATHER THAN A THROW, unlike every other caller of that method: this
     * one is reached from {@see readUserConfig()}, whose contract is that no
     * uncertainty costs more than the setting it was about. On a real launch
     * the question is already settled — {@see chat()}, {@see backend()} and
     * {@see backendFor()} each resolve {@see trustedConfigDirPath()} before
     * they build anything, so a process that cannot establish its home has
     * already refused to start. What this guard covers is the direct caller:
     * `EngineBackend`'s per-turn read, and a subcommand that reads the config
     * without a launch behind it.
     */
    private static function userSettingsDirOrNull(): ?string
    {
        try {
            return self::trustedConfigDirPath();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The CLI-written user file ALONE — {@see readUserConfig()} minus the
     * layering.
     *
     * Separate from the layered read because {@see writeUserConfig()} merges
     * onto what it reads and then writes the result back. Reading the LAYERED
     * view there would copy every effective value into the user's own file the
     * first time anything persisted a theme: a `titleModel` a project chose
     * would become a permanent user-tier setting, outliving the checkout that
     * suggested it and surviving into every other repository. That is a
     * one-way promotion from the lowest-trust layer to the highest, performed
     * by a UI action that says "Switch theme".
     *
     * @return array<string, mixed>
     */
    private static function rawUserConfig(): array
    {
        $path = self::userConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Name the project whose `.sugar-crush/settings.*` files this process may
     * consider — called by the four entry points that resolve a `$root`
     * ({@see chat()}, {@see app()}, {@see backend()}, {@see backendFor()}).
     *
     * NO PROJECT LAYER UNTIL AN ENTRY POINT HAS NAMED ONE, and that is the
     * whole of the rule. `readUserConfig()` has no `$root` parameter and cannot
     * grow one — its callers include `EngineBackend`'s per-turn read, five and
     * six frames below anything that knows a root — so the root has to be
     * remembered, the same way {@see useConfigPath()} remembers a config path
     * and for the same reason. Deriving it from `getcwd()` inside the read
     * instead would have been wrong whenever `--root` named something else: the
     * settings would come from the directory the user was standing in and the
     * files from the repository they pointed at.
     *
     * Falling back to `getcwd()` was the other candidate and is deliberately
     * NOT taken: a subcommand like `sugarcrush models` reads the user config
     * without ever naming a project, and having it silently pick up the CWD's
     * settings would make the project tier apply on paths that never opened a
     * project. Unnamed means user tier only, which is the pre-layering
     * behaviour and the safe direction.
     *
     * Null resets it, which tests must do — and a BLANK string is normalised to
     * null rather than stored, which buys no behaviour today and is kept anyway
     * for one measured reason: on PHP 8.3 `realpath('')` answers with the
     * PROCESS CWD, not `false`. Stored blank, {@see projectSettingsTrusted()}
     * would put the trust question to whatever directory the shell was standing
     * in — a directory no entry point named. The layer still comes back empty
     * because {@see LayeredSettings::projectLayer()} refuses a blank root, so
     * this is the outer of three guards; it is pinned directly, by reflection,
     * in `BootstrapLayeredSettingsTest`, since nothing observable downstream can
     * tell the three apart.
     */
    public static function useProjectRootForSettings(?string $root): void
    {
        self::$projectRootForSettings = $root === null || trim($root) === '' ? null : $root;
    }

    /**
     * Whether the operator opted this project root in to contributing settings
     * — {@see projectCommandShellIsTrusted()}'s shape for
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}, with the same
     * fail-closed-on-every-uncertainty behaviour and the same once-per-process
     * freeze.
     *
     * THE THROW IS SWALLOWED HERE and nowhere else in the family. Its three
     * siblings let {@see PermissionConfigException} out, because each is called
     * from a launch path that SHOULD refuse to start on an unusable permission
     * policy. This one is called from {@see readUserConfig()}, whose contract is
     * that a corrupt config costs the theme and not the session — see its
     * doc-block — and which `EngineBackend` calls once per turn. So an
     * unreadable config, or a home this process cannot establish ownership of,
     * costs the project settings layer. That is fail-closed: the layer is the
     * lowest-trust input in the stack, and its absence is the pre-layering
     * behaviour.
     */
    private static function projectSettingsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        // INSIDE the try as well, unlike the three siblings: this is the
        // home-ownership gate, and it throws on exactly the launch
        // ({@see requireHomeDirectory()}) that must not take the theme with it.
        // Leaving it outside would have made the swallow above cosmetic — the
        // first uncertainty in the chain would still have escaped.
        try {
            $path = self::trustedConfigDirPath() . '/config.json';

            if (!\array_key_exists($path, self::$trustedSettingsRoots)) {
                self::$trustedSettingsRoots[$path] = self::trustedProjectRoots(
                    self::permissionConfig(),
                    LayeredSettings::PROJECT_SETTINGS_TRUST_KEY,
                    'no project settings file may contribute a setting',
                );
            }
        } catch (\Throwable) {
            return false;
        }

        return in_array($canonical, self::$trustedSettingsRoots[$path], true);
    }

    /**
     * Read-merge-write $patch into the persisted user config, so a single
     * call only ever touches the keys it names (e.g. switching the theme
     * doesn't clobber a previously-persisted provider choice).
     *
     * The replacement is ATOMIC — write a sibling temp file, then `rename()`
     * over the target — because a partial write here is not merely a lost
     * theme: {@see permissionConfig()} refuses to launch on a config it cannot
     * parse, so a `/theme` or Ctrl+P persist interrupted by SIGINT, an OOM
     * kill or a full disk would leave a truncated file that bricks every
     * subsequent run, from inside the one binary that could have fixed it.
     * `rename()` within a directory is atomic on POSIX, so a reader ever sees
     * the old file or the new one and never a half of either.
     *
     * @param array<string, mixed> $patch
     */
    public static function writeUserConfig(array $patch): void
    {
        // rawUserConfig(), NOT readUserConfig(): see that method for why merging
        // onto the LAYERED view would persist a project's or a settings.json's
        // values into the user's own file as a side effect of switching a theme.
        $merged = array_merge(self::rawUserConfig(), $patch);
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // The write, not the read, is what earns the directory — see
        // {@see userConfigPath()}. Taken from the target FILE rather than from
        // configDirPath(): the two name the same directory until `--config`
        // points the target somewhere else ({@see useConfigPath()}), and then
        // configDirPath() is the wrong one in two ways at once. The sibling
        // check below would still PASS — tempnam() put the temp file exactly
        // where it was asked to — and the rename() under it would then be a
        // cross-directory move that is atomic only by luck and fails outright
        // across a mount, losing the persist; and ensureDir() would create
        // ~/.sugar-crush purely as scratch space on a run that was told to
        // stay out of it. MEASURED: with configDirPath() here, a persist to a
        // /tmp override still lands (same filesystem) but leaves that stray
        // directory behind, which is what
        // {@see BootstrapConfigPathOverrideTest::testWriteUserConfigPersistsIntoTheOverrideFile()}
        // pins.
        $dir = \dirname(self::userConfigPath());
        self::ensureDir($dir);

        // The temp file must be the target's SIBLING: rename() is only atomic
        // within one filesystem, and tempnam() silently falls back to the
        // system temp dir when the requested one is unusable — which on a
        // separate mount would turn the rename into a failure rather than a
        // torn write, but is worth refusing explicitly either way.
        //
        // Compared through realpath() on BOTH sides, never as raw strings:
        // tempnam() hands back a CANONICAL path, so a `HOME` with a trailing
        // slash (`HOME=/root/` is ordinary in a Dockerfile), a doubled slash,
        // or a `/./` made `dirname($temp) !== $dir` true for every write and
        // silently disabled config persistence outright — the sibling check
        // refusing the one directory it was pointed at. `realpath()` cannot
        // fail here: ensureDir() has just guaranteed $dir, and tempnam()
        // returns a file it created.
        $temp = @tempnam($dir, '.config.json.');
        if ($temp === false || realpath(\dirname($temp)) !== realpath($dir)) {
            if (is_string($temp)) {
                @unlink($temp);
            }

            return;
        }

        if (@file_put_contents($temp, $json) !== strlen($json) || !@rename($temp, self::userConfigPath())) {
            // Losing the setting is the correct outcome of a failed write.
            // Leaving the previous config intact is the important half.
            @unlink($temp);

            return;
        }

        // tempnam() creates at 0600; the file this replaces was created at the
        // process umask. 0600 is kept deliberately — this file now carries the
        // launch's permission policy, so it is nobody else's business.
        @chmod(self::userConfigPath(), 0600);
    }

    /**
     * Discover every skill reachable from $root and hand back the populated
     * registry: built-in (src/Skills/BuiltIn), user (~/.sugar-crush/skills),
     * project ({$root}/.sugar-crush/skills), and foreign imports from other
     * coding CLIs' conventions — {$root}/.claude/skills, ~/.claude/skills,
     * {$root}/.opencode/skills, ~/.config/opencode/skills (see {@see
     * \SugarCraft\Crush\Skills\ForeignSkillDiscovery}).
     *
     * The foreign half of that list was ASPIRATIONAL until crush_code.md
     * Phase 2 item 6: nothing called ForeignSkillDiscovery, so a
     * `~/.claude/skills` tree was discovered by its unit tests and by nothing
     * else. {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} now calls
     * it, registering the imports FIRST so a native skill still wins a name
     * collision — see that method for why additive is the only safe merge
     * direction here.
     *
     * This is the missing link crush_feat.md section 10.5(1) needed: without
     * it, SkillManager/SkillLoader/ForeignSkillDiscovery were only ever
     * exercised by their own unit tests — nothing in `bin/sugarcrush` called
     * them, so a skill dropped under any of those directories had zero
     * observable effect on a real run. Called from both {@see backend()} and
     * {@see backendFor()} so every provider path (env-driven, persisted, and
     * explicit Ctrl+P selection) gets the same discovered {@see
     * SkillRegistry} threaded into {@see EngineBackend::withSkillRegistry()}.
     */
    private static function skillRegistry(string $root): SkillRegistry
    {
        $registry = new SkillRegistry();
        $manager = new SkillManager(new SkillLoader(), $registry);
        $manager->loadAll($root);

        // section 7 E1's disableFromConfig() step: a user who does not want a
        // discovered skill offered to the model needs somewhere to say so,
        // and the persisted user config is the only per-user settings file
        // the CLI already reads. Absent key => nothing disabled.
        $disabled = self::readUserConfig()['disabledSkills'] ?? [];
        if (is_array($disabled)) {
            $manager->disableFromConfig(array_values(array_filter($disabled, 'is_string')));
        }

        // The diagnostic outlives the manager that produced it — see
        // {@see skillSkips()}. Merged rather than replaced because a launch
        // scans more than once (chat(), app(), and every Ctrl+P provider
        // switch), and a later scan finding nothing wrong must not erase what
        // an earlier one found.
        self::$skillSkips = [...self::$skillSkips, ...$manager->skipped()];

        // The skills half of the project-tier refusal — a repository that
        // committed `.sugar-crush/skills`, `.claude/skills` or `.opencode/skills`
        // as a link out of the checkout gets the tree dropped, and said so
        // nowhere. Merged, not replaced, for the reason above.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$manager->refusedDirectories()];

        return $registry;
    }

    /**
     * Every SKILL.md this process's skill scans could not read, keyed by path.
     *
     * The seam that replaced {@see \SugarCraft\Crush\Skills\SkillLoader}'s old
     * per-skip `error_log()`. Getting those lines off stderr was right — they
     * are OTHER TOOLS' files, one broken third-party skill printed on every
     * launch, and a skill scan also runs mid-session on the Ctrl+P provider
     * switch, where the line lands inside a frame the renderer believes it
     * owns — but a diagnostic nothing reads is the same as no diagnostic. So
     * the detail lives here, reachable from a doctor report or a debug pane,
     * and {@see reportSkillSkips()} puts ONE bounded line in front of the user
     * at launch so they know it is here to ask for.
     *
     * @return array<string, string> sourcePath => why it was skipped
     */
    public static function skillSkips(): array
    {
        return self::$skillSkips;
    }

    /**
     * Tell the user, once and in one line, that some skill files were skipped.
     *
     * ONE LINE regardless of how many, and only when there ARE some: the
     * failure this replaced was N lines every launch. Called from
     * {@see chat()} rather than from {@see skillRegistry()} deliberately —
     * skillRegistry() also runs on the Ctrl+P provider switch, mid-session,
     * with the alt screen up, and that is precisely where a stderr write
     * corrupts the frame. Construction time, before Program takes the
     * terminal, is the only safe moment, which is the same reasoning
     * {@see hookFiles()}'s skip notice is written under.
     *
     * PUBLIC because two construction paths outside this method's original
     * caller reach a skill scan and never reported it: {@see app()} scans a
     * second time for the shell's panes (skips found only by THAT scan were
     * collected and never surfaced), and {@see NonInteractive::run()} builds a
     * backend without going through {@see chat()} at all, so a `-p` run
     * swallowed the notice outright. Both call it at construction time, and the
     * `-p` path has no alt screen to corrupt in the first place.
     */
    public static function reportSkillSkips(): void
    {
        // Only what has not been reported yet, keyed the same way
        // {@see $reportedUntrustedHookFiles} is: a process that builds more
        // than one Chat (a test suite, an embedder) must not re-print the
        // same skips, and a second scan that found something NEW still says so.
        $new = array_diff_key(self::$skillSkips, self::$reportedSkillSkips);
        if ($new === []) {
            return;
        }

        foreach (array_keys($new) as $path) {
            self::$reportedSkillSkips[$path] = true;
        }

        $count = \count($new);
        // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. A skill
        // that did not load is a capability the session does not have, and the
        // user meets that as `/skill` not offering something they wrote. ONE
        // ROW, whatever the count: this message is already an aggregate, which
        // is what makes it safe to put in a transcript that also has to carry
        // eleven other sources.
        self::warnPermissionConfigInTranscript(sprintf(
            '%d skill file%s could not be read and %s skipped; set %s=1 to list %s',
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'was' : 'were',
            SkillLoader::DEBUG_SKIPS_ENV,
            $count === 1 ? 'it' : 'them',
        ));
    }

    /**
     * The launch's PreToolUse/PostToolUse chain: the built-in guards, then
     * whatever {@see hookFiles()} found on disk, then the permission gate.
     *
     * THE ORDER IS THE POINT. Built-ins first, config hooks second, the gate
     * LAST — a scan reports the FIRST refusal it meets
     * ({@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} returns a
     * non-permitting result outright), so this puts the narrow, specific
     * hazard ("this touches a protected file", then the user's own rule) ahead
     * of the broad policy one ("mode plan does not allow Edit"). Loading the
     * config hooks BEFORE the gate also keeps the gate last on both
     * construction paths, since {@see EngineBackend}'s own
     * `resolveHookManager()` appends it to whatever manager it is handed.
     *
     * A config hook cannot use that position to WIN over a built-in DENY: a
     * pass stops at the first non-permitting result, and the built-ins are
     * ahead of it, so a config hook can only ever report on a call the guards
     * before it already permitted.
     *
     * What that same short-circuit costs, stated rather than implied: the gate
     * is LAST, so ANY earlier non-permitting result returns before
     * {@see PermissionGateHook::execute()} is reached and the refusal never
     * reaches {@see PermissionGate}'s Auto-mode 3-strike circuit breaker. That
     * is not a cost this wiring introduced and it is not about config hooks:
     * the three built-ins have always been ahead of the gate, so a
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook} DENY — the
     * commonest refusal there is — was already uncounted before a hook FILE
     * could be loaded at all. Registering config hooks in between adds one
     * more source of the same uncounted refusal, not a new failure mode. It is
     * a MISCOUNT rather than a hole — the call is refused either way, and the
     * strike counter only escalates refusals it never saw — and closing it means
     * either running the gate on calls another hook already denied (giving a
     * denied call a second, weaker verdict) or teaching the registry to notify
     * skipped hooks. Both are larger than this wiring; the miscount is the
     * documented trade.
     *
     * @param PermissionGate|null $gate Install this gate as an additional
     *        PreToolUse layer, AFTER the built-ins — see {@see PermissionGateHook}
     *        for why that order. Pass the launch's single gate instance so the
     *        Auto-mode circuit breaker counts strikes once across every path
     *        that shares this manager; null keeps the built-ins-only chain
     *        every caller had before crush_code.md Phase 1 item 2.
     * @param string|null $root The project whose `.sugar-crush/hooks.yaml` is
     *        read alongside the user's. Null reads only the user's file, which
     *        is what a caller with no root of its own to give should get
     *        rather than a hook file resolved against the process directory.
     *
     * @throws PermissionConfigException when a hook file is present and unusable
     */
    private static function hooks(?PermissionGate $gate = null, ?string $root = null): HookManager
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->registerBuiltIns(); // audit + confirm-rm + protect-files guards

        foreach (self::hookFiles($root) as $path) {
            try {
                $hooks->loadEntries(self::hookFileEntries($path), $path);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                // Same refusal {@see permissionConfig()} makes, for the same
                // reason: this file is part of the launch's gating policy, so
                // "present but unusable" may not degrade into a shorter guard
                // chain nobody was told about. PermissionConfigException is
                // what `bin/sugarcrush`, {@see NonInteractive::run()} and
                // {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner}
                // already turn into a clean exit-2 usage report.
                throw new PermissionConfigException(
                    $e->getMessage() . ' Refusing to start rather than run with a hook chain '
                    . 'that is not the one configured.',
                    0,
                    $e,
                );
            }
        }

        if ($gate !== null) {
            $hooks->register(new PermissionGateHook($gate));
        }

        return $hooks;
    }

    /**
     * $path's hook entries, READ ONCE PER PROCESS.
     *
     * THE CHAIN A LAUNCH STARTS WITH IS THE CHAIN IT RUNS. A hook entry is a
     * shell command, and this method is reached on every hook-manager build —
     * the launch's two, plus one more for each Ctrl+P provider switch
     * ({@see \SugarCraft\Crush\Chat::selectPaletteProvider()} =>
     * {@see backendFor()}). Re-reading the file on each of those meant the
     * SESSION ITSELF could install hooks into itself: `Bash` is deliberately
     * not path-jailed, so one `>> ~/.sugar-crush/hooks.yaml` followed by a
     * provider switch put attacker-authored shell in the guard chain
     * mid-session, with no relaunch and no prompt.
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} denies the write
     * itself, but its `command` half is best-effort by nature (see that
     * method), so the read side may not depend on the write side having caught
     * it. Freezing at first read is what makes the two independent.
     *
     * The cost is stated rather than implied: a hook file EDITED BY THE USER
     * mid-session no longer takes effect until the next launch. That is the
     * same "one config source for the whole launch" commitment
     * {@see agentManager()} already documents for the permission rules, and it
     * is the half of the trade that can be undone by pressing Ctrl+C.
     *
     * Keyed by path, so this does not entangle a project file with the user's,
     * and an absent file is memoised as `[]` — a file that appears mid-session
     * is exactly the case being closed.
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>
     *
     * @throws PermissionConfigException when the user's own hook file is
     *         writable by other accounts, or belongs to one
     * @throws \RuntimeException|\InvalidArgumentException see {@see \SugarCraft\Crush\Hooks\HookConfig::loadFromFile()}
     */
    private static function hookFileEntries(string $path): array
    {
        if (!\array_key_exists($path, self::$hookFileEntries)) {
            // Only the USER's file. The project's is gated by
            // `trustedProjectHooks` and lives in a checkout whose permissions
            // are the user's business; the user's file is the one loaded with
            // no gate at all, on a premise that ownership is what makes true.
            if ($path === self::trustedConfigDirPath() . '/hooks.yaml') {
                self::requirePrivatePolicyFile($path);
            }

            self::$hookFileEntries[$path] = HookConfig::loadFromFile($path);
        }

        return self::$hookFileEntries[$path];
    }

    /**
     * Refuse a policy file that is not exclusively this account's.
     *
     * The gate on the project hook file answers "did the user trust this
     * repository". This answers the question underneath it — "is the file that
     * records the answer actually the user's" — which nothing checked, and
     * which the whole `~/.sugar-crush` premise rests on. A `config.json` owned
     * by another uid, or sitting in a world-writable directory, is somebody
     * else's `permissionMode`, `permissionRules` and `trustedProjectHooks`.
     *
     * WORLD-WRITABLE, NOT GROUP-WRITABLE. Debian/Ubuntu ship umask 002 with
     * user-private groups — every file this CLI writes on such a box is
     * group-writable to a group with exactly one member — so refusing on the
     * group bit would be a launch failure for the default configuration of a
     * mainstream distribution rather than a finding about anything. The `o+w`
     * bit has no such benign reading, and it is the bit `/tmp`-style planted
     * directories carry.
     *
     * OWNERSHIP is checked where `ext-posix` allows: it is the half that
     * catches a file another account planted with sane permissions. It does
     * NOT catch the session writing its own home — same uid — which is what
     * {@see hookFileEntries()} and {@see trustedRootsForThisProcess()} freeze
     * against instead.
     *
     * @throws PermissionConfigException
     */
    private static function requirePrivatePolicyFile(string $path): void
    {
        foreach ([$path, \dirname($path)] as $target) {
            if (!file_exists($target)) {
                continue;
            }

            clearstatcache(true, $target);

            $mode = @fileperms($target);
            if ($mode !== false && ($mode & 0002) !== 0) {
                throw new PermissionConfigException(
                    "{$target} is writable by every account on this machine, so the permission "
                    . 'policy and hook chain it carries are not this user\'s. Refusing to start '
                    . 'rather than run policy anyone could have written — `chmod o-w ' . $target . '`.',
                );
            }

            if (!\function_exists('posix_geteuid')) {
                continue;
            }

            $owner = @fileowner($target);
            if ($owner !== false && $owner !== posix_geteuid()) {
                throw new PermissionConfigException(
                    "{$target} belongs to uid {$owner}, not to the account this session is running as, "
                    . 'so the permission policy and hook chain it carries are somebody else\'s. '
                    . 'Refusing to start rather than run another account\'s policy.',
                );
            }
        }
    }

    /**
     * The hook files this launch reads, lowest-priority first: the user's
     * `~/.sugar-crush/hooks.yaml` always, and the project's
     * `{root}/.sugar-crush/hooks.yaml` ONLY when the user has said they trust
     * that project.
     *
     * THE PROJECT FILE IS ARBITRARY CODE EXECUTION FROM CLONED CONTENT, and
     * that is the whole reason for the gate. A hook entry is a shell command;
     * a `matcher: '.*'` entry runs it on the model's first tool call. So
     * `git clone <untrusted> && cd <it> && sugarcrush` would run a stranger's
     * shell with no prompt and nothing in the transcript — and no permission
     * mode saves the user from it, because the config hooks are registered
     * BEFORE {@see PermissionGateHook} and a scan stops at the first refusal,
     * so the payload has already run by the time the gate would have refused.
     * `--permission-mode plan` was measured as `verdict=allow, attacker shell
     * ran: YES`. Nothing else in this codebase runs project-authored code:
     * {@see agentPresets()} lets a project WEAKEN a sub-agent's policy, which
     * is influence over policy, not execution.
     *
     * THE OPT-IN. `~/.sugar-crush/config.json` — the user's own file, which no
     * repository can write BY BEING CLONED — carries a `trustedProjectHooks`
     * list of project roots:
     *
     * ```json
     * { "trustedProjectHooks": ["/home/you/work/my-repo", "~/src/other"] }
     * ```
     *
     * Per-path rather than one global `true` because "I trust this one repo"
     * is the real need, and a global flag re-opens the hole in every other
     * checkout the moment it is set. Entries are compared by `realpath()`, so
     * a symlinked or trailing-slash spelling of a trusted root still matches
     * and a path that does not resolve simply never matches.
     *
     * WHAT "THE USER'S OWN FILE" CAN AND CANNOT MEAN. A clone cannot write it,
     * but the SESSION runs as the user: `Bash` is not path-jailed, and a
     * cloned README or `CLAUDE.md` prompting the model into appending one line
     * to `trustedProjectHooks` is the same threat class as the hook file
     * itself. Two things keep the grant the user's:
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} denies the tool
     * call, and {@see trustedRootsForThisProcess()} freezes the list for the
     * process so a write that got past it cannot take effect in the session
     * that made it. Neither can make a NEXT launch safe — see that method for
     * the boundary, stated rather than implied.
     *
     * THE GATE, NOT THE FEATURE, IS WHAT IS CONDITIONAL. The project-file code
     * path is intact and reachable; only the decision to walk it is gated.
     * That is deliberate room for the better answer: a per-directory trust
     * PROMPT (print the hooks, require an explicit yes, record the decision
     * keyed by real path + content hash, re-prompt when the hash changes)
     * belongs here as a SECOND way to satisfy {@see projectHooksAreTrusted()},
     * beside the config key rather than instead of it — a prompt needs a
     * terminal, and this method also runs on the non-interactive and
     * background-session paths where there is nobody to ask.
     *
     * The gate is also what has to stand between a `SessionStart` hook and
     * execution. {@see \SugarCraft\Crush\Hooks\HookConfig::parse()} accepts
     * every {@see \SugarCraft\Crush\Hooks\HookEvent} case, so such an entry
     * registers today and is inert only because nothing constructs
     * {@see \SugarCraft\Crush\Hooks\HookDispatcher}. Wire session-lifecycle
     * dispatch up and the payload moves from first-tool-call to launch with no
     * other change — so "the gate refused to load it" is the property that has
     * to hold, not "nothing dispatches it yet".
     *
     * PATH SHAPE COPIED FROM {@see agentPresets()} — the project's
     * `.sugar-crush/<thing>` beside the user's, resolved off
     * {@see configDirPath()} rather than {@see configDir()} so reading the
     * hook chain is not what creates `~/.sugar-crush`. YAML because
     * {@see \SugarCraft\Crush\Hooks\HookConfig} parses YAML (symfony/yaml is a
     * hard dependency of this package), so the extension names what the file
     * actually is.
     *
     * WHEN BOTH ARE LOADED, neither overrides the other, and a name collision
     * between them is refused by {@see HookManager::loadFromFile()}. That is
     * deliberately NOT the "later source overrides earlier" precedence
     * {@see \SugarCraft\Crush\Skills\SkillLoader::loadAll()} uses for skills,
     * because a hook chain is not a lookup table: overriding by name would
     * mean a checked-in project file could disarm a guard the user wrote for
     * themselves — by naming it — on the first repo they cloned, and the
     * reverse ordering would let a personal file disarm the project's. Adding
     * is the only thing a second file is allowed to do.
     *
     * @return list<string> paths that may or may not exist, de-duplicated by
     *         real path; an absent file is a no-op, see
     *         {@see \SugarCraft\Crush\Hooks\HookConfig::loadFromFile()}
     *
     * @throws PermissionConfigException when a path cannot be reached, so
     *         whether a hook file is configured there is unknowable
     */
    private static function hookFiles(?string $root): array
    {
        // trustedConfigDirPath(), NOT configDirPath(): the user file is loaded
        // with no trust gate at all on the grounds that the user wrote it, and
        // that premise only holds in a directory only the user can write.
        $paths = [self::trustedConfigDirPath() . '/hooks.yaml'];

        if ($root !== null) {
            // CANONICAL, not as spelled. The trust decision below is made on
            // `realpath($root)`, so naming the file off the raw string would
            // leave the loaded path dependent on the process directory for a
            // decision that was not — and an in-process `chdir()` would then
            // re-point a path the launch had already vetted.
            $canonicalRoot = realpath($root);
            $projectFile = rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/') . '/.sugar-crush/hooks.yaml';

            if ($canonicalRoot !== false && self::projectHooksAreTrusted($canonicalRoot)) {
                $paths[] = $projectFile;
            } elseif (is_file($projectFile)) {
                // NOT a silent drop. Ignoring a file the repo's author expects
                // to run changes what the session does, so it is reported the
                // same way a skipped permission rule is — BOTH CHANNELS, see
                // {@see warnPermissionConfigInTranscript()}. This runs during
                // construction, before Program takes the terminal, so the
                // stderr copy lands ahead of the alt screen rather than inside
                // a frame — but "ahead of the alt screen" is exactly where the
                // alt screen then paints OVER it, which is why the transcript
                // copy exists as well.
                //
                // ONCE PER PATH PER PROCESS. {@see chat()} builds two hook
                // managers (its own chain and the engine backend's), so an
                // untrusted project printed this twice on every interactive
                // launch — and a notice a user meets twice a run for doing
                // nothing wrong is a notice they learn to scroll past.
                //
                // The advice names the CANONICAL path, which is the whole
                // point of printing it: `--root .` used to print `Add "." to
                // trustedProjectHooks`, and a literal `"."` entry is
                // realpath()'d against the CWD on every launch exactly as the
                // root is, so it always agrees — following the tool's own
                // instruction turned a per-path allowlist into "trust every
                // repository I cd into". {@see trustedProjectHookRoots()}
                // refuses such an entry now; this makes sure the tool never
                // suggests one.
                if (!isset(self::$reportedUntrustedHookFiles[$projectFile])) {
                    self::$reportedUntrustedHookFiles[$projectFile] = true;

                    self::warnPermissionConfigInTranscript(
                        "{$projectFile} was NOT loaded: honouring a project hook file means running shell "
                        . "this repository's author wrote, every time you open it. Add "
                        . '"' . rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/')
                        . '" to "' . self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY
                        . '" in ' . self::userConfigPath() . ' to opt in',
                    );
                }
            }
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                continue;
            }

            // The same ambiguity {@see permissionConfig()} draws the line on:
            // `is_file()` answers false both for "there is no hook file" and
            // for "a directory on the way to it cannot be searched", and only
            // the first is "nothing was configured". Reading the second as
            // absence would run the session with a guard chain shorter than
            // the configured one and say nothing.
            $unreachable = self::unreachableAncestor($path);
            if ($unreachable !== null) {
                throw new PermissionConfigException(
                    "{$path} cannot be reached: {$unreachable}, "
                    . 'so whether hooks are configured there is unknowable. '
                    . 'Refusing to start rather than run with an unknown hook chain.',
                );
            }
        }

        // DE-DUPLICATED BY REAL PATH, because the two candidates are not always
        // two files. Run `sugarcrush` in your own home directory — or with
        // `--root .` from it, or through a symlinked/trailing-slash alias of it
        // — and both entries name `~/.sugar-crush/hooks.yaml`. Loading it twice
        // hits {@see HookManager::loadFromFile()}'s already-registered guard and
        // kills the launch with exit 2 over a collision that does not exist,
        // and it does so only for users who actually wrote hooks.
        $unique = [];
        foreach ($paths as $path) {
            // `?: $path` keeps a not-yet-existing file (the fresh-install case,
            // where realpath() answers false) as its own entry rather than
            // collapsing every absent candidate onto one key.
            $unique[realpath($path) ?: $path] ??= $path;
        }

        return array_values($unique);
    }

    /**
     * Whether the user has opted this project root's `.sugar-crush/hooks.yaml`
     * in — see {@see hookFiles()} for the threat this answers and for the trust
     * PROMPT that belongs beside this check rather than instead of it.
     *
     * Fails closed on every uncertainty: an unresolvable root, an absent key, a
     * key of the wrong shape. The one thing it does not degrade quietly on is a
     * `config.json` that exists and cannot be parsed — {@see permissionConfig()}
     * stops the launch there, and this is the same file and the same class of
     * decision.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    private static function projectHooksAreTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        return in_array($canonical, self::trustedRootsForThisProcess(), true);
    }

    /**
     * The trusted roots for this launch, RESOLVED ONCE AND NOT AGAIN.
     *
     * THE GRANT MAY NOT BE MADE BY THE THING IT GATES. `trustedProjectHooks`
     * lives in `~/.sugar-crush/config.json`; the shipped default permission
     * mode is {@see DEFAULT_PERMISSION_MODE}; and `Bash` is deliberately not
     * path-jailed ({@see \SugarCraft\Crush\Tools\BuiltIn\Bash}). This method
     * used to re-read the file on every hook-manager build, so a repository
     * whose README prompt-injected the model into appending one line to that
     * list — then any Ctrl+P provider switch — had its own `.sugar-crush/hooks.yaml`
     * shell running mid-session, no relaunch and no prompt. That is precisely
     * the cloned-content threat {@see hookFiles()} describes, arriving through
     * the allowlist instead of around it.
     *
     * Freezing at first read is what breaks the loop: within a session the
     * answer to "which repositories did the user trust" is whatever it was
     * when the session started, so a write made DURING the session — however
     * it got past {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} —
     * cannot take effect in that session. What it does not, and cannot, do is
     * make a NEXT launch safe: a session that can run arbitrary shell as this
     * user can leave anything behind in this user's home. The property claimed
     * here is narrower and is the one the gate needs to be worth having — the
     * trust decision is the user's, and is made before the untrusted content
     * is running, not by it and not while it runs.
     *
     * Keyed by the config path so a process that legitimately moves `HOME`
     * (the test suite) still reads each home once, rather than one home ever.
     *
     * @return list<string>
     *
     * @throws PermissionConfigException see {@see permissionConfig()}
     */
    private static function trustedRootsForThisProcess(): array
    {
        $path = self::trustedConfigDirPath() . '/config.json';

        if (!\array_key_exists($path, self::$trustedRoots)) {
            self::$trustedRoots[$path] = self::trustedProjectHookRoots(self::permissionConfig());
        }

        return self::$trustedRoots[$path];
    }

    /**
     * The project roots listed under `trustedProjectHooks`, canonicalised —
     * {@see trustedProjectRoots()} with this class's HOOK key. Kept as a named
     * method of its own arity because it is what {@see hookFiles()}'s trust chain
     * reads and what two tests invoke by reflection to assert the parsing rules
     * below.
     *
     * Item-wise tolerance copied from {@see permissionRules()}: one malformed
     * entry is skipped and reported rather than silently widening or narrowing
     * the whole list. An entry that does not RESOLVE is dropped without a
     * warning, though — it can never match anything, and the launch already
     * prints the far more useful "this project's hook file was not loaded"
     * line when that was what the user meant to opt in.
     *
     * REPORTED ONCE PER PROCESS, through {@see warnPermissionConfigOnce()} and
     * for the same reason the sibling notice in {@see hookFiles()} is latched:
     * a hook manager is built at launch and again on every Ctrl+P provider
     * switch, and by the second one the alt screen is up, so the line lands in
     * a frame the renderer believes it owns. It fires on exactly the upgrade
     * path this diff created — a user who followed the tool's older advice and
     * wrote `"."` is the one who now gets a warning — so it is the last
     * message that should be repainting somebody's transcript.
     *
     * @param array<string, mixed> $config the already-read user config
     * @return list<string>
     */
    private static function trustedProjectHookRoots(array $config): array
    {
        return self::trustedProjectRoots(
            $config,
            self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY,
            'no project hook file was trusted',
        );
    }

    /**
     * Whether the user has opted this project root's `.mcp.json` in — the
     * {@see projectHooksAreTrusted()} of {@see TRUSTED_PROJECT_MCP_CONFIG_KEY},
     * with the same fail-closed-on-every-uncertainty behaviour and the same
     * once-per-process freeze. See {@see mcpClient()} for why starting a server
     * needs the gate.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    private static function projectMcpIsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        $path = self::trustedConfigDirPath() . '/config.json';

        if (!\array_key_exists($path, self::$trustedMcpRoots)) {
            self::$trustedMcpRoots[$path] = self::trustedProjectRoots(
                self::permissionConfig(),
                self::TRUSTED_PROJECT_MCP_CONFIG_KEY,
                'no project MCP config was trusted',
            );
        }

        return in_array($canonical, self::$trustedMcpRoots[$path], true);
    }

    /**
     * Whether the operator has opted this project root in to running the
     * `` !`cmd` `` form of a command file under
     * `<root>/.sugar-crush/commands` — {@see projectMcpIsTrusted()}'s shape for
     * {@see TRUSTED_PROJECT_COMMANDS_CONFIG_KEY}, with the same
     * fail-closed-on-every-uncertainty behaviour and the same once-per-process
     * freeze.
     *
     * PUBLIC, unlike its two siblings, because the consumer is not in this
     * class: the check happens when a `/name` is submitted, inside
     * {@see \SugarCraft\Crush\Chat}, and {@see chat()} passes the ANSWER (a
     * bool) rather than the ability to ask. Handing Chat this method instead
     * would let it re-ask mid-session, which is precisely what the freeze exists
     * to prevent.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    public static function projectCommandShellIsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        $path = self::trustedConfigDirPath() . '/config.json';

        if (!\array_key_exists($path, self::$trustedCommandRoots)) {
            self::$trustedCommandRoots[$path] = self::trustedProjectRoots(
                self::permissionConfig(),
                self::TRUSTED_PROJECT_COMMANDS_CONFIG_KEY,
                'no project command file may run a shell',
            );
        }

        return in_array($canonical, self::$trustedCommandRoots[$path], true);
    }

    /**
     * The project roots listed under $key in the user config, canonicalised.
     *
     * ONE PARSER FOR ALL FOUR TRUST KEYS, parameterised by the key name rather
     * than copied. This said "BOTH … `trustedProjectHooks` and
     * `trustedProjectMcp`" while there were already four callers, which is the
     * kind of count that rots silently — so it is written as the list, and the
     * list is asserted by
     * {@see \SugarCraft\Crush\Tests\Config\TrustKeyDocumentationDriftTest}:
     *
     *  - {@see TRUSTED_PROJECT_HOOKS_CONFIG_KEY} via {@see projectHooksAreTrusted()}
     *  - {@see TRUSTED_PROJECT_MCP_CONFIG_KEY} via {@see projectMcpIsTrusted()}
     *  - {@see TRUSTED_PROJECT_COMMANDS_CONFIG_KEY} via {@see projectCommandShellIsTrusted()}
     *  - {@see \SugarCraft\Crush\Config\LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}
     *    via {@see projectSettingsTrusted()}
     *
     * They are four separate GRANTS ({@see TRUSTED_PROJECT_MCP_CONFIG_KEY} says
     * why they may not be one) but the same DATA SHAPE, and every rule below —
     * the relative-entry refusal, the `~` expansion, the item-wise tolerance,
     * the once-per-process warning — is a property of "a list of project roots
     * in the user's config", not of what the list authorises. A second copy
     * would be a second place for the `"."`-is-a-global-bypass refusal to be
     * forgotten.
     *
     * The four do NOT share caching or failure behaviour, only parsing: each
     * caller memoises into its own static, and {@see projectSettingsTrusted()}
     * alone swallows the {@see trustedConfigDirPath()} throw instead of
     * propagating it. See `PERMISSIONS.md` for that asymmetry.
     *
     * @param array<string, mixed> $config the already-read user config
     * @param string $key which trust list to read
     * @param string $nothingTrusted what a wrong-shaped value costs, for the
     *        warning — the only sentence that differs between the four keys
     * @return list<string>
     */
    private static function trustedProjectRoots(array $config, string $key, string $nothingTrusted): array
    {
        $raw = $config[$key] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            self::warnPermissionConfigOnce(
                $key . ' is not a list of project paths; ' . $nothingTrusted,
            );

            return [];
        }

        $roots = [];
        foreach ($raw as $index => $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                self::warnPermissionConfigOnce(
                    $key . "[{$index}] is not a project path; entry skipped",
                );
                continue;
            }

            $entry = trim($entry);
            $expanded = str_starts_with($entry, '~/') || $entry === '~'
                ? self::homePath() . substr($entry, 1)
                : $entry;

            // A RELATIVE ENTRY IS A GLOBAL BYPASS, not a narrow trust. This
            // list is realpath()'d fresh on every launch and so is the root it
            // is compared against, so `"."` resolves to whatever directory the
            // process was started in and therefore ALWAYS matches — one entry
            // that trusts every repository the user ever cd's into. `"../x"`
            // and `"src/repo"` are the same defect wearing a longer name.
            //
            // REFUSED rather than resolved-once-at-parse-time, because there
            // is nothing stable to resolve it against: this file is per-USER
            // and read on every launch, while the only thing a relative path
            // could be anchored to — the CWD — is per-INVOCATION. Any
            // anchoring choice would make "which repo did I trust?" depend on
            // where the user happened to be standing when they last edited
            // the config, which is the ambiguity, not a fix for it. And it is
            // refused LOUDLY: a silently dropped entry leaves the user
            // believing they opted in.
            if (!self::isAbsolutePath($expanded)) {
                self::warnPermissionConfigOnce(
                    $key . "[{$index}] is '{$entry}', which is relative to "
                    . 'whatever directory sugarcrush was started in — it would trust EVERY repository you '
                    . 'run it from, not one. Write the absolute path (or a ~/-rooted one); entry skipped',
                );
                continue;
            }

            $canonical = realpath($expanded);
            if ($canonical !== false) {
                $roots[] = $canonical;
            }
        }

        return $roots;
    }

    /**
     * Whether $path names a location independently of the process directory.
     *
     * Windows spellings are recognised as well as POSIX ones because
     * {@see resolvedHomePath()} reads `$USERPROFILE`, so a `~`-rooted entry can
     * legitimately expand to `C:\Users\you\...` on the one platform where a
     * drive letter — not a leading slash — is what makes a path absolute.
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * The launch's {@see PermissionGate} — crush_code.md Phase 1 item 2's
     * "constructed from the same config source as sub-agents".
     *
     * Same vocabulary the sub-agent path already speaks: the kebab-case
     * {@see PermissionMode} values an agent preset's `permissionMode:`
     * frontmatter uses (see {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}),
     * so `plan` means the same thing whether it is written in an agent preset
     * or in ~/.sugar-crush/config.json. Precedence: the env var is the
     * per-invocation override and wins over the persisted key.
     *
     * NOTHING HERE MAY FAIL OPEN. Every other setting this class reads
     * degrades quietly on a bad value because the cost of guessing wrong is a
     * default theme; here the fallback is the most PERMISSIVE mode, so the
     * same tolerance turns "I configured `plan` with a deny rule" plus one
     * stray comma into an ungated session the user has no way to notice. So
     * this method draws a hard line between ABSENCE and a PRESENT-BUT-UNUSABLE
     * input:
     *
     * - Absent (no config file, no `permissionMode`, no env var) => the
     *   documented default below. Nothing was configured, nothing is being
     *   overridden, and a fresh install must start.
     * - Present but unusable => {@see PermissionConfigException}, which
     *   `bin/sugarcrush` reports as an exit-2 usage error. That covers a
     *   config file that exists and cannot be read or parsed, and a
     *   `permissionMode` — from either source — naming no real mode
     *   (`paln`, `Plan`, `deny-all`).
     *
     * Hard-failing rather than warning-and-continuing on the corrupt-file case
     * is deliberate, and the asymmetry with a typo'd env value is only in the
     * MESSAGE, not the severity: a broken config file is precisely the input
     * whose intent cannot be recovered. `readUserConfig()` hands back `[]` for
     * it, so `permissionMode` and `permissionRules` both vanish at once and
     * there is no way to tell a file that only ever set a theme from one that
     * set a deny-everything policy. Continuing permissively is the reported
     * fail-open; continuing restrictively silently ignores what the file
     * actually said; refusing to start is the only outcome that is neither,
     * costs a one-line fix, and cannot be missed. The same file being
     * unreadable already silently drops the user's persisted provider and
     * theme, so this makes an existing quiet breakage loud rather than
     * inventing a new failure.
     *
     * DEFAULT IS BypassPermissions, deliberately, and TEMPORARILY. The main
     * loop had no gate at all before this, and a stricter default would have
     * been a breaking change on upgrade rather than a safer one: modes that
     * answer Ask (Default/AcceptEdits/Auto, for writes) failed CLOSED on the
     * engine path, because no caller anywhere attached an approver — so
     * Default mode would have turned "no permission system" into "every Edit
     * refused".
     *
     * HALF OF THAT IS NOW FIXED, and the default has NOT moved with it, on
     * purpose. {@see HeadlessPermissionPrompt} is attached on the console
     * paths — the `-p` one-shot ({@see NonInteractive}) and the
     * background-session daemon
     * ({@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::backend()})
     * — so an ASK there is prompted for at a terminal, and refused with a
     * reason naming the tool and the remedies without one. The TUI path still
     * fails closed: {@see backend()}/{@see backendFor()} leave the approver
     * off for it (see the `$consolePermissionPrompt` parameter), because
     * {@see \SugarCraft\Crush\Chat}'s prompt is a `Deferred` settled by a
     * later `Msg` and {@see EngineBackend::completeAsync()} runs the turn in a
     * forked child with a one-way channel home — neither of which a blocking
     * closure can serve. Flipping the default is what happens when THAT
     * lands, not before: it is the path a real interactive session runs on.
     *
     * Be honest about what the default costs: with the shipped
     * empty rule set, BypassPermissions is not "more guarded than before", it
     * is EXACTLY EQUAL to having no gate. Every destructive `rm` the gate's
     * circuit breaker refuses is already refused, earlier and more broadly, by
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}, and "explicit
     * deny rules still apply" says nothing when no rules are configured. What
     * the default buys is a gate that is REACHABLE and configurable: set
     * `permissionMode`/`permissionRules` and it starts deciding things. The
     * permissive default is a stopgap for the fail-closed ASK path, not the
     * settled design — it goes away once an ASK on the engine path can
     * actually be ANSWERED FROM A TUI SESSION. That takes the two pieces named
     * above and exactly ONE of them is now done: an approver IS attached, on
     * the console paths. The other is untouched — the channel it would have to
     * ask over from inside {@see EngineBackend::completeAsync()}'s forked
     * child is still a one-way frame stream, and no closure can put a question
     * on screen through it.
     *
     * Rules come from a `permissionRules` array of
     * `{"pattern": "Bash*", "action": "deny"}` objects. Unlike the mode, a
     * malformed entry is skipped individually rather than stopping the launch:
     * the list is item-wise, so one bad entry does not make the others
     * unreadable the way a JSON syntax error makes the whole file unreadable.
     * A skipped entry is reported on stderr, because a silently dropped `deny`
     * rule widens permission — and an entry whose `action` is not a real
     * {@see PermissionAction} is DROPPED, never coerced to allow.
     *
     * @throws PermissionConfigException when a present permission input cannot be used
     */
    public static function permissionGate(): PermissionGate
    {
        // The LAYERS rather than the merge, so the refusal below can name the
        // file the bad value actually came from — see
        // {@see permissionConfigLayers()} for the error message that made this
        // necessary. Merged here rather than through `permissionConfig()` so
        // the two policy files are read once per launch, not twice.
        $layers = self::permissionConfigLayers();
        $config = array_merge(...array_values($layers));

        // An EMPTY env var is absence, not a bad value: `FOO=` is how a
        // wrapper script drops an inherited override. Anything else it says,
        // including "0", is a value the user meant.
        $env = getenv(self::PERMISSION_MODE_ENV);
        $envRaw = ($env === false || $env === '') ? null : $env;

        // A present-but-non-string `permissionMode` (a number, a bool, a
        // nested object) is stringified rather than ignored, so it reaches the
        // same "that is not a mode" refusal an unrecognised string does.
        // Silently skipping it would be the fail-open again, one type away.
        $configRaw = $config[self::PERMISSION_MODE_CONFIG_KEY] ?? null;
        $configRaw = match (true) {
            $configRaw === null => null,
            is_string($configRaw) => $configRaw === '' ? null : $configRaw,
            is_scalar($configRaw) => var_export($configRaw, true),
            default => get_debug_type($configRaw),
        };

        // --permission-mode is the highest-precedence source: an explicit flag on
        // THIS launch beats an inherited env var and beats a config file. It runs
        // through the same permissionModeFrom(), so a value that is not a mode
        // fails with the same message shape, naming the flag as its source.
        //
        // WALKED rather than written as a `??` chain, so that WHICH source won
        // survives the resolution instead of being thrown away one line after
        // it was known. `/permissions` reports it, and re-deriving it at
        // display time would be a second copy of this precedence — free to
        // disagree with this one, and reading files that may have been edited
        // since. The loop is behaviourally identical to the chain it replaces:
        // `??` short-circuits, so a later source's BAD value is still never
        // validated (and so never throws) once an earlier source has answered.
        $candidates = [
            '--permission-mode' => self::$permissionModeOverride,
            '$' . self::PERMISSION_MODE_ENV => $envRaw,
            self::PERMISSION_MODE_CONFIG_KEY . ' in '
                . self::permissionKeySource($layers, self::PERMISSION_MODE_CONFIG_KEY) => $configRaw,
        ];

        $mode = null;
        $modeSource = 'the built-in default';
        foreach ($candidates as $source => $raw) {
            $resolved = self::permissionModeFrom($raw, $source);
            if ($resolved !== null) {
                $mode = $resolved;
                $modeSource = $source;
                break;
            }
        }
        $mode ??= self::DEFAULT_PERMISSION_MODE;

        // The classifier is what Auto mode gates on, and PermissionGate fails
        // CLOSED (everything Asks) without one — so it is always supplied
        // rather than only when the mode currently happens to be Auto: this
        // gate instance is shared, and a mode read from config is not
        // something this method should have to predict.
        return new PermissionGate($mode, self::permissionRules($config), new SafetyClassifier(), $modeSource);
    }

    /**
     * Resolve one permission-mode source: null when it said nothing, the mode
     * when it named one, and a throw when it named something that is not one.
     *
     * @param string|null $raw    the raw value, or null when the source is absent
     * @param string      $source how to name the source in the error message
     *
     * @throws PermissionConfigException
     */
    private static function permissionModeFrom(?string $raw, string $source): ?PermissionMode
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $mode = PermissionMode::tryFrom($raw);
        if ($mode !== null) {
            return $mode;
        }

        $valid = implode(', ', array_map(static fn(PermissionMode $m): string => $m->value, PermissionMode::cases()));

        throw new PermissionConfigException(
            "{$source} is '{$raw}', which is not a permission mode (expected one of: {$valid}). "
            . 'Refusing to start rather than fall back to the permissive default.',
        );
    }

    /**
     * {@see readUserConfig()}, minus the tolerance — see {@see permissionGate()}
     * for why the permission path may not share it.
     *
     * Kept separate rather than made the one reader for everything: the theme
     * and the persisted provider genuinely SHOULD survive a corrupt config,
     * because guessing wrong about them costs nothing.
     *
     * LAYERED SINCE Phase 6 item 4, over the user tier ONLY: the
     * `permissionMode`/`permissionRules` of `~/.sugar-crush/settings.json`
     * ({@see permissionSettingsLayer()}) beneath the whole of `config.json`.
     * Both files are read by {@see readPolicyFile()}, so the strictness is the
     * same for either — and no project file is consulted at any trust level,
     * which is the one thing {@see readUserConfig()} does differently and the
     * one thing it must never do here.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when either file exists and cannot be used
     */
    private static function permissionConfig(): array
    {
        // trustedConfigDirPath(), NOT userConfigPath(): the two resolve to the
        // same file whenever this user's home is knowable, and when it is not
        // this one refuses instead of reading a permission policy — and a
        // `trustedProjectHooks` list — out of a world-writable stand-in.
        //
        // Called for its THROW even when `--config` has named the file, on its
        // own line rather than as the right operand of `??` (which PHP would
        // not evaluate): naming the policy file explicitly says nothing about
        // whether the ~/.sugar-crush this process would go on to read hooks
        // and agent presets from is this user's, so the gate stays armed.
        return array_merge(...array_values(self::permissionConfigLayers()));
    }

    /**
     * The permission layers KEYED BY THE PATH THEY CAME FROM, lowest precedence
     * first — `settings.json` then `config.json`.
     *
     * THE KEYS ARE THE POINT, and they exist because an error message lied.
     * `permissionGate()` refuses a launch whose `permissionMode` names no real
     * mode, and it named the file as a literal `userConfigPath()` — hardcoded to
     * `config.json` — from the moment `settings.json` became a second source.
     * Measured: a `~/.sugar-crush/settings.json` of `{"permissionMode":"nope"}`
     * with no `config.json` at all refused the launch with "permissionMode in
     * …/config.json is 'nope'", sending the user to edit a file that does not
     * exist. {@see readPolicyFile()}'s own doc-block claims which file refused
     * the launch is always in the error; for that one branch it was not. So the
     * merge cannot be anonymous — provenance has to survive it, which is what
     * {@see permissionKeySource()} reads.
     *
     * `config.json` outranks `settings.json` for {@see readUserConfig()}'s
     * reason: it is the file the CLI WRITES, so it must also be the file that
     * decides. `array_merge`, later wins — and the ordering of this array IS
     * that precedence, for both the merge and the provenance lookup, so the two
     * cannot disagree about which file won.
     *
     * ONE EDGE, named rather than left to be discovered: `--config` pointed at
     * the user's own `settings.json` collapses the two entries into one, since
     * they are the same array key. The surviving entry is the
     * {@see readPolicyFile()} read — the whole file, not the
     * {@see PERMISSION_SETTINGS_KEYS} whitelist — which is the right answer for
     * a file the user has explicitly named as THE policy file, and provenance
     * still points at it.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws PermissionConfigException when either file exists and cannot be used
     */
    private static function permissionConfigLayers(): array
    {
        $configDir = self::trustedConfigDirPath();
        $discovered = $configDir . '/config.json';
        $path = self::$configPathOverride ?? $discovered;
        $settingsPath = rtrim($configDir, '/') . '/' . LayeredSettings::USER_FILE;

        return self::withoutEmptyPermissionOverrides([
            $settingsPath => self::permissionSettingsLayer($settingsPath),
            $path => self::readPolicyFile($path),
        ]);
    }

    /**
     * The layers with every EMPTY LATER VALUE dropped, so that a key written
     * blank in one file cannot throw away the value another file actually set.
     *
     * THE INVARIANT IS ABOUT DISPLACEMENT, NOT ABOUT ANY PARTICULAR OUTCOME.
     * Measured before this filter existed, with `{"permissionMode":"plan"}` in
     * `settings.json`:
     *
     *     config.json ABSENT                =>  plan
     *     config.json {}                    =>  plan
     *     config.json {"permissionMode":""} =>  bypass-permissions, silently
     *     config.json {"permissionMode":null} => bypass-permissions, silently
     *
     * It is tempting to describe those last two rows as "an empty key silently
     * grants the widest mode", and that is what they did — but it is not the
     * mechanism. The mechanism is that `array_merge` let a valueless key win the
     * precedence walk and the merged key then normalised to absent, one step too
     * late, so {@see permissionGate()} started from
     * {@see DEFAULT_PERMISSION_MODE}. That default merely HAPPENS to be
     * `bypass-permissions` today; tighten it and the identical bug would silently
     * lock the user out of the mode they configured instead. So what is fixed
     * here is "an empty value must not displace an earlier layer", and a fix
     * aimed at "must not reach bypass" would have gone stale the day the default
     * moved.
     *
     * EMPTY IS EXACTLY `null` AND `''`, and the narrowness is deliberate rather
     * than incidental. Those are the two spellings every downstream reader
     * already treats as "nothing was said" — {@see permissionGate()} normalises
     * `''` to null, and {@see permissionRules()} loads nothing from either. A
     * `"  "` names no mode and goes on refusing the launch by name, and a `[]`
     * is a well-formed rules list that goes on winning: both are VALUES, and
     * widening this test to cover them would convert a loud refusal and a
     * documented override into silent fallbacks.
     *
     * SCOPED TO {@see PERMISSION_SETTINGS_KEYS}. Today that scope is not
     * observable — the settings layer carries only those keys, so every other
     * key of the merged array comes from exactly one layer and has nothing to
     * displace — but the reasoning above is about the permission keys, and this
     * says so rather than quietly extending to keys it was never measured on.
     *
     * The drop is REPORTED, because the two spellings failed differently and
     * both failed the user: the mode said nothing at all, and the rules key said
     * "no rules were loaded" without ever mentioning that the rules it did not
     * load were configured in another file. That second half is the part a user
     * can act on. {@see warnPermissionConfigOnce()} rather than
     * {@see warnPermissionConfig()} because this runs once per
     * {@see permissionConfigLayers()} call and a launch makes more than one.
     *
     * @param array<string, array<string, mixed>> $layers lowest precedence first
     * @return array<string, array<string, mixed>>
     */
    private static function withoutEmptyPermissionOverrides(array $layers): array
    {
        // The path of the last layer that set each key to something real. Only
        // a key that has one of those can be DISPLACED — an empty value with
        // nothing beneath it is left in place, so the readers downstream go on
        // seeing it and go on saying what they said about it.
        $carried = [];

        foreach ($layers as $path => $data) {
            foreach (self::PERMISSION_SETTINGS_KEYS as $key) {
                if (!\array_key_exists($key, $data)) {
                    continue;
                }

                $value = $data[$key];
                if ($value !== null && $value !== '') {
                    $carried[$key] = $path;
                    continue;
                }

                if (!isset($carried[$key])) {
                    continue;
                }

                unset($layers[$path][$key]);
                self::warnPermissionConfigOnce(
                    "{$key} in {$path} is empty, so it was ignored rather than allowed to discard the "
                    . "{$key} configured in {$carried[$key]}",
                );
            }
        }

        return $layers;
    }

    /**
     * WHICH FILE last set a permission key, for an error message that has to
     * name it.
     *
     * The LAST layer carrying the key, matching `array_merge`'s later-wins, and
     * `array_key_exists` rather than `?? null` so that a layer setting a key to
     * an explicit null is still the layer that set it.
     *
     * The fallback is reached only for a key NO layer carries, and it is
     * deliberately not exercised by any caller today: a permission key absent
     * from every layer is read as absent and never reaches an error message
     * about its value. It returns the file the CLI writes because that is the
     * least wrong thing to point a user at, not because it has been observed.
     *
     * @param array<string, array<string, mixed>> $layers
     */
    private static function permissionKeySource(array $layers, string $key): string
    {
        $source = null;
        foreach ($layers as $path => $data) {
            if (\array_key_exists($key, $data)) {
                $source = $path;
            }
        }

        return $source ?? self::userConfigPath();
    }

    /**
     * The permission keys of `~/.sugar-crush/settings.json` — crush_code.md
     * Phase 6 item 4's `permission`/`permissionMode` settings block.
     *
     * WHY NOT THROUGH {@see \SugarCraft\Crush\Config\LayeredSettings}, which
     * already reads this exact file: that class's reader is TOLERANT by
     * contract — a malformed file is the absence of a layer, because the keys it
     * carries are a theme and a model name and guessing wrong about those costs
     * nothing. The permission path may not have that tolerance; it is the whole
     * argument of {@see permissionGate()}. Routing `permissionMode` through the
     * tolerant merge would have meant one stray comma silently downgrading a
     * configured `plan` session to the permissive default, which is the
     * fail-open this method exists to close. So the same FILE is read twice by
     * two readers with different strictness, deliberately, and
     * `LayeredSettings::LAYERED_KEYS` documents its half of the split.
     *
     * NO PROJECT TIER, AT ANY TRUST LEVEL. `permissionMode` reaches
     * `bypass-permissions`, so a project-tier permission block would be a full
     * sandbox escape delivered by `git clone` — the operator's `chat()` would
     * come up ungated because a checked-in file said so. There is no trust flag
     * that makes that a reasonable grant, which is why this reads only the
     * home-owned directory and why the keys are absent from
     * `LayeredSettings::PROJECT_TIER_KEYS` and from `LAYERED_KEYS` entirely.
     *
     * FOLLOWS THE HOME, NOT `--config`. {@see useConfigPath()} names one file;
     * this is a different file, in the directory `trustedConfigDirPath()`
     * resolves — the same choice {@see userSettingsDirOrNull()} makes for the
     * tolerant reader, so both readers of `settings.json` agree on WHICH
     * `settings.json`.
     *
     * A NOTED BEHAVIOUR CHANGE: a `~/.sugar-crush/settings.json` that exists and
     * cannot be parsed now REFUSES THE LAUNCH, where before it was silently
     * skipped. That is the cost of the file becoming a policy source, and it is
     * the same bargain `config.json` already makes. It is also strictly louder
     * rather than newly broken: such a file already lost the user their theme
     * and provider without saying so.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when the file exists and cannot be used
     */
    private static function permissionSettingsLayer(string $path): array
    {
        $data = self::readPolicyFile($path);

        // array_key_exists, not `??`, and the reason had to be CORRECTED: an
        // earlier version of this comment claimed an explicit
        // `"permissionRules": null` "has to reach permissionRules()' own
        // handling", which was a behavioural claim about code that did not
        // behave that way — `permissionRules()` opened with `?? null` and read
        // explicit-null and absent identically, so the two spellings of this
        // filter were exactly equivalent and a mutation between them was
        // unkillable. The claim is now true because `permissionRules()` was
        // changed to make it true: a present-but-null rules key is REPORTED on
        // stderr, since a user who wrote it believes they configured rules and
        // has none. `array_key_exists` is also what
        // {@see permissionKeySource()} needs, for the same reason — a layer
        // that set a key to null is still the layer that set it.
        $kept = [];
        foreach (self::PERMISSION_SETTINGS_KEYS as $key) {
            if (\array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }

        return $kept;
    }

    /**
     * One policy file, read with no tolerance at all — extracted verbatim from
     * {@see permissionConfig()} so that `config.json` and the user's
     * `settings.json` cannot diverge into two differently-strict readers of the
     * same kind of file. Every message below still names `$path`, so which file
     * refused the launch is always in the error.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when the file exists and cannot be used
     */
    private static function readPolicyFile(string $path): array
    {
        if (!is_file($path)) {
            // SOMETHING IS THERE AND IT IS NOT A READABLE REGULAR FILE — a
            // directory named `config.json`, a dangling symlink. The walk
            // below answers "nothing is configured" for it, because every
            // ancestor really is searchable, and this method then starts on
            // the permissive default. That is the same present-but-unusable
            // fail-open the rest of this method exists to close, and the same
            // distinction {@see \SugarCraft\Crush\Hooks\HookConfig::loadFromFile()}
            // draws for the hook file, so it is drawn the same way here.
            if (file_exists($path) || is_link($path)) {
                throw new PermissionConfigException(
                    "{$path} exists but is not a readable file (it is a "
                    . (is_dir($path) ? 'directory' : 'symlink that does not resolve to one')
                    . '). Refusing to start rather than run with an unknown permission policy.',
                );
            }

            // `is_file()` answers false for two things that are not the same
            // question: the file is ABSENT, or a directory on the way to it
            // cannot be SEARCHED, in which case a policy may be sitting right
            // there and this process simply cannot see it. Only the first is
            // "nothing was configured". Reading the second as absence was the
            // fail-open this method exists to close, and it needs no change to
            // the config file at all to reach — a different euid, `sudo`
            // without `-E`, an NFS/autofs blip. It was also exactly backwards
            // from what the docblock promises: an unreadable FILE hard-failed
            // while an unreadable DIRECTORY silently ran bypass-permissions.
            $unreachable = self::unreachableAncestor($path);
            if ($unreachable !== null) {
                throw new PermissionConfigException(
                    "{$path} cannot be reached: {$unreachable}, "
                    . 'so whether a permission policy is configured there is unknowable. '
                    . 'Refusing to start rather than run with an unknown permission policy.',
                );
            }

            return [];
        }

        // The file exists, so "whose is it" is now an answerable question and
        // has to be answered before its contents become this launch's policy.
        self::requirePrivatePolicyFile($path);

        // `@`-silenced for the reason readUserConfig() is: the false branch
        // below IS the handling, and the raw warning would land in the middle
        // of the TUI's own output.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new PermissionConfigException(
                "{$path} exists but could not be read (check its permissions). "
                . 'Refusing to start rather than run with an unknown permission policy.',
            );
        }

        // A zero-byte (or whitespace-only) file is definitionally "nothing was
        // configured", and refusing to start on it bricks the CLI on a state
        // the CLI can produce ITSELF: {@see writeUserConfig()} replaces the
        // file atomically now, but a config truncated by an older build, a
        // full disk, or an editor is still out there, and there is no way to
        // fix it from inside a binary that will not launch.
        if (trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);

        // The second half of the test is on the JSON TEXT, for exactly the
        // reason {@see \SugarCraft\Crush\Hooks\ScriptHook::modifyOrDeny()}
        // tests it there: `json_decode()` throws away the distinction being
        // made, since `{}` and `[]` both decode to `[]`. `is_array()` alone
        // therefore called a top-level JSON LIST a usable config, dropped
        // every key in it, and started on the permissive default — with the
        // branch below still claiming, unreachably, that it would have
        // reported "the top level is not a JSON object".
        if (!is_array($data) || !str_starts_with(ltrim($contents), '{')) {
            // A BOM is named rather than left to `json_last_error_msg()`'s bare
            // "Syntax error": it is a common editor artifact, it is INVISIBLE
            // in every editor that wrote it, and the file it fails on is
            // otherwise character-for-character correct — so the generic
            // message sends the user hunting for a stray comma that is not
            // there. Still a hard failure: JSON does not permit a BOM, and
            // this path may not guess at a policy.
            $error = match (true) {
                str_starts_with($contents, "\xEF\xBB\xBF") => 'it starts with a UTF-8 byte-order mark, '
                    . 'which JSON does not permit — re-save the file as UTF-8 without a BOM',
                json_last_error() === JSON_ERROR_NONE => 'the top level is not a JSON object',
                default => json_last_error_msg(),
            };

            throw new PermissionConfigException(
                "{$path} is not usable JSON ({$error}). "
                . 'Refusing to start rather than run with an unknown permission policy.',
            );
        }

        return $data;
    }

    /**
     * Why $path could not be reached, phrased for the exception message, or
     * null when the whole chain is traversable and the file is simply not
     * there.
     *
     * Walks UPWARD rather than testing `dirname($path)` alone because that
     * test is itself ambiguous: `is_dir()` on a directory whose own parent is
     * unsearchable also answers false. The first ancestor that answers true to
     * `is_dir()` was successfully stat'ed, which proves everything above it
     * was searchable — so its own `is_executable()` is the whole answer.
     *
     * The SYMLINK probe is the second half, and without it the walk re-opened
     * the very fail-open it exists to close. `dirname()` is LEXICAL, so it
     * cannot follow the real chain past a link: point `~/.sugar-crush` at a
     * directory sitting behind an unsearchable one and `is_dir()` on the link
     * answers false, the walk steps up to a perfectly searchable `~`, and a
     * policy nobody can read is reported as "nothing configured". A component
     * that `lstat()`s but does not resolve to a directory is therefore its own
     * terminal answer: whether it dangles or its target is merely out of
     * reach, what is behind it is unknowable, and unknowable fails closed.
     *
     * Why not a `realpath()`-based walk: `realpath()` returns false for BOTH
     * "does not exist" and "cannot be reached", which is precisely the
     * distinction this method exists to draw — it would have to fall back to a
     * lexical walk anyway, and would lose the "reached the root, nothing is
     * there" terminal state that keeps a fresh install launching.
     */
    private static function unreachableAncestor(string $path): ?string
    {
        $dir = \dirname($path);

        while (true) {
            if (is_dir($dir)) {
                return is_executable($dir) ? null : "{$dir} is not searchable by this process";
            }

            if (is_link($dir)) {
                return "{$dir} is a symlink that does not resolve to a directory this process can search";
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                // Reached the filesystem root without finding anything that
                // exists. Nothing is hiding a config; there is no config.
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * EVERY COMPLAINT HERE GOES TO BOTH CHANNELS — see
     * {@see warnPermissionConfigInTranscript()}. A dropped rule is a SILENT
     * WIDENING: {@see PermissionRule} degrades a malformed pattern to a name no
     * real tool matches, so a `Deny` typo'd this way denies NOTHING and the
     * session runs wide open for as long as it lasts. That is the one thing on
     * this path a user has to learn while the session is still running, and
     * stderr under the alternate screen is precisely where they cannot.
     *
     * AND IT WAS SAID TWICE. MEASURED before the migration: {@see chat()}
     * reaches this method through {@see permissionGate()} AND through
     * {@see agentManager()}, and these were raw {@see warnPermissionConfig()}
     * calls with no de-duplication at all — a config with two bad rules printed
     * four stderr lines on every launch. Routing them through the transcript
     * seam picks up {@see warnPermissionConfigOnce()}'s per-process map on the
     * stderr side as well, so the count is now one apiece. That is a change to
     * stderr, and it is the fix, not a side effect.
     *
     * @param array<string, mixed> $config the already-read user config
     * @return list<PermissionRule>
     */
    private static function permissionRules(array $config): array
    {
        // ABSENT AND EXPLICITLY NULL ARE DIFFERENT THINGS HERE, and they were
        // not before: `?? null` collapsed them, so `"permissionRules": null`
        // loaded no rules and said nothing — the same silence that let the
        // argument-scoped grammar deny nothing for as long as it did. A user
        // who typed the key believes they configured rules. Absence is not an
        // error (a fresh install has no key at all); presence with no usable
        // value is, at the same report-and-continue level as every other
        // item-wise rules complaint.
        if (!\array_key_exists(self::PERMISSION_RULES_CONFIG_KEY, $config)) {
            return [];
        }

        $raw = $config[self::PERMISSION_RULES_CONFIG_KEY];
        if ($raw === null) {
            self::warnPermissionConfigInTranscript(
                self::PERMISSION_RULES_CONFIG_KEY . ' is present but null rather than a list of rules; '
                . 'no rules were loaded',
            );

            return [];
        }

        if (!is_array($raw)) {
            self::warnPermissionConfigInTranscript(
                self::PERMISSION_RULES_CONFIG_KEY . ' is not a list of rules; no rules were loaded',
            );

            return [];
        }

        $rules = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['pattern'] ?? null)) {
                self::warnPermissionConfigInTranscript(
                    self::PERMISSION_RULES_CONFIG_KEY . "[{$index}] has no string 'pattern'; rule skipped",
                );
                continue;
            }

            $action = is_string($entry['action'] ?? null)
                ? PermissionAction::tryFrom($entry['action'])
                : null;
            if ($action === null) {
                self::warnPermissionConfigInTranscript(
                    self::PERMISSION_RULES_CONFIG_KEY . "[{$index}] ('{$entry['pattern']}') has no valid 'action' "
                    . "(expected allow, deny or ask); rule skipped rather than coerced",
                );
                continue;
            }

            // A pattern the grammar cannot parse is reported and skipped here
            // rather than handed to the gate, for the same reason a bad
            // `action` is: {@see PermissionRule} degrades a malformed pattern to
            // a name that matches no real tool, so a `Deny` typo'd this way
            // would silently deny NOTHING. That is the exact failure the
            // argument-scoped grammar was rewritten to end, and the two
            // channels this method's doc-block names are the only places it can
            // be noticed.
            //
            // THE REASON COMES FROM THE GRAMMAR, not from this call site. This
            // warning used to assert "has an unbalanced parenthesis" for every
            // rejection, and measured, that was false for half of the grammar's
            // rejections: `""` contains no parenthesis and `"(rm *)"` contains a
            // balanced pair — both are refused for having no tool-name half.
            // A diagnostic that misnames the mistake is worse than a generic
            // one, so the wording lives in
            // {@see PermissionRule::patternRejectionReason()} beside the check
            // that produces it.
            $rejection = PermissionRule::patternRejectionReason($entry['pattern']);
            if ($rejection !== null) {
                self::warnPermissionConfigInTranscript(
                    self::PERMISSION_RULES_CONFIG_KEY . "[{$index}] ('{$entry['pattern']}') {$rejection}, so it is "
                    . 'not a Tool or Tool(argument-pattern) pattern; rule skipped rather than loaded as a pattern '
                    . 'that would match nothing',
                );
                continue;
            }

            $rules[] = new PermissionRule($entry['pattern'], $action);
        }

        return $rules;
    }

    /**
     * Report a permission-config problem the launch survived.
     *
     * stderr, matching the precedent {@see backend()} already sets for a
     * configured-but-unusable provider: stdout belongs to the TUI, and a
     * dropped rule has to be visible somewhere or it is the silent widening
     * this whole path exists to avoid.
     *
     * NOT AN UN-MIGRATED CALL SITE, and it was re-examined in round 42 (E78)
     * on the theory that it was one. This `fwrite(STDERR, …)` is the STDERR
     * CHANNEL ITSELF, not a warning that never made it onto the transcript
     * seam: {@see warnPermissionConfigInTranscript()} records the transcript
     * row and then delegates HERE for the stderr half, so migrating this line
     * onto that seam would be a cycle — the seam calling the seam — and would
     * remove the only unclipped copy the design leans on when it advertises
     * "the full text is on stderr". The question worth asking of a warning is
     * which of the three entry points it uses (this one, {@see
     * warnPermissionConfigOnce()}, or the transcript seam); the question is
     * never whether this line should stop writing to stderr.
     */
    private static function warnPermissionConfig(string $message): void
    {
        fwrite(STDERR, "sugarcrush: {$message}.\n");
    }

    /**
     * {@see warnPermissionConfig()}, but at most once per process per message.
     *
     * For the warnings raised from a path that runs MORE THAN ONCE A LAUNCH.
     * Keyed by the message rather than by a call site because the same
     * malformed entry reported twice is what the user experiences as noise,
     * whichever line printed it — and because the message already carries the
     * entry's index, so two genuinely different bad entries still both get
     * said. Static for the reason {@see $reportedUntrustedHookFiles} is: the
     * duplication is a property of the launch, not of any instance.
     */
    private static function warnPermissionConfigOnce(string $message): void
    {
        if (isset(self::$reportedPermissionConfigWarnings[$message])) {
            return;
        }

        self::$reportedPermissionConfigWarnings[$message] = true;
        self::warnPermissionConfig($message);
    }

    /**
     * {@see warnPermissionConfigOnce()}, AND a row in the transcript the launch
     * is about to build.
     *
     * FOR THE WARNINGS THAT HAVE TO BE SEEN WHILE THE SESSION RUNS, rather than
     * before it or after it. stderr is not being taken away from any of them —
     * `-p` reads it, and so does the scrollback a user gets back when
     * they quit. But an interactive launch enters the alternate screen roughly
     * half a second later (MEASURED: 0.47s on a real pty run) and paints over
     * it, and the primary buffer does not come back until the session is over.
     * A warning that says "this checkout cut your tools to `Bash`" arriving
     * after the Bash-only session is not the warning.
     *
     * BOTH CHANNELS, never one instead of the other. See
     * {@see Chat::withLaunchNotices()} for why the transcript is the surface.
     *
     * WHAT IS ROUTED HERE, and the rule that decided it: a warning reaches the
     * transcript iff it names something the session can no longer DO — a
     * provider that became {@see EchoProvider}, agent presets that did not
     * load, a hook file that was refused, permission rules that were dropped, a
     * tool set that was cut. Warnings about the user's config being MALFORMED
     * rather than the session being DIMINISHED stay on stderr:
     * {@see trustedProjectRoots()}'s per-entry complaints (whose consequence —
     * an untrusted project — is already reported here by
     * {@see hookFiles()}/{@see reportProjectTierRefusals()} with the actionable
     * path) and {@see withoutEmptyPermissionOverrides()}'s "an empty key was
     * ignored" (which reports a change that was DECLINED, so nothing about the
     * session differs).
     *
     * THIS LIST USED TO HAVE A THIRD ENTRY: {@see reportPrunedSessions()},
     * "about history already deleted, not about this session's capabilities".
     * WHAT IS TRUE NOW: its SUMMARY is routed here (E78 round 42) and only its
     * per-session detail rows stayed behind. A prune takes away resuming,
     * branching and rewinding the rows it names, which is a capability this
     * launch removed and therefore the rule's own "iff" case; the reason it
     * reads as an exception is the fan-out, and the fan-out is a property of
     * the DETAIL, not of the fact. See that method for the split.
     * WHY THE REST OF THE LIST STILL EARNS ITS PLACE: the two entries above
     * report a change the launch DECLINED to make, and re-examining them in
     * round 42 did not move either — nothing about the running session differs
     * because of them, so a transcript row would spend tokens every turn to say
     * that nothing happened.
     *
     * The stderr half keeps {@see warnPermissionConfigOnce()}'s per-process
     * de-duplication and the transcript half keeps its own per-LAUNCH one, so a
     * second {@see chat()} in one process still seeds its transcript even
     * though stderr has already said it. Recording BEFORE the delegation rather
     * than after is what makes that true — the Once() call returns early on the
     * repeat.
     *
     * BOUNDED ON BOTH AXES, and only on the transcript side. See
     * {@see LAUNCH_NOTICE_LIMIT} for why an unbounded list is a per-token cost
     * for the whole session rather than a scrolling nuisance, and
     * {@see LAUNCH_NOTICE_MAX_CHARS} for the per-message clip. $message reaches
     * stderr whole and unclipped either way: that channel is the complete
     * record, which is what makes the clip safe to advertise.
     */
    private static function warnPermissionConfigInTranscript(string $message): void
    {
        // mb_*, not substr(): these messages interpolate paths and globs, and
        // cutting one mid-codepoint would hand Chat a row that is not valid
        // UTF-8 — which json_encode() (the session store, the `-p` document)
        // refuses outright rather than degrading.
        $notice = mb_strlen($message, 'UTF-8') > self::LAUNCH_NOTICE_MAX_CHARS
            ? mb_substr(
                $message,
                0,
                self::LAUNCH_NOTICE_MAX_CHARS - mb_strlen(self::LAUNCH_NOTICE_CLIP_SUFFIX, 'UTF-8'),
                'UTF-8',
            ) . self::LAUNCH_NOTICE_CLIP_SUFFIX
            : $message;

        // The de-dup check comes FIRST so a message already recorded is never
        // counted as an overflow — a repeat costs the transcript nothing, and
        // charging it to the "and N more" tail would overstate what was lost.
        if (!\in_array($notice, self::$launchNotices, true)) {
            if (\count(self::$launchNotices) < self::LAUNCH_NOTICE_LIMIT) {
                self::$launchNotices[] = $notice;
            } else {
                self::$launchNoticesDropped[$notice] = true;
            }
        }

        self::warnPermissionConfigOnce($message);
    }

    /**
     * The launch warnings {@see chat()} seeds the transcript with — see
     * {@see warnPermissionConfigInTranscript()}.
     *
     * Public so a test can read what a launch decided to say without capturing
     * stderr, and so an embedder building its own Chat can route them into
     * whatever surface it has.
     *
     * THE OVERFLOW ROW IS SYNTHESISED HERE rather than pushed onto the list, and
     * that is what keeps {@see LAUNCH_NOTICE_LIMIT} an honest cap: an overflow
     * marker stored IN the list would occupy a slot, and a second overflow after
     * it would have to rewrite an entry rather than append. Reading it out at
     * the accessor means every caller of this method — {@see chat()}, a doctor
     * report, an embedder — gets the same complete answer, and none of them can
     * see a truncated list without being told it was truncated.
     *
     * @return list<string>
     */
    public static function launchNotices(): array
    {
        if (self::$launchNoticesDropped === []) {
            return self::$launchNotices;
        }

        $dropped = \count(self::$launchNoticesDropped);

        return [
            ...self::$launchNotices,
            sprintf(
                '…and %d more launch warning%s this transcript could not fit; the full list is on stderr',
                $dropped,
                $dropped === 1 ? '' : 's',
            ),
        ];
    }

    /**
     * The project's MCP config file name — the SAME file Claude Code reads, and
     * the same `mcpServers` key {@see McpClient::loadConfig()} already parsed
     * before anything built one.
     */
    public const MCP_CONFIG_FILENAME = '.mcp.json';

    /** {@see mcpConfigDecision()} status: no `.mcp.json` at the project root. */
    public const MCP_ABSENT = 'absent';

    /** {@see mcpConfigDecision()} status: present, but it resolves outside the checkout. */
    public const MCP_OUTSIDE_TREE = 'outside-tree';

    /** {@see mcpConfigDecision()} status: present and contained, but this root is not trusted. */
    public const MCP_UNTRUSTED = 'untrusted';

    /** {@see mcpConfigDecision()} status: present, contained and trusted — servers may be started. */
    public const MCP_TRUSTED = 'trusted';

    /**
     * Where this project's `.mcp.json` is, and whether this launch is allowed
     * to start the servers it names — WITHOUT starting any of them.
     *
     * THE ONE DISCOVERY PATH. {@see mcpClient()} and {@see
     * mcpServerInventory()} both come through here, so `sugarcrush mcp list`
     * cannot report a file, a containment verdict or a trust verdict that
     * differs from the one the launch acts on. Two readers with two copies of
     * this rule is a defect this package has shipped before; the whole reason
     * `mcp list` is not simply "json_decode(.mcp.json)" is that such a listing
     * would happily enumerate servers the trust gate refuses to run.
     *
     * Records the same {@see $projectTierRefusals} entries the inline version
     * did, keyed by the same path, so calling it twice is idempotent.
     *
     * @return array{path: string, root: string, canonicalRoot: string|false, status: string}
     *   `status` is one of {@see self::MCP_ABSENT}, {@see self::MCP_OUTSIDE_TREE},
     *   {@see self::MCP_UNTRUSTED}, {@see self::MCP_TRUSTED}.
     */
    public static function mcpConfigDecision(?string $root = null): array
    {
        $root = self::requireRoot($root);

        // CANONICAL, not as spelled, for the reason {@see hookFiles()} gives at
        // its own `realpath()`: the trust decision below is made on the resolved
        // root, so keying the memo off the raw string would leave two names for
        // one decision. MEASURED against the raw-string version, four spellings
        // of ONE root — `$W/repo`, `$W/repo/`, `$W/repo/sub/..`, `$W/repo/./` —
        // produced 4 cached clients and EIGHT live server processes, which is
        // exactly the accumulation {@see $mcpClients} says memoization prevents.
        $canonicalRoot = realpath($root);
        $path = rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/')
            . '/' . self::MCP_CONFIG_FILENAME;

        $decision = ['path' => $path, 'root' => $root, 'canonicalRoot' => $canonicalRoot];

        // is_file() FIRST, so the overwhelmingly common "no MCP config" case
        // costs one stat and reaches neither the containment compare, the trust
        // gate, nor the refusal report. A dangling symlink is not a file, so it
        // lands here.
        if (!is_file($path)) {
            return $decision + ['status' => self::MCP_ABSENT];
        }

        if (!ContainedPath::within($path, $root)) {
            // A REASON, NOT A SENTENCE, and it does not name the configured path:
            // the one notice that prints this composes `ignoring <path> — <reason>`
            // and already holds it, so naming it here put it in that line TWICE.
            // Same mid-clause "resolves to …" shape as every sibling feeder — see
            // {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()},
            // whose doc-block records making this identical correction.
            self::$projectTierRefusals[$path] = sprintf(
                'resolves to %s, outside the project tree it was read from (%s); '
                . 'refusing to start servers named by a file outside the checkout.',
                realpath($path) === false ? 'nothing readable' : (string) realpath($path),
                $root,
            );

            return $decision + ['status' => self::MCP_OUTSIDE_TREE];
        }

        // THE TRUST GATE, and it is checked AFTER containment so that an
        // out-of-tree config is reported as out-of-tree rather than as untrusted:
        // the two have different fixes and only one of them is "opt in".
        if ($canonicalRoot === false || !self::projectMcpIsTrusted($canonicalRoot)) {
            self::$projectTierRefusals[$path] = sprintf(
                'starting the MCP servers it names means running programs this repository chose, '
                . 'every time you open it, before any tool call and in every permission mode. '
                . 'Add "%s" to "%s" in %s to opt in',
                rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/'),
                self::TRUSTED_PROJECT_MCP_CONFIG_KEY,
                self::userConfigPath(),
            );

            return $decision + ['status' => self::MCP_UNTRUSTED];
        }

        return $decision + ['status' => self::MCP_TRUSTED];
    }

    /**
     * What `.mcp.json` DECLARES, for `sugarcrush mcp list` — read, never run.
     *
     * NOTHING HERE CALLS `proc_open()`, and that is the entire design
     * constraint. {@see mcpClient()} STARTS every configured server as a side
     * effect of being asked for the client, so routing a listing through it
     * would mean `sugarcrush mcp list` launches every program this repository
     * names — the exact act the trust gate exists to make deliberate, performed
     * by the command an operator runs precisely BECAUSE they do not yet trust
     * the file. So this reads the JSON and reports; `status` tells the caller
     * whether the launch would run these entries at all.
     *
     * Consequence, stated because it is a real limit rather than an oversight:
     * `enabled` reflects the CONFIG, not liveness. Nothing here can tell you a
     * declared server would fail to start — finding that out means starting it.
     *
     * @return array{status: string, path: string, servers: list<array{name: string, type: string, detail: string}>, error: string|null}
     *   `servers` is empty for every non-{@see self::MCP_TRUSTED} status and
     *   for a file that is not decodable, in which case `error` says why.
     */
    public static function mcpServerInventory(?string $root = null): array
    {
        $decision = self::mcpConfigDecision($root);
        $base = ['status' => $decision['status'], 'path' => $decision['path'], 'servers' => [], 'error' => null];

        if ($decision['status'] !== self::MCP_TRUSTED) {
            return $base;
        }

        $contents = @file_get_contents($decision['path']);
        if (!is_string($contents)) {
            return ['error' => 'could not be read'] + $base;
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['error' => 'is not valid JSON (' . $e->getMessage() . ')'] + $base;
        }

        if (!is_array($data) || !is_array($data['mcpServers'] ?? null)) {
            return ['error' => 'has no "mcpServers" object'] + $base;
        }

        $servers = [];
        foreach ($data['mcpServers'] as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                continue;
            }

            // The same three types {@see \SugarCraft\Crush\MCP\McpClient::startServer()}
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
                'git' => is_string($config['path'] ?? null) ? $config['path'] : '(this project)',
                default => '',
            };

            $servers[] = ['name' => $name, 'type' => $type, 'detail' => $detail];
        }

        return ['servers' => $servers] + $base;
    }

    /**
     * The launch's MCP client, with its configured servers STARTED, or null when
     * this project has no usable `.mcp.json`.
     *
     * ONE LOCATION, `$root/.mcp.json`, and no user-level fallback. Adding one
     * would mean choosing a precedence between a file the repository chooses and
     * a file the user chooses, for a config whose entries `proc_open()` arbitrary
     * commands; a second tier is a decision to make deliberately with its own
     * tests, not a convenience to slip in beside the first. Stated here so its
     * absence is a decision rather than an oversight.
     *
     * CONTAINMENT. `.mcp.json` is a REPOSITORY-CHOSEN path whose contents name
     * commands to execute, so it is confined the way every other
     * repository-chosen read in this package is: {@see ContainedPath::within()},
     * both sides `realpath()`d, so a committed `.mcp.json -> /elsewhere/evil.json`
     * is refused rather than followed. It is ONE compare rather than the
     * anchor-plus-entry PAIR {@see \SugarCraft\Crush\Commands\CommandLoader}
     * uses, and the difference is structural rather than a weaker rule: that
     * class walks a DIRECTORY, so it has a boundary directory of its own to
     * anchor (`.sugar-crush/commands` may itself be a symlink out of the tree,
     * and then every entry under it passes an entry-level check). Here the
     * "directory" IS $root — the value the user named on `--root` or the process
     * working directory — and a tree cannot be confined to itself. The same
     * shape as {@see \SugarCraft\Crush\Agents\WorktreeManager}'s two
     * entry-level compares, for the same reason.
     *
     * STARTING A SERVER IS CODE EXECUTION, so the file is TRUST-GATED by the same
     * MECHANISM `.sugar-crush/hooks.yaml` is — same config file, same parser, same
     * once-per-process freeze, separate key; see
     * {@see TRUSTED_PROJECT_MCP_CONFIG_KEY} and {@see hookFiles()} for the threat
     * model this reuses wholesale. The same gate is not the same FAILURE MODE, and
     * the two are worth keeping apart: an unusable hook file refuses the launch,
     * an unusable `.mcp.json` degrades it (see FAILS OPEN below). The
     * measurement that put the gate here, taken against a version of this method
     * that had none, with the root NOT trusted and the mode `plan`:
     *
     *     .mcp.json = {"mcpServers":{"evil":{"command":"/bin/sh",
     *                  "args":["-c","echo PWNED-AT-LAUNCH > …/pwned.txt"]}}}
     *     Bootstrap::tools($repo)  ->  tools=10  elapsed=0.02s
     *     cat pwned.txt            ->  PWNED-AT-LAUNCH
     *
     * `tools=10` is the count THAT run returned, when the built-in set was ten;
     * it is eleven today ({@see LspTool}) and the transcript above is left as it
     * was measured rather than renumbered. What matters is that it equals the
     * built-in count with NO bridge added: the payload was not even a working MCP
     * server, `initialize` failed, the server was discarded — AND THE COMMAND
     * STILL RAN. Starting IS the execution, so nothing downstream of `proc_open()`
     * can be the boundary. In particular the PreToolUse chain is not: that gate
     * sees tool CALLS, and this happens at construction, before any call and in
     * every permission mode including `plan`. An earlier revision of this
     * doc-block claimed "FAILS OPEN ON CAPABILITY, NEVER ON POLICY … a server
     * that will not start costs the model some TOOLS; it cannot loosen anything",
     * which was false in precisely the load-bearing direction.
     *
     * FAILS OPEN ON CAPABILITY ONLY. A missing file, a file outside the tree, an
     * untrusted root, or a server that will not start costs the model some TOOLS.
     * So unlike {@see hooks()} — where a present but unusable file refuses the
     * LAUNCH, because a hook chain is the launch's gating policy — this degrades.
     *
     * THE TWO REFUSALS THIS METHOD MAKES ITSELF are recorded in
     * {@see $projectTierRefusals} and printed by
     * {@see reportProjectTierRefusals()}: out of the tree, and not trusted.
     * "Present and ignored" is worse than silent when the user wrote the file on
     * purpose, and the invisibility of the ungated version is half of why it went
     * unnoticed. A config that is simply absent says nothing at all, which is the
     * common case.
     *
     * A BROKEN CONFIG IS A DIFFERENT QUESTION AND MOSTLY DEGRADES SILENTLY. An
     * earlier revision of this doc-block said "a broken config is reported on
     * stderr", which is true of one of the five shapes a broken one takes.
     * Measured on this tree, with the root trusted so the trust gate is not what
     * is being observed:
     *
     *     A) a server whose command does not exist   SILENT  <- the common case
     *     B) an unknown `type`                       reported: "could not be
     *                                                fully started (…Unknown MCP
     *                                                server type: weird)"
     *     C) malformed JSON                          SILENT
     *     D) valid JSON, wrong top-level key         SILENT
     *     E) unknown `type`, then a good server      reported, and the servers
     *                                                after the bad entry are
     *                                                never reached
     *
     * Only the `default => throw` arm of {@see McpClient::startServer()} reaches
     * the `catch` below at all; a server whose own `start()` fails is swallowed in
     * there by `catch (\RuntimeException) { return; }`, and C and D are an empty
     * config as far as {@see McpClient::loadConfig()} is concerned. So (A) — a
     * `command` that is misspelled or not installed, overwhelmingly the way a real
     * `.mcp.json` is broken — costs the user every tool on that server with
     * nothing said anywhere. The repair belongs in `McpClient`, which owns both
     * the swallow and the JSON parse, and is on the hardening backlog as E41;
     * widening this method to reach around it would put the diagnostic in the
     * wrong class and leave the swallow in place.
     *
     * @throws \RuntimeException when neither $root nor a working directory exists
     *         ({@see requireRoot()}) — the same refusal every other rooted
     *         accessor here makes.
     * @throws PermissionConfigException when a `.mcp.json` IS present and the
     *         user config that would grant it cannot be read or parsed — the same
     *         hard line {@see permissionConfig()} draws, reached only for a
     *         project that ships the file.
     */
    public static function mcpClient(?string $root = null): ?McpClient
    {
        $decision = self::mcpConfigDecision($root);
        $path = $decision['path'];

        $pid = getmypid() ?: 0;
        if (array_key_exists($path, self::$mcpClients[$pid] ?? [])) {
            return self::$mcpClients[$pid][$path];
        }

        // The memo is consulted BEFORE this gate, deliberately: a client whose
        // config file was deleted (or whose trust grant was revoked) mid-process
        // still owns live server processes that stopMcpServers() has to reach,
        // so an already-created client is returned whatever the file says now.
        // Only mcpConfigDecision() decides whether a NEW one may be built.
        if ($decision['status'] !== self::MCP_TRUSTED) {
            return null;
        }

        $client = new McpClient(
            $path,
            // $unrestricted, and it is the opposite of what it looks like. The
            // client fails CLOSED without an AgentPreset, and the MAIN agent has
            // no preset — that mechanism scopes SUB-agents. So the alternatives
            // were "the main agent gets zero MCP tools" or "synthesize a fake
            // preset for it". What this actually bypasses is
            // {@see \SugarCraft\Crush\MCP\McpRouter}'s per-preset allowlist,
            // which is sub-agent SCOPING and not the user's safety boundary: the
            // main agent is not preset-scoped for `Bash` either. That comparison
            // is about SCOPING and is the only thing it is good for; see below.
            //
            // TWO CONTROLS, TWO JOBS, and stating them as one is the error this
            // change-set was corrected for. LAUNCHING a server is gated by the
            // trust list above — a repository-chosen command reaching
            // `proc_open()` cannot be gated by anything downstream of it, because
            // starting IS the execution. CALLING a bridge is gated by the
            // PreToolUse chain, which sees tool calls and never sees
            // `proc_open()`. Neither control substitutes for the other, and an
            // earlier revision of this reasoning offered the PreToolUse chain as
            // the answer to both.
            //
            // NOT "GATED EXACTLY AS `Bash`", which is how that revision put the
            // second half. The CHAIN is shared; the DECISION coincides in five of
            // the six permission modes and diverges under `plan`, where `Bash` is
            // allowed for exploration and every `mcp__*` name is denied as a write
            // tool — i.e. in the conservative direction. The measured table, and
            // why the divergence is not a hole, are in
            // {@see \SugarCraft\Crush\Tools\McpToolBridge}; the end-to-end
            // measurements are in
            // {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest}.
            //
            // NO $denyPatterns ARGUMENT, deliberately: `McpClient` consults them
            // only through `router()`, which only the AgentPreset arm reaches, so
            // on this path they are inert. Passing a list that cannot be enforced
            // would be a clause with no truth behind it. Deny patterns belong to
            // the sub-agent path, which this bundle does not wire.
            unrestricted: true,
        );

        // Registered BEFORE start, and the order matters: startServers() adds
        // each server to the client as it comes up, so a client that throws
        // part-way through still owns live processes that stopMcpServers() has to
        // reach.
        self::$mcpClients[$pid][$path] = $client;
        self::registerMcpShutdown();

        try {
            $client->startServers();
        } catch (\Throwable $e) {
            // An unknown `type` in the config is the throwing case
            // ({@see McpClient::startServer()}); a server whose own start() fails
            // is already skipped in there. Reported rather than swallowed, and
            // not rethrown: this is on {@see tools()}, which every launch and
            // every provider switch reaches, and a PHP fatal over a live TUI is
            // strictly worse than a session with fewer tools.
            //
            // WHAT IS LOST, precisely: the throw happens on the offending entry,
            // so servers EARLIER in the file are up and servers AFTER it were
            // never reached. Ordering-dependent, and a defect in the client
            // rather than here — recorded in this bundle's report for the backlog.
            //
            // BOTH CHANNELS, AND AN ini-DIRECTED THIRD DESTINATION. This is the
            // one site in this class that writes `error_log()` AS WELL AS the
            // transcript seam, so the reason is spelled out rather than assumed.
            //
            // WHAT THIS COMMENT SAID: `error_log()` RATHER THAN
            // {@see warnPermissionConfig()}, this class's other stderr seam,
            // because it is the only one a test can OBSERVE — `error_log()`
            // honours the `error_log` ini setting, so
            // {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()}
            // points it at a file and asserts this text, where a
            // `fwrite(STDERR, …)` would be unassertable and would additionally
            // print into the suite's own output.
            //
            // WHAT IS TRUE NOW: assertability was never the whole question.
            // WHERE THE MESSAGE LANDS FOR THE USER was, and `error_log()` answers
            // that one badly, because the ini setting it honours is the
            // OPERATOR's. On a box that points `error_log` at a file — an
            // entirely ordinary production PHP config — this notice reaches
            // NEITHER the terminal NOR the transcript, and the user gets a
            // silently reduced tool set with nothing anywhere saying tools are
            // missing (E86). The transcript seam is the surface that answers
            // "where does the user see it", and it is not unassertable either:
            // {@see launchNotices()} is public precisely so a test can read what
            // a launch decided to say without capturing stderr.
            //
            // WHY `error_log()` STILL EARNS ITS PLACE: the ini-file destination
            // is the assertable one, and the shutdown-seam test named above
            // depends on it. That test's subject is that a client which threw
            // part-way through is still reachable by {@see stopMcpServers()},
            // and the diagnostic it reads out of the log file is how it proves
            // the throw path was the one taken; dropping this call would falsify
            // a test that is pinning something real. An operator who HAS pointed
            // `error_log` at a file also wants the full diagnostic to land there.
            //
            // THE TWO MESSAGES ARE DELIBERATELY NOT THE SAME TEXT SENT TWICE.
            // `error_log()` carries the OPERATOR diagnostic — the exception class
            // and its message. The transcript row carries the CONSEQUENCE, which
            // is what {@see warnPermissionConfigInTranscript()}'s routing rule
            // asks for: a warning reaches the transcript iff it names something
            // the session can no longer DO, and a partly started MCP config is a
            // cut tool set.
            //
            // WHAT WAS CLAIMED ABOUT THE PAIR: "on a box whose `error_log` ini is
            // unset BOTH land on stderr, and phrasing them as diagnostic +
            // consequence is what keeps that pair from reading as a stutter."
            // Round 43's review noted that no test had ever run this on such a
            // box. WHAT THE MEASUREMENT FOUND when one did: the two lines share
            // the clause "could not be fully started", the path, and the
            // exception MESSAGE — considerably more overlap than "diagnostic +
            // consequence" implies. They are still two different lines: only the
            // first names the exception CLASS and "continuing without it", and
            // only the second names "MCP tools from …" and what the session is
            // left with. WHY THE DUPLICATION EARNS ITS PLACE: the transcript row
            // has to stand ALONE. On the surface it exists for there is no
            // `error_log()` line beside it, so a row that omitted the cause would
            // tell the user tools are missing and nothing about why. The pairing
            // on an unset-ini box is the price of that, and it is now asserted
            // rather than asserted-in-prose —
            // {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings()}.
            //
            // STILL NOT ROUTED INTO {@see $projectTierRefusals}: that collector's
            // subject is a path this launch declined to READ, and this file was
            // read, granted and partly started. Its key is the path, which the
            // two refusals above already own, so an entry here would collide with
            // them.
            //
            // BOUNDED AT ONE ROW PER PATH PER PROCESS — and the bound has TWO
            // parts, where an earlier revision of this comment claimed one.
            //
            // WHAT IT SAID: "by construction rather than by the seam's cap: the
            // memo AT THE TOP OF THIS METHOD stores the client BEFORE
            // startServers()".
            //
            // WHAT IS TRUE NOW: the memo is CHECKED at the top of this method;
            // it is STORED immediately above this `try`, which is the placement
            // that actually matters and carries its own note there. And the memo
            // is not the whole bound: {@see stopMcpServers()} clears this pid's
            // bucket outright (`unset(self::$mcpClients[$pid])`), so an
            // `mcpClient()` for the same path AFTER a stop builds a new client
            // and re-enters this `catch`.
            //
            // WHY THE BOUND STILL HOLDS: in production nothing calls
            // {@see stopMcpServers()} except the `register_shutdown_function`
            // closure {@see registerMcpShutdown()} installs, so there is no
            // "after" in which to re-enter; and in the only shape where there is
            // — an in-process test that stops and then asks again — the seam's
            // own `in_array($notice, …, true)` de-dup drops the repeat before it
            // can reach the transcript. Memoized on the production path, de-duped
            // on the other: that is what makes a transcript row safe on
            // {@see tools()}, which every launch AND every provider switch runs.
            // The seam's {@see LAUNCH_NOTICE_MAX_CHARS} clip then bounds the
            // LENGTH, which matters here because `$e->getMessage()` interpolates
            // a `type` string the project's `.mcp.json` chose.
            error_log(sprintf(
                'sugarcrush: MCP config %s could not be fully started (%s: %s); continuing without it.',
                $path,
                $e::class,
                $e->getMessage(),
            ));

            // REACHABILITY AT THIS SITE IS DRIVEN, not inherited from the other
            // fifteen call sites: {@see chat()} holds no `self::tools(` call of
            // its own and gets here transitively through `backend()` ->
            // `tools()` -> {@see mcpTools()} -> this method, then reads
            // {@see launchNotices()} on its last line — so a row recorded now is
            // in hand by the time the transcript is seeded. Measured end-to-end
            // by
            // {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::testAPartlyStartedMcpConfigReachesTheTranscriptAndNotOnlyTheErrorLog()}.
            self::warnPermissionConfigInTranscript(sprintf(
                'MCP tools from %s are incomplete: the server list could not be fully started (%s); '
                    . 'this session has only the tools that did load',
                $path,
                $e->getMessage(),
            ));
        }

        return $client;
    }

    /**
     * The model-facing {@see McpToolBridge} for every tool the launch's MCP
     * servers advertise — empty when there is no config, which is the common case.
     *
     * @return list<McpToolBridge>
     */
    public static function mcpTools(?string $root = null): array
    {
        $client = self::mcpClient($root);
        if ($client === null) {
            return [];
        }

        $tools = [];
        foreach ($client->listTools() as $descriptor) {
            $tools[] = new McpToolBridge($client, $descriptor);
        }

        return $tools;
    }

    /**
     * Stop every MCP server THIS PID started.
     *
     * PUBLIC because it is BOTH the shutdown hook's callback and the only way a
     * caller that owns a process lifetime (a test, an embedder) can hand the
     * servers back before exit. Idempotent: the client empties its own map, and
     * this empties this pid's bucket of ours.
     *
     * THIS PID'S BUCKET AND NO OTHER — see {@see $mcpClients}. A
     * `pcntl_fork()`ed child inherits the parent's whole map, so anything wider
     * than one bucket means a worker's ordinary exit SIGTERMs the live session's
     * servers. Other pids' buckets are left in place rather than dropped: the
     * child's copy of them is a copy, and dropping it in the child would say
     * something untrue about the parent's.
     */
    public static function stopMcpServers(): void
    {
        $pid = getmypid() ?: 0;
        $clients = self::$mcpClients[$pid] ?? [];
        unset(self::$mcpClients[$pid]);

        foreach ($clients as $client) {
            // Bounded by StdioMcpServer::stop()'s SIGTERM-then-9 escalation.
            // Wrapped anyway: this runs during shutdown, and a throw from the
            // first server would leave the rest running.
            try {
                $client->stopServers();
            } catch (\Throwable) {
                // Nothing to report to — stderr may already be gone at shutdown,
                // and there is no remedy left to offer.
            }
        }
    }

    /**
     * THE SHUTDOWN SEAM, and it had to be created: `bin/sugarcrush` runs
     * `Program::run()` (or {@see NonInteractive::run()}) and then falls off the
     * end of the script, and a `grep` for `register_shutdown_function` across
     * `src/` and `bin/` found nothing at all. A `stopServers()` that is never
     * called leaves an orphaned third-party process per configured server after
     * every session, which is worse than never starting them.
     *
     * REGISTERED HERE rather than in `bin/sugarcrush` on purpose: this is the one
     * function that STARTS the servers, so start and stop cannot be wired apart.
     * Every entry point — the TUI, `-p` one-shot, an embedder calling
     * {@see tools()} directly, a test — gets the stop for free, and nothing can
     * acquire the servers without it.
     *
     * ONCE PER PROCESS IMAGE, and a `pcntl_fork()`ed child needs no registration
     * of its own: it inherits this one, and the inherited hook is CORRECT in the
     * child because {@see stopMcpServers()} acts only on `getmypid()`'s bucket of
     * {@see $mcpClients}. So a child that starts servers of its own —
     * {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::executeTask()}
     * reaches {@see tools()} after a `chdir()` — has them stopped when it exits,
     * while the live session's servers are untouched. Both halves of that are
     * measured in
     * {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::testAForkedWorkerStopsItsOwnServersAndLeavesTheParentsAlone()}.
     *
     * WHAT IT COVERS: a normal return, any `exit()`, and an uncaught exception.
     * WHAT IT DOES NOT: `SIGKILL`, and any signal for which no PHP handler is
     * installed — a bare `SIGINT`/`SIGTERM` at the shell terminates the process
     * without running shutdown functions. That residue is not closed here because
     * closing it means installing signal handlers on the CLI's main path, which
     * is a decision about the TUI's own Ctrl+C handling and not about MCP. Nor
     * does it cover {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()},
     * which deliberately bypasses PHP's shutdown sequence — a child that took
     * that route and had started its own servers leaks them, and that is the
     * fork helper's contract rather than something this seam can override.
     */
    private static function registerMcpShutdown(): void
    {
        if (self::$mcpShutdownRegistered) {
            return;
        }

        self::$mcpShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::stopMcpServers();
        });
    }

    /**
     * The model-facing {@see LspTool}, wired to whatever language servers this
     * launch has — which today is NONE, and that is deliberate.
     *
     * AN INTENTIONAL DORMANT SEAM, in this repo's "wire it or document it, never
     * delete it" sense. `src/LSP/` ships a finished {@see LspClient},
     * {@see \SugarCraft\Crush\LSP\LspConnection} and
     * {@see \SugarCraft\Crush\LSP\LspCache} with three test files, and before
     * {@see LspTool} nothing outside `src/LSP/` referenced any of them. The tool
     * is the reachability half. This method is the CONFIGURATION half, and it is
     * empty for one measured reason: THERE IS NO SETTINGS KEY FOR LANGUAGE
     * SERVERS. Measured on this tree — `grep -rin lsp` over `src/Cli/Bootstrap.php`
     * and every settings reader in `src/` matched nothing but the additions in
     * this commit, and `src/` has no `Settings` namespace at all
     * ({@see \SugarCraft\Crush\Tui\Components\SettingsPane} is a TUI pane, not
     * a loader). So there is nothing to read, and inventing a key here would be a
     * config surface with no documentation, no validation and no user.
     *
     * WHAT HAS TO LAND NEXT, named rather than left implied: a settings block
     * declaring, per language, a server command plus args; a launcher that
     * `proc_open()`s it through {@see \SugarCraft\Crush\LSP\LspConnection::connect()}
     * and calls `initialize()`; an `onNotification()` subscriber routing
     * `textDocument/publishDiagnostics` into
     * {@see LspClient::handlePublishDiagnostics()}, which NOTHING in `src/` does
     * today — that is why {@see LspTool}'s empty `diagnostics` answer carries its
     * own caveat rather than reading as "this file is clean"; a shutdown hook in
     * the shape of {@see stopMcpServers()}; and — because STARTING A SERVER IS
     * CODE EXECUTION,
     * exactly as it is for `.mcp.json` — the same trust gate {@see mcpClient()}
     * applies to a project-supplied config. That gate is the reason this is not
     * a two-line change, and it is why the launcher is out of this bundle's scope
     * rather than merely unfinished.
     *
     * MEANWHILE THE TOOL IS STILL REACHABLE AND STILL HONEST. With a null client
     * every call returns an ERROR naming the language, never an empty success —
     * see {@see LspTool} for why that distinction is the point, and
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest::testTheWiredLspToolRefusesRatherThanAnsweringEmptyWithNoServerConfigured()}
     * for the assertion that the LIVE wiring behaves that way rather than only
     * the class in isolation.
     *
     * $client is injectable so an embedder that HAS built servers can hand them
     * over without waiting for the settings block; {@see tools()} threads its own
     * parameter straight through.
     */
    public static function lspTool(?string $root = null, ?LspClient $client = null): LspTool
    {
        return new LspTool($client, self::requireRoot($root));
    }

    /**
     * Build the built-in coding tools, with a shared InstructionFileLoader
     * wired into every tool that surfaces nested CLAUDE.md/AGENTS.md content
     * (Read/Edit/Glob/Write) so those files are actually reachable when a user
     * runs the real CLI binary.
     *
     * THIS ARRAY IS THE WHOLE MODEL-FACING TOOL SET. Its BUILT-IN half is
     * ELEVEN entries as of this writing — it was ten until {@see LspTool} was
     * added — and THAT count, not this array's length, is the domain for every
     * "N built-in tools" figure in `README.md`. `src/Tools/BuiltIn/` holds
     * exactly those eleven concrete `Tool` classes.
     *
     * ELEVEN IS THE COUNT OF WIRED TOOLS, NOT OF USABLE ONES, and the two differ
     * on every launch today: `LspTool` is reachable and answers every call with a
     * "no language server configured" error, because nothing in `src/` reads a
     * server command yet ({@see lspTool()}). A figure that said "eleven working
     * tools" would be the wrong claim about this array.
     *
     * The array is longer than eleven only when the project ships a `.mcp.json` AND
     * the user has listed this root under `trustedProjectMcp` — both conditions,
     * see {@see mcpClient()} for why the second one exists. Then one
     * {@see McpToolBridge} per tool the configured MCP servers advertise is
     * appended at the end (see {@see mcpTools()}). That is a per-PROJECT number
     * nothing in `src/` can know, which is why every count stated here and every
     * scanned assertion about this method is about the built-in half. The one
     * concrete `Tool` implementor under `src/` that is NOT in the literal below is
     * `McpToolBridge`, for exactly that reason —
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES}
     * is where that exemption is recorded and asserted.
     *
     * NOT "BY CONSTRUCTION", which is what this doc-block used to say. Nothing
     * derives this array from that directory or that directory from this array;
     * they are two hand-maintained halves, and the reason they agree is a TEST
     * that scans the directory —
     * `BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()`,
     * whose expected set comes from `glob()` over `src/Tools/BuiltIn/` rather than
     * a literal. Measured with the old literal assertions in place: an eleventh
     * implementor added to that directory and not listed here left the whole
     * Integration tier at `OK (467 tests, 2681 assertions)`, which is exactly how
     * {@see Write} came to be written, tested, named in the README and unreachable
     * from any real run. If you add a tool class, add it here — the mechanism that
     * tells you is a red test, not the type system.
     *
     * @param InstructionFileLoader|null $loader Pass the caller's loader to
     *        keep the engine's root-instruction reads and the tools' on-touch
     *        reads on ONE instance (its dedup map is per-instance).
     * @param SkillRegistry|null $skills The registry the model-facing {@see
     *        SkillTool} resolves names against. Pass the caller's registry so
     *        the tool, {@see EngineBackend::withSkillRegistry()} and the shell
     *        pane all read ONE instance — two independently scanned
     *        registries would let a skill disabled on one still be invocable
     *        through the other. Defaults to a fresh scan of $root.
     * @param LspClient|null $lsp Language servers for {@see LspTool}, threaded
     *        through to {@see lspTool()}. Null — the shipped state, since nothing
     *        in `src/` builds one — leaves the tool reachable but refusing.
     *
     * @return list<Tool>
     */
    public static function tools(?string $root = null, ?InstructionFileLoader $loader = null, ?SkillRegistry $skills = null, ?LspClient $lsp = null): array
    {
        $root = self::requireRoot($root);
        $loader ??= self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        // ONE tracker across every tool that resolves a path — Read, Edit,
        // Glob, Grep and Write: the "announce a path-scoped skill the first
        // time we touch a file it covers" rule is only correct if all of them
        // share the announced-set. Five trackers would re-announce the same
        // skill once per tool (crush_feat.md section 7 E4).
        //
        // This comment said THREE and named Read/Edit/Glob. It was already
        // stale — `Write` below has taken the same pair since it was wired —
        // and `Grep` has now joined them, which is the arithmetic this project
        // keeps getting wrong. The count and the names are both asserted, not
        // just written here: `BinSugarcrushWiringTest` now DERIVES the roster
        // from the tools that declare an `instructionLoader` property instead
        // of restating a hand-kept list, so the next tool to take the pair
        // fails that test until it is acknowledged there.
        $nudge = SkillPathNudge::new($skills);

        $tools = [
            new Bash($root),
            new Read($root, instructionLoader: $loader, skillNudge: $nudge),
            new Edit($root, instructionLoader: $loader, skillNudge: $nudge),
            new Glob($root, instructionLoader: $loader, skillNudge: $nudge),
            // Same pair as Read/Edit/Glob/Write, and it was the one
            // path-resolving tool without them: a `CLAUDE.md` governing a
            // directory stayed unannounced when Grep was what surfaced the
            // file, and a `paths:`-scoped skill stayed silent on a search that
            // named every file it covers.
            //
            // Taking the pair gives Grep session-scoped state, which is why
            // it now implements `CarriesSessionState` — see
            // {@see \SugarCraft\Crush\Tools\BuiltIn\Grep::isParallelSafe()}
            // for why its concurrency verdict is unchanged but its
            // justification is not.
            new Grep($root, instructionLoader: $loader, skillNudge: $nudge),
            // Write, and it was missing: {@see Edit} refuses a path that does
            // not exist yet (it requires `file_exists()` AND a non-empty
            // `old_string`), so with the set at nine the model's ONLY route to
            // a new file was a `Bash` heredoc — which skips the diff preview
            // entirely and reaches the permission gate as an opaque shell
            // command instead of a reviewable write. The class, its jail
            // routing and its diff rendering were all finished and tested
            // (`tests/Tools/BuiltIn/WriteTest.php`,
            // `tests/Tools/WorktreeJailRoutingTest.php`); only this line was
            // absent, so no real run could reach it. Same three arguments as
            // Edit deliberately — a write into a skill-scoped or
            // CLAUDE.md-bearing directory has to announce that context exactly
            // as touching the path through Read/Edit/Glob would.
            new Write($root, instructionLoader: $loader, skillNudge: $nudge),
            new WebFetch(),
            new WebSearch(),
            new Doctor(),
            // Level-2 of the progressive-disclosure design: the system prompt
            // carries only each skill's name+description, and the model pulls
            // a full SKILL.md body through this tool only when it decides one
            // is relevant (crush_feat.md section 7 E1/E2).
            new SkillTool($skills, new SkillLoader()),
            // ELEVENTH, and appended to the built-in half rather than inserted:
            // every position above is a wire position the model has already
            // learned. Dormant by construction on every launch today — see
            // {@see lspTool()} for the missing configuration half and for why a
            // dormant-but-reachable tool is shipped instead of an unreachable
            // finished subsystem.
            self::lspTool($root, $lsp),
            // APPENDED, so the eleven built-ins keep the wire order the model has
            // learned and an MCP config can only ever ADD names. Empty unless
            // this project ships a `.mcp.json` AND the user trusted this root —
            // see {@see mcpTools()} and {@see mcpClient()}.
            //
            // The doc-block above deliberately says ELEVEN, not "eleven plus
            // whatever this call returned": that count is the BUILT-IN set, which
            // is what `README.md`'s figure and `BinSugarcrushWiringTest`'s scanned
            // assertion are both about. What this array returns is that set PLUS
            // whatever the project's MCP servers advertise, which is a per-project
            // number nothing in `src/` can know. It said TEN until `LspTool` was
            // wired above; if you add a twelfth, this number, the README figure
            // and `BuiltInToolCorpusTest`'s wired-count assertion all move
            // together.
            ...self::mcpTools($root),
        ];

        // FILTERED HERE, NOT AT THE CALL SITES, and that is the load-bearing
        // part rather than tidiness: `withTools(self::tools(...))` appears
        // THREE times in this class — in `app()`, `backend()` and
        // `backendFor()` — and a filter applied at two of them is a config key
        // that works until the user switches provider. (The plan for this item
        // named two sites; there are three. An earlier version of this comment
        // named `chat()` as one of them, which is wrong in the way this project
        // keeps being wrong: `chat()` reaches tools only transitively through
        // `backend()` and holds no `self::tools(` call of its own. Measured with
        // `grep -n 'self::tools(' src/Cli/Bootstrap.php`; deliberately no line
        // numbers, since a comment quoting them decays on the next insertion
        // above.) Everything
        // downstream of this return receives an already-filtered set, including
        // `mcpTools()`'s appended bridges, which is what makes
        // `disabledTools: ["mcp__git__*"]` mean anything.
        return self::filterToolSet($tools);
    }

    /**
     * Apply the user's `allowedTools` / `disabledTools` settings to the
     * model-facing tool set — Phase 6 item 3's `tools.allow` / `tools.deny`.
     *
     * BOTH KEYS ONLY EVER SHRINK THE SET, and saying so is the whole security
     * argument for {@see LayeredSettings::PROJECT_TIER_KEYS} admitting one of
     * them: {@see tools()}'s array is the ceiling, nothing here can add to it,
     * and an `allowedTools` whitelist is an intersection rather than a grant.
     * The names are {@see PermissionRule::matchesToolName()}'s dialect —
     * `fnmatch()`, so `mcp__git__*` works — because two glob dialects for tool
     * names would be two things `mcp__git__*` could mean.
     *
     * NOT A SEQUENCE OF TWO PASSES — A CONJUNCTION, and the difference matters
     * to the claim {@see LayeredSettings::PROJECT_TIER_KEYS} rests on. A tool is
     * kept iff the allow-list admits it AND the deny-list does not name it, in
     * ONE predicate, so there is no "first" and no "then" for a later stage to
     * re-admit anything in. An earlier draft of this comment said "allow-list
     * first, then deny", which was a sequencing this code never had; the
     * conclusion was right and the mechanism named for it was invented. Either
     * shape happens to be safe here because both halves only ever remove, but a
     * conjunction is safe by inspection and a pipeline is safe only by argument.
     *
     * An EMPTY or ABSENT `allowedTools` means "all of them", following
     * {@see \SugarCraft\Crush\MCP\McpRouter::resolveAllowedTools()}, which
     * already makes exactly this decision for MCP servers. The alternative
     * reading — an empty list means no tools — turns `"allowedTools": []` into
     * a silently toolless agent, and a user who wants that has
     * `disabledTools: ["*"]`.
     *
     * THAT LAST CLAUSE NAMES A USER AND THIS METHOD APPLIES IT TO A PROJECT.
     * `disabledTools` is in {@see LayeredSettings::PROJECT_TIER_KEYS}, so a
     * trusted checkout's `["*"]` reaches this same branch and produces the same
     * empty set — a "supported way to ask for a toolless agent" asked for by the
     * repository rather than by its operator. Left as it is, deliberately: both
     * reports fire ({@see reportProjectTierToolRemovals()} names the file, and
     * the empty-set warning below follows it), and reaching the branch at all
     * requires the operator's own
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY} grant. It is written
     * down because the sentence's authority is "the user chose this", and that
     * authority does not transfer to the project tier on its own — the trust
     * grant is what carries it there.
     *
     * A NON-LIST VALUE IS IGNORED rather than coerced, matching
     * {@see permissionRules()}'s discipline: `"disabledTools": "Bash"` is a
     * mistake whose charitable reading (a one-element list) is a guess, and
     * guessing on a key that decides what the model can do is how a config bug
     * becomes a capability change. Ignored means "as if unset", which for
     * `disabledTools` is the permissive direction — stated because it is the
     * one place in this method that does not fail closed, and it is chosen for
     * consistency with every other tolerant `readUserConfig()` reader rather
     * than because the safe direction is unclear.
     *
     * @param list<Tool> $tools
     * @return list<Tool>
     */
    private static function filterToolSet(array $tools): array
    {
        $kept = self::toolSetUnder($tools, self::readUserConfig());

        self::reportProjectTierToolRemovals($tools, $kept);

        // NO FLOOR, and there deliberately still is not one. `disabledTools`
        // reducing the set to nothing is a fail-SAFE direction — a model holding
        // no tools can do nothing — and the doc-block above names
        // `disabledTools: ["*"]` as the supported way to ask for exactly that,
        // so refusing it would break a configuration this class documents as
        // intentional. What was wrong was only that nobody was told: measured,
        // `{"disabledTools": ["*"]}` handed the backend an empty tool set and
        // no line anywhere said so, which from the model's end is
        // indistinguishable from a launch that failed to wire its tools.
        if ($kept === [] && $tools !== []) {
            // BOTH CHANNELS, and this is the strictly more severe sibling of
            // the report reportProjectTierToolRemovals() already routes there:
            // that one says which tools a project took, this one says there are
            // none left. An operator watching a model refuse to read a file has
            // no other way to learn why. Reached through tools() <- backend(),
            // chat()'s first named argument.
            self::warnPermissionConfigInTranscript(
                'allowedTools/disabledTools left no tools at all, so the model will be given an empty '
                . 'tool set and can do nothing but talk',
            );
        }

        return $kept;
    }

    /**
     * {@see filterToolSet()}'s predicate, against a GIVEN merged config rather
     * than against the one this process would read.
     *
     * Extracted so {@see reportProjectTierToolRemovals()} can evaluate the SAME
     * predicate against the stack minus the project tier and diff the two
     * results. That is not the "two passes" {@see filterToolSet()}'s doc-block
     * warns about: the allow/deny conjunction below is untouched and still
     * decides each tool in one expression; what runs twice is the whole
     * predicate, over two different configs, and neither run can re-admit
     * anything the other removed because neither run sees the other's output.
     *
     * (This sentence used to say "this method's doc-block", which pointed at the
     * paragraph you are reading — the one place the warning is NOT. It is in
     * {@see filterToolSet()}'s block, above the predicate the warning is about.)
     *
     * @param list<Tool> $tools
     * @param array<string, mixed> $config
     * @return list<Tool>
     */
    private static function toolSetUnder(array $tools, array $config): array
    {
        $allow = is_array($config['allowedTools'] ?? null) ? $config['allowedTools'] : [];
        $deny = is_array($config['disabledTools'] ?? null) ? $config['disabledTools'] : [];

        if ($allow === [] && $deny === []) {
            return $tools;
        }

        $matches = static function (array $patterns, string $name): bool {
            foreach ($patterns as $pattern) {
                if (is_string($pattern) && PermissionRule::matchesToolName($pattern, $name)) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter(
            $tools,
            static function (Tool $tool) use ($allow, $deny, $matches): bool {
                $name = $tool->name();

                if ($allow !== [] && !$matches($allow, $name)) {
                    return false;
                }

                return !$matches($deny, $name);
            },
        ));
    }

    /**
     * Say which tools a TRUSTED PROJECT's `disabledTools` took away.
     *
     * WHY THIS EXISTS, and what it does and does not fix. The reason
     * {@see LayeredSettings::PROJECT_TIER_KEYS} admits `disabledTools` while
     * withholding `allowedTools` was, in the doc's own words, that a deny-list
     * "can express the same attack, but only by naming every tool it removes —
     * a value you can see when you read the file". That is false, and it is
     * false because {@see PermissionRule::matchesToolName()} is bare
     * `fnmatch()`: measured end-to-end, a project-tier
     * `{"disabledTools": ["[!B]*"]}` leaves exactly `Bash` out of eleven.
     *
     * THE RESTRICTION THE BACKLOG PROPOSED WAS NOT TAKEN, and the measurement
     * says why. "Refuse negated character classes at the project tier" closes
     * the `[!B]*` spelling and nothing else: `["[C-Z]*", "[a-z]*"]` contains no
     * negation, is one character longer per glob, and also leaves only `Bash`.
     * MEASURED end-to-end on PHP 8.3.6, 2026-08-22, against the eleven-tool
     * ceiling this file builds — both values leave exactly `Bash`; PHP 8.4 was
     * NOT exercised, because this box has only 8.3.6 while CI runs both.
     * Shipping that restriction would have replaced a false claim about the
     * dialect with a false claim about the fix. Restricting the tier to LITERAL
     * names closes it completely, but it also deletes the legitimate use the
     * key was admitted for — a checkout saying "there is no git server here,
     * stop offering `mcp__git__*`" — and it costs the operator a capability they
     * had rather than the attacker one they did not, since reaching this code at
     * all already required the operator to list this checkout under
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}.
     *
     * THAT CLAUSE COUNTED THE GLOB INSTEAD OF NAMING IT, AND THE COUNT WAS
     * WRONG. WHAT IT SAID: "closes the eight-character version and nothing
     * else". WHAT IS TRUE NOW: nothing in the counterexample is eight
     * characters. `[!B]*` is five, `"[!B]*"` seven and `["[!B]*"]` nine, so the
     * phrase pointed at a value that does not exist and a reader could not tell
     * which of the three was meant. Round 42 retracted the same figure on
     * `docs/SETTINGS.md` (E74, `02fdc3f5`) and round 43 in
     * {@see LayeredSettings::PROJECT_TIER_KEYS} (E81, `7dab9c4b`) — an earlier
     * draft of THIS paragraph put both in round 42, which is the kind of
     * confident mechanism claim rule 8 exists for and is corrected rather than
     * dropped. This was the last copy left. The clause now names the glob. WHY THE SENTENCE STILL EARNS ITS PLACE: the length was never the
     * argument, and it was load-bearing only by accident. The argument is that
     * the proposed rule is keyed to a SPELLING while the hazard is an EFFECT —
     * `["[C-Z]*", "[a-z]*"]` is a different spelling of the same effect, one
     * character longer per glob, with no negation anywhere for the rule to
     * catch. That holds whatever the first spelling's length happens to be,
     * which is exactly why quoting the value reads better than counting it.
     * The three counts above now have a generator rather than a proof-read, in
     * {@see \SugarCraft\Crush\Tests\Config\GlobFigureDriftTest}, which also
     * censuses `src/` for paragraphs still spelling the retracted figure
     * without retracting it — the census is EMPTY as of this rewrite, and it is
     * asserted empty alongside a known-stale fixture, so an assertion of
     * nothing cannot pass by scanning nothing.
     *
     * So what is repaired is the PROPERTY, not the grammar: the effect is
     * visible, whatever spelling produced it. The capability is unchanged and
     * deliberately so — a trusted project may still choose the tool set, and now
     * the launch says that it did, names the file, and lists both what went and
     * what is left. That last part is the point: "everything narrow and
     * reviewable is gone and `Bash` is not" is the sentence an operator needs,
     * and no pattern-shape rule would have produced it.
     *
     * DIFFED RATHER THAN RE-MATCHED, and the reason is stronger than it first
     * looks. `LayeredSettings::merge()` is key-level, not union: a user who
     * names ANY `disabledTools` of their own replaces the project's list
     * outright. MEASURED — a user `["Read"]` against a trusted project
     * `["[!B]*"]` leaves ten tools, the user's one removal, and the project's
     * glob does nothing at all. So re-matching the project's patterns would
     * report removals that never happened, in exactly the case where the
     * operator had already protected themselves. The comparison is instead
     * between the set this launch actually offers and the set it would have
     * offered with layers 1+2 absent — through {@see mergedConfig()}, so there
     * is exactly one copy of the layer precedence and this cannot drift out of
     * agreement with {@see readUserConfig()} about which layer wins.
     *
     * @param list<Tool> $tools the unfiltered ceiling
     * @param list<Tool> $kept  what this launch will actually offer
     */
    private static function reportProjectTierToolRemovals(array $tools, array $kept): void
    {
        $root = self::$projectRootForSettings;
        if ($root === null) {
            return;
        }

        // The FILE, not just "the project": an operator told their tool set was
        // cut has to know whether to look in the committed `settings.json` or in
        // the `.gitignore`d `settings.local.json`. Null covers every reason
        // layers 1+2 contributed nothing — untrusted root, no such file, a file
        // that does not carry the key — so the untrusted case costs one call and
        // stays silent, which is what it should do: nothing was removed.
        $source = LayeredSettings::projectKeySource($root, self::projectSettingsTrusted($root), 'disabledTools');
        if ($source === null) {
            return;
        }

        $names = static function (array $set): array {
            return array_map(static function (Tool $tool): string {
                return $tool->name();
            }, $set);
        };

        $withoutProject = $names(self::toolSetUnder($tools, self::mergedConfig(false)));
        $removed = array_values(array_diff($withoutProject, $names($kept)));
        if ($removed === []) {
            return;
        }

        $remaining = array_values(array_diff($withoutProject, $removed));

        // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. On the
        // interactive path stderr alone is a warning nobody can read: measured,
        // this line printed 0.47s before the alternate screen opened over it.
        self::warnPermissionConfigInTranscript(sprintf(
            '%s (disabledTools) disabled %d of the %d tools your own settings left — %s — %s',
            $source,
            \count($removed),
            \count($withoutProject),
            implode(', ', $removed),
            $remaining === [] ? 'leaving no tools at all' : 'leaving: ' . implode(', ', $remaining),
        ));
    }

    /**
     * The one session-lifetime {@see InstructionFileLoader} shared by the
     * backend and the Read/Edit/Glob tools, so a root CLAUDE.md/AGENTS.md is
     * read (and its `@import`s expanded) once per session rather than once
     * per consumer.
     *
     * The forced-instruction glob patterns {@see
     * InstructionFileLoader::loadForced()} resolves come from {@see
     * forcedInstructions()} — the "instructions" key of
     * ~/.sugar-crush/config.json — which is what gives loadForced() a real
     * data source instead of a permanently-empty constructor default.
     */
    public static function instructionLoader(?string $root = null): InstructionFileLoader
    {
        return new InstructionFileLoader(self::requireRoot($root), self::forcedInstructions());
    }

    /**
     * The directory a run is rooted at: the caller's `--root`, else the
     * process working directory.
     *
     * Throws rather than substituting a placeholder when neither is
     * available. Every consumer of this value is a path jail
     * (Bash/Read/Edit/Glob/Grep) or a repo-root file scan
     * ({@see InstructionFileLoader}, {@see skillRegistry()}), and there is no
     * benign stand-in: `''` points those scans at the filesystem root, and
     * `null` leaves the tools with no jail at all — strictly worse than
     * refusing to start. A process whose working directory has been deleted
     * has no root to offer, so saying so is the only honest degradation
     * (crush_code.md Phase 0 item 6).
     */
    private static function requireRoot(?string $root): string
    {
        $resolved = $root ?? (getcwd() ?: null);
        if ($resolved === null) {
            throw new \RuntimeException(
                'sugarcrush: cannot determine a project root — the process working directory is '
                . 'unavailable (deleted or unreadable). Pass --root <dir>.'
            );
        }

        return $resolved;
    }

    /**
     * The user-configured forced-instruction glob patterns, read from the
     * "instructions" key of ~/.sugar-crush/config.json.
     *
     * Mirrors opencode's `opencode.json` `instructions` array: a list of
     * repo-relative globs (e.g. "docs/conventions/*.md") whose matches are
     * force-loaded into the system prompt every session regardless of what
     * the agent happens to touch. Before this existed, {@see
     * InstructionFileLoader::loadForced()} was reachable but could never
     * return anything on a real run — nothing ever passed it a pattern.
     *
     * Everything that is not a non-empty string is dropped here rather than
     * handed downstream: these values land verbatim in the model's system
     * prompt, and a hand-edited config file is the expected authoring route,
     * so a malformed entry must degrade to "that one pattern is ignored"
     * instead of a type error at session start. Containment (absolute paths,
     * `..` traversal) stays {@see InstructionFileLoader::loadForced()}'s job —
     * it is the layer that resolves a pattern against the repo root.
     *
     * @return list<string>
     */
    public static function forcedInstructions(): array
    {
        $configured = self::readUserConfig()['instructions'] ?? null;
        if (!is_array($configured)) {
            return [];
        }

        $patterns = [];
        foreach ($configured as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Real, on-disk session store backing /branch, /rename, and /rewind.
     *
     * EnhancedSessionStore wraps SessionStore via composition and delegates
     * every one of its methods (see its class docblock), making it a strict
     * superset of what Chat's sessionStore()/withSessionStore() accept
     * (`SessionStore|EnhancedSessionStore|null`). Constructing the enhanced
     * variant here means /rewind's checkpoint commands work out of the box
     * instead of degrading with "Session store does not support
     * checkpoints."
     *
     * Retention is applied here too — `pruneSessions()` had been written and
     * unit-tested but never called from anywhere — but only when the user
     * OPTED IN with `SUGARCRUSH_SESSION_RETENTION_DAYS`. It is off by default,
     * because the session a silent 30-day default deletes is precisely the one
     * the user came back for: coming back after a month means the row is old,
     * and it is unnamed because auto-titling fires at most once and fails
     * silently without a title backend. Startup is nevertheless the right
     * moment to run it when it IS on — it is the one point in the process
     * where no session is open, it runs before {@see seedSession()} picks the
     * row to resume, and it costs one indexed DELETE rather than anything on
     * the render or turn path.
     *
     * Two further guards, since this deletes conversations: the row
     * `seedSession()` would resume is passed in as exempt (so retention can
     * never eat the session the user is about to be handed, whatever its age),
     * and everything actually deleted is reported rather than going
     * unmentioned — one summary row on BOTH the transcript and stderr and the
     * per-session ids on stderr, see {@see reportPrunedSessions()} for the
     * split. (This sentence said "reported on stderr"; that was the whole truth
     * until E78 round 42 routed the summary onto the transcript seam, on the
     * ground that a launch which deleted conversations has taken a capability
     * away and the stderr copy is painted over by the alternate screen 0.47s
     * later.) A failure is swallowed: an unprunable database is not a
     * reason to refuse to start.
     *
     * `prune: false` is for the READ-ONLY callers. Retention is a property of
     * a LAUNCH, not of the store: it is safe here because this is the one
     * point where no session is open. A diagnostic that merely COUNTS rows —
     * {@see \SugarCraft\Crush\Cli\Subcommands::doctorProbes()}'s `session
     * store` check — must not delete conversations on the way to the count,
     * and MEASURED it did: two rows aged to 2020 with
     * SUGARCRUSH_SESSION_RETENTION_DAYS=7 became one, with the removal
     * reported on stderr, purely from running `sugarcrush doctor`. The default
     * stays `true` so the launch path in {@see chat()} is unchanged.
     *
     * @see \SugarCraft\Crush\Session\SessionStore::pruneSessions() for why
     *      named sessions are exempt from retention entirely.
     */
    public static function sessionStore(bool $prune = true): EnhancedSessionStore
    {
        $store = new EnhancedSessionStore(self::configDir() . '/session.db');

        $retentionDays = self::sessionRetentionDays();
        if ($prune && $retentionDays > 0) {
            try {
                $resumable = $store->listSessions(1)[0]['id'] ?? null;
                $pruned = $store->pruneSessions(
                    $retentionDays,
                    is_string($resumable) && $resumable !== '' ? $resumable : null,
                );
                if ($pruned > 0) {
                    self::reportPrunedSessions($store->pruneReport(), $retentionDays);
                }
            } catch (\Throwable) {
                // Best effort — see the docblock above.
            }
        }

        return $store;
    }

    /**
     * Tell the user which conversations retention just deleted.
     *
     * Silence was the worst part of the original wiring: a launch destroyed a
     * month-old session and its whole transcript, printed nothing, logged
     * nothing, and the caller threw the count away. stderr before the TUI
     * takes the screen is where this class already reports provider fallbacks.
     *
     * WHAT THIS DOC-BLOCK USED TO SAY, and what the routing rule in
     * {@see warnPermissionConfigInTranscript()} used to say about it: stderr is
     * the whole answer here, because this notice is "about history already
     * deleted, not about this session's capabilities".
     * WHAT IS TRUE NOW: the SUMMARY is on both channels (E78 round 42), because
     * the second half of that sentence does not survive being read next to the
     * rule it was decided under. The rule is "a warning reaches the transcript
     * iff it names something the session can no longer DO", and after a prune
     * the session can no longer resume, branch, rename or rewind the rows this
     * line names — the `/resume` picker and `session list` are simply shorter
     * than the user left them. That is a capability the launch removed, not a
     * complaint about a malformed config, and it is the ONLY entry on this
     * class's stderr-only list that destroys data. The 0.47s window
     * {@see warnPermissionConfigInTranscript()} measures applies to it exactly
     * as it applies to a provider fallback: an interactive launch paints the
     * alternate screen over this line before it can be read, and the primary
     * buffer does not come back until the session is over.
     * WHY THE PER-SESSION ROWS STILL EARN THEIR PLACE ON STDERR ALONE: the
     * summary is ONE row per launch whatever the prune deleted, where the
     * detail is one row per session — an unbounded per-ENTRY fan-out into a
     * list that is sent to the model on every turn, which is the exact shape
     * {@see LAUNCH_NOTICE_LIMIT} exists to refuse. stderr is the complete,
     * unclipped record; the transcript carries the fact and the count.
     *
     * ORDERING is what makes the summary reachable at all: {@see chat()} calls
     * {@see sessionStore()} early and reads {@see launchNotices()} last, so a
     * notice recorded here is in hand by the time the transcript is seeded.
     *
     * THE NON-CHAT CALLERS, surveyed in full — an earlier version of this
     * paragraph named only the doctor probe, which made the survey look
     * complete when it was one of three. {@see sessionStore()} is NOT memoized;
     * it builds a fresh store and prunes on every call, so every caller is a
     * caller of this method. There are four:
     *
     *  - {@see chat()} — the production path, and the only one that reads
     *    {@see launchNotices()} back.
     *  - {@see \SugarCraft\Crush\Cli\Subcommands::doctorProbes()} — passes
     *    `prune: false`, so it never reaches this method at all.
     *  - {@see \SugarCraft\Crush\Cli\Subcommands::sessionList()} and
     *    {@see \SugarCraft\Crush\Cli\Subcommands::sessionDelete()} — both take
     *    the default `prune: true`, so on a retention-enabled box
     *    `sugarcrush session list` DOES record a transcript row here, into a
     *    static list that subcommand never reads and the process then discards.
     *    Inert rather than wrong — the stderr half of the seam still prints,
     *    which is the half a subcommand's user is reading, and the orphaned row
     *    costs one bounded string — but it is the shape to watch: if a
     *    subcommand ever grows a transcript of its own, this is where a launch
     *    notice about a prune would arrive in it uninvited. Not fixed here
     *    because suppressing it means either memoizing the store or threading a
     *    "who is asking" flag through the accessor, and both are larger changes
     *    than the thing they would prevent.
     *
     * The summary's trailing `:` is gone with the migration — the seam formats
     * every message as `sugarcrush: <message>.` — and the detail rows below
     * still read as its list because they follow it immediately and name
     * themselves. A repeat launch in one process is the one case where they can
     * be orphaned: {@see warnPermissionConfigOnce()} de-duplicates the summary
     * per PROCESS while these rows are unconditional, so an identical second
     * prune prints its rows under no header. Self-describing rows rather than
     * a second de-dup map, because the alternative is dropping the complete
     * record on the channel that exists to hold it.
     *
     * @param array<int, array{id: string, name: ?string, updated_at: string, messages: int}> $report
     */
    private static function reportPrunedSessions(array $report, int $retentionDays): void
    {
        $count = count($report);
        self::warnPermissionConfigInTranscript(sprintf(
            'retention removed %d unnamed %s untouched for %d+ days (ids on stderr)',
            $count,
            $count === 1 ? 'session' : 'sessions',
            $retentionDays,
        ));
        foreach ($report as $row) {
            fwrite(STDERR, sprintf(
                "sugarcrush:   %s (last used %s UTC, %d %s)\n",
                $row['id'],
                $row['updated_at'],
                $row['messages'],
                $row['messages'] === 1 ? 'message' : 'messages',
            ));
        }
    }

    /**
     * How many days an unnamed session survives without being touched, from
     * `SUGARCRUSH_SESSION_RETENTION_DAYS`.
     *
     * **Retention is opt-in: the default is `0`, which disables it.** An
     * unset variable cannot mean "delete my history", and the only signal
     * distinguishing a session worth keeping from an abandoned one — a name —
     * is weak enough (auto-titling runs once per session, needs a working
     * title backend, and fails silently) that a session holding a month of
     * work can easily still be unnamed.
     *
     * `0` and any value that is not a plain positive integer — negative,
     * fractional, suffixed, wordy — also disable it rather than guessing at an
     * intent. A value above {@see SessionStore::MAX_RETENTION_DAYS} is clamped
     * rather than rejected: `ctype_digit()` accepts `99999999999999999999`,
     * `(int)` saturates it to `PHP_INT_MAX`, and `strtotime("-PHP_INT_MAX
     * days")` overflows to a cutoff in the year 2343668 — a FUTURE cutoff, at
     * which "older than the cutoff" is every session there is.
     */
    public static function sessionRetentionDays(): int
    {
        $raw = getenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        if ($raw === false) {
            return 0;
        }

        $trimmed = trim($raw);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return 0;
        }

        return min((int) $trimmed, SessionStore::MAX_RETENTION_DAYS);
    }

    /**
     * Give the CLI a real session row to run against, resuming the most
     * recent one when the store already has sessions and creating one when
     * it does not (crush_feat.md §5 E1's sketch).
     *
     * This is the capstone gap that whole feature area was blocked on: no
     * production path — not this method's caller, not `Chat::init()`, not
     * any `bin/` entry point — ever called {@see
     * \SugarCraft\Crush\Session\SessionStore::createSession()}, so on a real
     * run `listSessions()` returned `[]` for the whole process lifetime.
     * Everything keyed off `currentSessionId` was therefore dead in
     * production while passing its own unit tests: `/sessions` rendered an
     * empty picker, `Renderer::renderSessionTabStrip()` self-suppressed
     * below two rows, `Chat::cycleSessionTab()` early-returned, `/branch`
     * had nothing to fork, and `Chat::scheduleTitleGeneration()` bailed on
     * its `currentSessionId === null` guard so the auto-title call could
     * never fire.
     *
     * Resume-most-recent (rather than always creating) is what §5 E1
     * sketches, and it is also what makes the run's `/rewind` checkpoints
     * survive a restart. The existing row's `name` is handed back with it
     * so the caller can latch `Chat::currentSessionName()`: without that,
     * a resumed-but-already-titled session would look unnamed to
     * `scheduleTitleGeneration()` and get re-titled — and the store's name
     * overwritten — on the first turn of every subsequent launch.
     *
     * @param string $provider Provider name to record on a newly created
     *        row (see {@see selectedProviderLabel()}); ignored when an
     *        existing session is resumed.
     * @param string $model Model name to record alongside it.
     *
     * @return array{0: string, 1: ?string} session id, and its existing
     *         name when resuming a named session (null otherwise)
     */
    public static function seedSession(
        \SugarCraft\Crush\Session\SessionStore|EnhancedSessionStore $store,
        string $provider = 'sugarcrush',
        string $model = 'unknown',
    ): array {
        $row = $store->listSessions(1)[0] ?? null;
        if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
            $name = $row['name'] ?? null;

            return [$row['id'], is_string($name) && $name !== '' ? $name : null];
        }

        $sessionId = bin2hex(random_bytes(8));
        $store->createSession($sessionId, $provider, $model);

        return [$sessionId, null];
    }

    /**
     * The provider/model pair to stamp on a session row this process
     * creates, mirroring {@see backend()}'s own selection order.
     *
     * These are labels, not a second backend selection: they record what
     * the run *asked* for. If a requested provider turns out to be
     * unconstructable, {@see backend()} warns on stderr and degrades to
     * Echo while this row still names the requested provider — recording
     * the request is more useful than recording the fallback, and the
     * store has no column for both. Both shell-out paths
     * (`$SUGARCRUSH_BACKEND_CMD`, `$SUGARCRUSH_BACKEND_CMD_STREAM`) and the
     * default path have no provider name at all, so they are labelled
     * honestly as such rather than given an invented one.
     *
     * Public because {@see NonInteractive::run()} needs to distinguish "this
     * run is on the offline Echo default" from "this run is on
     * `$SUGARCRUSH_BACKEND_CMD`" before it decides whether to warn that the
     * answer did not come from a model — re-reading the same two env vars
     * there would be a second copy of this precedence chain that could drift
     * from {@see backend()}'s.
     *
     * @return array{0: string, 1: string}
     */
    public static function selectedProviderLabel(): array
    {
        $name = self::selectedProviderName();
        if ($name === null) {
            // ONE label for both shell-out tiers. The label answers "did a
            // model behind a provider produce this?", and the answer is no for
            // either variable; a third label would also have to be taught to
            // {@see provider()}, whose `$name === 'command'` arm is what keeps
            // a shell-out run from being captioned "echo" in the status bar.
            return self::backendCommandTierIsSelected() ? ['command', 'unknown'] : ['echo', 'echo'];
        }

        // Same resolver backendFor() builds the real backend from, so the
        // caption cannot name a different model than the one answering.
        $model = self::selectedModelName();
        if ($model === null) {
            try {
                $configured = (new ProviderFactory())->defaultConfig($name)['model'] ?? null;
            } catch (\Throwable) {
                $configured = null;
            }
            $model = is_string($configured) && $configured !== '' ? $configured : 'unknown';
        }

        return [$name, $model];
    }

    /**
     * The provider name this run selected, or null when the run is not on
     * a provider at all (either shell-out tier — see
     * {@see backendCommandTierIsSelected()} — or the offline Echo default). Same precedence {@see backend()} applies:
     * `$SUGARCRUSH_PROVIDER`, then the name persisted by a previous Ctrl+P
     * "Switch model".
     *
     * Public because this is the exact question the one-shot path has to ask
     * before choosing between {@see backend()}'s lenient fallback and {@see
     * backendFor()}'s throw-don't-degrade contract (crush_code.md Phase 0
     * item 10): a non-null answer means *this run asked for a specific
     * provider*, and silently answering it from Echo is the bug. Exposing the
     * existing helper rather than re-deriving the precedence in {@see
     * NonInteractive} keeps ONE definition of "which provider did this run
     * select", so the backend, the session row's recorded provider and the
     * one-shot hard-fail can never disagree about it.
     */
    public static function selectedProviderName(): ?string
    {
        $env = getenv('SUGARCRUSH_PROVIDER');
        if ($env !== false && $env !== '') {
            return $env;
        }

        if (self::backendCommandTierIsSelected()) {
            return null;
        }

        $persisted = self::readUserConfig()['provider'] ?? null;

        return is_string($persisted) && $persisted !== '' ? $persisted : null;
    }

    /**
     * Whether this run is on one of {@see backend()}'s two shell-out tiers —
     * `$SUGARCRUSH_BACKEND_CMD` or `$SUGARCRUSH_BACKEND_CMD_STREAM`.
     *
     * Both selection helpers above ask exactly this question and both have to
     * get the same answer: {@see selectedProviderName()} returns null so a
     * stale persisted provider does not outrank a shell-out that
     * {@see backend()} really is about to construct, and
     * {@see selectedProviderLabel()} labels the run 'command' so
     * {@see \SugarCraft\Crush\Cli\NonInteractive} does not announce "no
     * provider configured" to someone who configured one. When the streaming
     * variable was added, reading only the first of the two here is precisely
     * how those two answers would have drifted apart.
     */
    private static function backendCommandTierIsSelected(): bool
    {
        foreach (['SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_BACKEND_CMD_STREAM'] as $var) {
            if (self::backendCommandEnv($var) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The command one of the two shell-out variables names, or null when that
     * variable is ABSENT — which includes unset, empty, and WHITESPACE-ONLY.
     *
     * The whitespace case is not pedantry: `export SUGARCRUSH_BACKEND_CMD='   '`
     * (or the same on the streaming variable) used to select the shell-out tier,
     * spawn `sh -c '   '`, exit 0 and return an EMPTY assistant message, while
     * {@see selectedProviderLabel()} labelled the run 'command' so nothing
     * warned about it — a run with no model, no answer and no complaint. There
     * is no command a caller could mean by a string of spaces, and absence is
     * the only reading that leaves the next tier reachable.
     *
     * Both selection sites go through here so they cannot disagree: this method
     * defines what "the variable is set" MEANS, and {@see backend()} choosing a
     * tier while {@see backendCommandTierIsSelected()} denied one existed is
     * exactly the drift that would attribute a `-p` answer to the wrong
     * backend. The value is returned UNTRIMMED — leading whitespace is
     * harmless to `sh -c` and trimming would silently rewrite the command a
     * caller actually exported.
     */
    private static function backendCommandEnv(string $var): ?string
    {
        $value = getenv($var);
        if ($value === false || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * A deliberately cheap Backend for {@see Chat}'s one-shot session-title
     * call, or null when this run has no provider to build one from.
     *
     * Chat falls back to the MAIN conversation backend when this is null —
     * which on the provider paths means naming a session costs a second
     * full tool-capable agent turn, complete with the tool schemas, skill
     * registry and root CLAUDE.md/AGENTS.md instruction preamble, for a
     * request whose entire output is a handful of words. This backend is
     * the same provider with none of that attached: no tools, no hooks, no
     * skill registry, no instruction loader, so the title request carries
     * only the title prompt and the transcript.
     *
     * The model is `$SUGARCRUSH_TITLE_MODEL`, else a `titleModel` key in
     * ~/.sugar-crush/config.json, else the provider's own default — and
     * deliberately NOT `$SUGARCRUSH_MODEL`, which names the big
     * conversation model. Null on any construction failure: an unnamed
     * session is a non-event (same silent-failure stance {@see
     * \SugarCraft\Crush\Chat::scheduleTitleGeneration()} takes), and
     * `backend()` has already warned about an unusable provider.
     */
    public static function titleBackend(): ?Backend
    {
        return self::toollessBackend('SUGARCRUSH_TITLE_MODEL', 'titleModel');
    }

    /**
     * A deliberately TOOL-LESS Backend for `/compact`'s model-written exchange
     * summaries (crush_code.md Phase 5 item 6), or null when this run has no
     * provider to build one from.
     *
     * Tool-less, not cheap — the distinction matters in a build whose other half
     * is a spend cap, and the next paragraph is why: this one deliberately runs
     * on the provider's DEFAULT model, which is the expensive one. Its prompt is
     * the whole earlier conversation, so it is routinely the largest single call
     * this app makes. {@see titleBackend()} is the cheap one.
     *
     * Separate from {@see titleBackend()} because the two calls want different
     * models for different reasons and one variable could not serve both: a
     * session title is a handful of words and the smallest model will do, while
     * a compaction summary is what the model will be shown of the whole earlier
     * conversation from then on, and a bad one is permanent context loss. So
     * this defaults to the PROVIDER's default model rather than to the title
     * model, and `$SUGARCRUSH_SUMMARY_MODEL` / `summaryModel` exist for a user
     * who would rather trade quality for cost here.
     *
     * What it shares with titleBackend() is the part that matters for
     * correctness: no tools, no hooks, no skill registry, no instruction
     * preamble. {@see \SugarCraft\Crush\Chat}'s $summaryBackend docblock spells
     * out why routing a summarization through the tool-capable main backend
     * would let a compaction raise a permission prompt.
     *
     * Null on any construction failure, and that is not an error path: `/compact`
     * falls back to the heuristic summarizer it has always used.
     */
    public static function summaryBackend(): ?Backend
    {
        return self::toollessBackend('SUGARCRUSH_SUMMARY_MODEL', 'summaryModel');
    }

    /**
     * The construction {@see titleBackend()} and {@see summaryBackend()} share:
     * this run's selected provider with NOTHING attached — no tools, no hooks,
     * no skill registry, no instruction loader — under whichever model
     * $modelEnvVar, then the $modelConfigKey key of ~/.sugar-crush/config.json,
     * then the provider's own default names.
     *
     * One builder rather than two near-copies, because the tool-less part is
     * the load-bearing part: a second copy is a second place for a `withTools()`
     * to be added by mistake, and on the summarization path that would mean a
     * compaction that can run Bash.
     *
     * `hooksDisabled: true` is passed EXPLICITLY, and the reason is that the
     * "no hooks" half of the sentence above was otherwise false. Left at its
     * default, {@see EngineBackend::resolveHookManager()} calls
     * `registerBuiltIns()` and the backend carries `ProtectFilesHook`,
     * `ConfirmRemoveHook` and `AuditHook`. All three are `PreToolUse`/
     * `PostToolUse` only, so with no tools attached none of them could ever
     * fire and the safety conclusion held anyway — but it held as a
     * TWO-STEP argument resting on a second fact, and this is asserted as a
     * safety property at four sites. One flag makes the sentence true on its
     * own terms.
     */
    private static function toollessBackend(string $modelEnvVar, string $modelConfigKey): ?Backend
    {
        $providerName = self::selectedProviderName();
        if ($providerName === null) {
            return null;
        }

        try {
            $factory = new ProviderFactory();
            $config = $factory->defaultConfig($providerName);

            $model = getenv($modelEnvVar);
            if ($model === false || $model === '') {
                $configured = self::readUserConfig()[$modelConfigKey] ?? null;
                $model = is_string($configured) && $configured !== ''
                    ? $configured
                    : (string) ($config['model'] ?? '');
            }
            if ($model === '') {
                return null;
            }
            $config['model'] = $model;

            return new EngineBackend($factory->create($config), $model, hooksDisabled: true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The spend ceiling `$SUGARCRUSH_MAX_COST` sets, in US dollars, or null for
     * no cap (crush_code.md Phase 5 item 7).
     *
     * ABSENCE AND A BAD VALUE ARE DIFFERENT ANSWERS, exactly as they are for
     * `$SUGARCRUSH_PERMISSION_MODE` (see {@see permissionGate()}), and for the
     * same reason spelled out there: every fallback in this chain ends somewhere
     * MORE PERMISSIVE, so silently discarding a value the user set on purpose is
     * a fail-open.
     *
     * - Unset, or empty/whitespace — absence. No cap, like every other variable
     *   this class reads.
     * - A positive finite number, optionally with a leading `$` and surrounding
     *   whitespace — the cap. `$5` is what a human types and `/budget $5` already
     *   accepts it, so refusing it here would be an inconsistency, not a
     *   safeguard.
     * - Anything else present — {@see PermissionConfigException}, which
     *   `bin/sugarcrush` turns into an exit-2 usage report. That covers `5USD`,
     *   `five dollars`, `0`, `-5` and `1e309`.
     *
     * The previous behaviour — read a bad value as unset, "matching the refusal
     * `/budget 0` gives" — conflated two things that are not alike. `/budget 0`
     * answers IN THE TRANSCRIPT, so the user learns immediately that no cap was
     * set; this path is read once at launch and said nothing, so a user who typed
     * `SUGARCRUSH_MAX_COST=5USD` got an uncapped session and no hint of it. The
     * argument for tolerance applies to a theme or a persisted provider, where
     * guessing wrong costs nothing.
     *
     * @throws PermissionConfigException when the variable is present and unusable
     */
    public static function maxCostUsd(): ?float
    {
        $raw = getenv('SUGARCRUSH_MAX_COST');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        $amount = ltrim($trimmed, '$');
        $value = is_numeric($amount) ? (float) $amount : null;

        if ($value === null || !Chat::isUsableSpendCap($value)) {
            throw new PermissionConfigException(
                "\$SUGARCRUSH_MAX_COST is '{$trimmed}', which is not a spend ceiling. Expected a positive "
                . 'number of US dollars (fractional allowed, a leading $ accepted), for example 5 or $2.50. '
                . 'Zero and negative are refused rather than read as "no cap" because they are the opposite '
                . 'request; a figure too large to represent (1e309, i.e. infinity) is refused because it '
                . 'would install a cap that never triggers. Unset the variable for no cap. Refusing to '
                . 'start rather than run uncapped with a ceiling you asked for.',
            );
        }

        return $value;
    }

    /**
     * Real, on-disk memory store backing /memory. MemoryStore's constructor
     * requires its directory to already exist and be writable, so the
     * directory is created here first.
     */
    public static function memoryStore(): MemoryStore
    {
        $dir = self::configDir() . '/memory';
        self::ensureDir($dir);

        return new MemoryStore($dir);
    }

    /**
     * {@see memoryStore()}, or null if it cannot be opened.
     *
     * The prompt-side consumer (crush_code.md Phase 5 item 9) must never be
     * able to stop a session starting. `memoryStore()` throws when
     * `~/.sugar-crush/memory` cannot be created or is not writable — a
     * read-only home, a tmpfs quota, an existing file where the directory
     * belongs — and while that is a real reason for `/memory` to report a
     * failure when the user invokes it, it is not a reason to refuse to launch.
     * So the *store* keeps throwing for `/memory` to surface, and the prompt
     * route degrades to "no memory block", which is exactly the prompt every
     * session had before this item.
     *
     * Same shape as {@see \SugarCraft\Crush\Backend\EngineBackend::userConfig()}'s
     * guard: a broken optional input costs the feature, never the turn.
     */
    private static function memoryStoreOrNull(): ?MemoryStore
    {
        try {
            return self::memoryStore();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ~/.sugar-crush — the same per-user config directory convention every
     * other stateful store in this codebase already uses (e.g.
     * Skills\SkillDiscovery, Agents\TeamManager, Workflows\WorkflowEngine).
     */
    private static function configDir(): string
    {
        $dir = self::configDirPath();
        self::ensureDir($dir);

        return $dir;
    }

    /**
     * Where {@see configDir()} would be, without creating it — the resolver on
     * its own, so read-only callers can name the directory without the naming
     * having a side effect on disk.
     */
    private static function configDirPath(): string
    {
        return self::homePath() . '/.sugar-crush';
    }

    /**
     * This user's home directory, or the least-surprising stand-in when
     * nothing can say — the one resolution every `~`-rooted path in this class
     * goes through, so the config directory and a `trustedProjectHooks` entry
     * written `~/src/repo` can never disagree about where `~` is.
     *
     * WHICH READERS STILL GET THE STAND-IN, stated exactly, because the older
     * wording here ("everything that is POLICY goes through
     * trustedConfigDirPath()") was not true of `~/.sugar-crush/agents`, whose
     * presets carry `permissionMode:` and `tools:`. That directory now resolves
     * through {@see trustedConfigDirPath()} as well, alongside `config.json`
     * and `hooks.yaml`. What is left on this path is the state whose worst
     * outcome is a lost setting or a lost transcript: the theme, the persisted
     * provider, the session store, the memory store, and the skill trees.
     *
     * Those are not policy, but they are not nothing either — a skill body is
     * prompt context — so the ORDER is what protects them: {@see chat()},
     * {@see backend()} and {@see backendFor()} each resolve
     * {@see trustedConfigDirPath()} before they build anything, so a launch
     * that cannot determine this user's home refuses before a store is created
     * or a skill tree is scanned, rather than after. Direct callers of the
     * individual store factories still get the stand-in; that is the documented
     * boundary, not an oversight.
     */
    private static function homePath(): string
    {
        return HomeDirectory::path();
    }

    /**
     * This user's home directory when it can actually be DETERMINED, or null.
     *
     * The environment failing to say where home is does not mean nobody knows:
     * a cron entry, a systemd unit, `env -i` and `sudo` without `-E` all drop
     * `HOME` while the process still has a real uid with a real passwd entry,
     * so the passwd database is consulted before giving up. That is what keeps
     * the answer the user's OWN directory on every path that previously fell
     * through to a shared one.
     *
     * Null — nothing in the environment, no passwd entry, or no ext-posix — is
     * "this process cannot tell whose home this is", which is a different
     * answer from any directory and is why this returns null rather than
     * inventing one.
     */
    private static function resolvedHomePath(): ?string
    {
        return HomeDirectory::resolved();
    }

    /**
     * {@see configDirPath()} for the two files that are SECURITY POLICY rather
     * than preference — `config.json` (permission mode, permission rules,
     * `trustedProjectHooks`) and `hooks.yaml` (shell commands run on tool
     * calls) — and therefore the one path resolution that may not fall back to
     * a directory anybody else can write.
     *
     * `/tmp` is mode 1777. With `HOME` unset and no passwd entry,
     * {@see homePath()}'s stand-in made `/tmp/.sugar-crush/hooks.yaml` the
     * "user's own file, which no repository can write" that
     * {@see hookFiles()} loads WITHOUT the project-trust gate — so any local
     * user could pre-create that directory and get arbitrary shell on the
     * session's first tool call, plus a `config.json` setting
     * `permissionMode` and `trustedProjectHooks`. The ungating is sound; the
     * premise it rests on ("you wrote it") is what stopped being true.
     *
     * Refusing is the only outcome that is neither of the two wrong ones: a
     * launch that reads a stranger's policy, or one that silently ignores the
     * policy the user did write. It costs one exported variable to fix and
     * says so.
     *
     * TWO REFUSALS, not one, and the second is new because the first was being
     * CITED as the second. This method used to ask {@see resolvedHomePath()},
     * which answers "can a home be NAMED" — a question `HOME=/tmp` passes. The
     * `/tmp` stand-in the paragraph above describes as the thing being defended
     * against was therefore reachable simply by pointing `HOME` at it, and
     * {@see agentPresets()} carried a comment asserting this method "refuses a
     * home this process cannot establish ownership of" when no `stat` was
     * performed anywhere in the package. MEASURED before {@see HomeDirectory::owned()}:
     *
     *     HOME=<mode 1777 dir>  ->  trustedConfigDirPath() = <that dir>/.sugar-crush
     *                           ->  user-tier presets read from it = ["notmine"]
     *
     * The second refusal is that measurement closed: the resolved home must
     * exist, must not be world-writable, and must be owned by this process's
     * effective uid. Its bounds — group-writable homes accepted, ownership
     * clause skipped on non-POSIX hosts — are written where the check is.
     *
     * @throws PermissionConfigException when this user's home cannot be determined
     *         or cannot be established as this user's
     */
    private static function trustedConfigDirPath(): string
    {
        $home = self::resolvedHomePath();
        if ($home === null) {
            throw new PermissionConfigException(
                'this process cannot determine which home directory is yours ($HOME is unset, '
                . '$USERPROFILE is unset, and there is no passwd entry for its uid), so the '
                . 'permission policy and hook chain in ~/.sugar-crush cannot be located. '
                . 'Refusing to start rather than read either of them out of a world-writable '
                . 'fallback directory — export HOME to the account this session belongs to.',
            );
        }

        $owned = HomeDirectory::owned();
        if ($owned === null) {
            throw new PermissionConfigException(sprintf(
                'the home directory this process resolved (%s) cannot be established as yours — it does not '
                . 'exist, or it is world-writable, or it is owned by another account. The permission policy '
                . 'and hook chain in ~/.sugar-crush would then be whatever the last local user to write '
                . 'there put in them, so they are not read. Export HOME to the account this session belongs '
                . 'to, or fix that directory\'s ownership and mode.',
                $home,
            ));
        }

        return $owned . '/.sugar-crush';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
