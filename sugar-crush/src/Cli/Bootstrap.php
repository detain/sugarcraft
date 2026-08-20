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
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
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

    private const DEFAULT_PERMISSION_MODE = PermissionMode::BypassPermissions;

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
     * The config FILE `--config` named, or null to discover
     * `~/.sugar-crush/config.json` — see {@see useConfigPath()}.
     */
    private static ?string $configPathOverride = null;

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
     * TWO OTHER HOLDERS of a repository-chosen path do NOT feed this, and each
     * is named rather than counted, because "five feeders" quietly becoming
     * "five feeders and three things nobody drains" is the drift this collector
     * keeps producing. It was FOUR until crush_code.md Phase 1 item 3 wired
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}, and THREE until
     * Phase 2 item 4 wired {@see \SugarCraft\Crush\Commands\CommandLoader} — which
     * is what a named gap is for, twice over. The two that remain are DORMANT —
     * nothing in `src/` or `bin/` constructs them — and both are GATED, which
     * dormant does not imply and for one round did not mean:
     *
     *  - {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}
     *    (`.opencode/memory`) exposes `refusedDirectories()` with nothing
     *    reading it yet;
     *  - `.sugar-crush/hooks.yaml` has its own trust gate
     *    ({@see projectHooksAreTrusted()}) and refuses the LAUNCH rather than
     *    degrading, so a collector entry would be unreachable.
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

        return $chat;
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
     * TEN repository-chosen DOT-DIRECTORY paths exist in `src/` — and the
     * qualifier is the number's domain rather than decoration. What the
     * derivation counts is a string literal of the shape `.<dir>/<segment>`:
     * twenty distinct ones on this tree, ten of them classified
     * repository-chosen. This list said FOUR, then FIVE, both hand-written; it is
     * now DERIVED from `src/` by
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest}, which
     * reds when an eleventh appears.
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
     * The THREE that are gated elsewhere and named as gaps rather than counted
     * here — `.sugar-crush/commands`, `.opencode/memory`,
     * `.sugar-crush/hooks.yaml` — are itemised on {@see $projectTierRefusals}.
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
            self::warnPermissionConfig(sprintf('ignoring %s — %s', $path, rtrim($reason, '.')));
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
     * WHAT THE IMPORT CARRIES INTO THE ROSTER IS NARROWER THAN THE PRESET, and
     * the difference is worth stating where the merge happens rather than leaving
     * it to be inferred: {@see Agent::fromPreset()} reads name, description,
     * `initialPrompt`, model, `tools` and `skills` and NOTHING ELSE, so an
     * imported preset's `permissionMode:` — the field the foreign registry's own
     * measurement showed reaching `bypass-permissions` — does not travel this
     * path at all. It is dropped on the floor for native presets too; this wiring
     * neither widens nor narrows that. ASSERTED on the type, not read off the
     * mapper, by
     * {@see \SugarCraft\Crush\Tests\Integration\ForeignAgentPresetWiringTest::testAnImportedPresetsPermissionModeHasNowhereToLandOnTheRoster()}
     * — the bound is only as good as `Agent`'s field list, which a refactor can
     * change without touching this method. It bounds THIS path and nothing else:
     * {@see \SugarCraft\Crush\Agents\AgentPreset} still carries every field, so a
     * future consumer reading presets directly inherits them.
     *
     * {@see \SugarCraft\Crush\Agents\AgentPreset::$source} STILL HAS NO READER
     * after this change. {@see Agent} carries no source field, so the palette
     * badge that would make an imported row distinguishable from a native one is
     * the remaining half of Phase 1 item 3 and needs a field on `Agent`, not more
     * wiring here.
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
            fwrite(STDERR, "sugarcrush: foreign agent presets unavailable ({$e->getMessage()}); continuing without them.\n");
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
            fwrite(STDERR, "sugarcrush: agent presets unavailable ({$e->getMessage()}); continuing with the built-in agents.\n");

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

        $chat = self::chat($root);
        [$provider, $model] = self::provider();

        // ONE registry for the shell's Skills pane and the Skill tool in its
        // displayed tool list: scanning twice would show the user a roster
        // that the tool could disagree with.
        $skills = self::skillRegistry($root);

        // This is a SECOND scan — chat() above already reported what its own
        // scan skipped — and it can find skills that one did not, because it
        // runs after it and reads the same trees. reportSkillSkips() only
        // prints what has not been printed yet, so the common case is silence
        // and the case this adds is a skill file that would otherwise have been
        // collected into skillSkips() and never mentioned to anybody.
        self::reportSkillSkips();
        // Same argument for the second scan's directory refusals.
        self::reportProjectTierRefusals();

        return App::new($provider, $model)
            ->withChat($chat)
            ->withSessionId($chat->currentSessionId())
            ->withTools(self::tools($root, null, $skills))
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
     * already warned on stderr in that case, and refusing to launch the TUI
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
     *      token as the command produces it; the read loop is synchronous, so
     *      the TUI does not repaint until the completion resolves (see that
     *      class's docblock — the display half of this claim was withdrawn
     *      after measurement, and a non-blocking rewrite is a backlog item).
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
     */
    public static function backend(?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null): Backend
    {
        // Same first-line refusal {@see chat()} makes, for the same ordering
        // reason: this method is the `-p` path's entry point, where nothing
        // else resolves the config directory until hooks() does — five calls
        // and one full skill scan later. See {@see trustedConfigDirPath()}.
        self::trustedConfigDirPath();

        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        $providerType = getenv('SUGARCRUSH_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                return self::backendFor($providerType, $root, $skills, $gate);
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
                fwrite(STDERR, "sugarcrush: provider '{$providerType}' unavailable ({$e->getMessage()}); falling back to echo.\n");
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
                return self::backendFor($persisted, $root, $skills, $gate);
            } catch (PermissionConfigException $e) {
                // See the env-var branch above: this arm exists to keep the
                // `\Throwable` degrade-to-echo arm below from catching it.
                throw $e;
            } catch (\Throwable $e) {
                fwrite(STDERR, "sugarcrush: persisted provider '{$persisted}' unavailable ({$e->getMessage()}); falling back to echo.\n");
            }
        }

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        return (new EngineBackend(new EchoProvider(), 'echo'))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks(null, $root))
            ->withPermissionGate($gate ?? self::permissionGate())
            ->withSkillRegistry($skills)
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(self::memoryStoreOrNull());
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
     *
     * @throws \Throwable
     */
    public static function backendFor(string $providerName, ?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null): Backend
    {
        // See backend(): whichever of the two a run enters through, the sweep
        // happens once, and the config directory is named before any store or
        // skill scan can resolve it out of a `/tmp` stand-in. sweepOnce()
        // latches, so backend() delegating here does not sweep twice.
        self::trustedConfigDirPath();
        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;
        $factory = new ProviderFactory();
        $provider = $factory->create($factory->defaultConfig($providerName));
        $model = getenv('SUGARCRUSH_MODEL') ?: ($factory->defaultConfig($providerName)['model'] ?? 'gpt-4o');

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        return (new EngineBackend($provider, (string) $model))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks(null, $root))
            ->withPermissionGate($gate ?? self::permissionGate())
            ->withSkillRegistry($skills)
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(self::memoryStoreOrNull());
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
     * @return array<string, mixed>
     */
    public static function readUserConfig(): array
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
        $merged = array_merge(self::readUserConfig(), $patch);
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
        self::warnPermissionConfig(sprintf(
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
                // same way a skipped permission rule is — see
                // {@see warnPermissionConfig()} for why stderr. This runs
                // during construction, before Program takes the terminal, so
                // on the interactive path the line lands ahead of the alt
                // screen rather than inside a frame.
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

                    self::warnPermissionConfig(
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
     * The project roots listed under $key in the user config, canonicalised.
     *
     * ONE PARSER FOR BOTH TRUST KEYS, parameterised by the key name rather than
     * copied: `trustedProjectHooks` and `trustedProjectMcp` are two separate
     * GRANTS ({@see TRUSTED_PROJECT_MCP_CONFIG_KEY} says why they may not be one)
     * but they are the same DATA SHAPE, and every rule below — the relative-entry
     * refusal, the `~` expansion, the item-wise tolerance, the once-per-process
     * warning — is a property of "a list of project roots in the user's config",
     * not of what the list authorises. A second copy would be a second place for
     * the `"."`-is-a-global-bypass refusal to be forgotten.
     *
     * @param array<string, mixed> $config the already-read user config
     * @param string $key which trust list to read
     * @param string $nothingTrusted what a wrong-shaped value costs, for the
     *        warning — the only sentence that differs between the two keys
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
     * answer Ask (Default/AcceptEdits/Auto, for writes) currently fail CLOSED
     * on the engine path, because no caller anywhere attaches an approver —
     * {@see EngineBackend::withPermissionApprover()} has none outside its own
     * test — so Default mode would have turned "no permission system" into
     * "every Edit refused". Be honest about what that costs: with the shipped
     * empty rule set, BypassPermissions is not "more guarded than before", it
     * is EXACTLY EQUAL to having no gate. Every destructive `rm` the gate's
     * circuit breaker refuses is already refused, earlier and more broadly, by
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}, and "explicit
     * deny rules still apply" says nothing when no rules are configured. What
     * the default buys is a gate that is REACHABLE and configurable: set
     * `permissionMode`/`permissionRules` and it starts deciding things. The
     * permissive default is a stopgap for the fail-closed ASK path, not the
     * settled design — it goes away once an ASK on the engine path can
     * actually be ANSWERED: an approver attached (the missing piece today),
     * and a channel it can ask over from inside
     * {@see EngineBackend::completeAsync()}'s forked child (the piece behind
     * that one).
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
        $config = self::permissionConfig();

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

        $mode = self::permissionModeFrom($envRaw, '$' . self::PERMISSION_MODE_ENV)
            ?? self::permissionModeFrom(
                $configRaw,
                self::PERMISSION_MODE_CONFIG_KEY . ' in ' . self::userConfigPath(),
            )
            ?? self::DEFAULT_PERMISSION_MODE;

        // The classifier is what Auto mode gates on, and PermissionGate fails
        // CLOSED (everything Asks) without one — so it is always supplied
        // rather than only when the mode currently happens to be Auto: this
        // gate instance is shared, and a mode read from config is not
        // something this method should have to predict.
        return new PermissionGate($mode, self::permissionRules($config), new SafetyClassifier());
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
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when the file exists and cannot be used
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
        $discovered = self::trustedConfigDirPath() . '/config.json';
        $path = self::$configPathOverride ?? $discovered;

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
     * @param array<string, mixed> $config the already-read user config
     * @return list<PermissionRule>
     */
    private static function permissionRules(array $config): array
    {
        $raw = $config[self::PERMISSION_RULES_CONFIG_KEY] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            self::warnPermissionConfig(
                self::PERMISSION_RULES_CONFIG_KEY . ' is not a list of rules; no rules were loaded',
            );

            return [];
        }

        $rules = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['pattern'] ?? null)) {
                self::warnPermissionConfig(
                    self::PERMISSION_RULES_CONFIG_KEY . "[{$index}] has no string 'pattern'; rule skipped",
                );
                continue;
            }

            $action = is_string($entry['action'] ?? null)
                ? PermissionAction::tryFrom($entry['action'])
                : null;
            if ($action === null) {
                self::warnPermissionConfig(
                    self::PERMISSION_RULES_CONFIG_KEY . "[{$index}] ('{$entry['pattern']}') has no valid 'action' "
                    . "(expected allow, deny or ask); rule skipped rather than coerced",
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
     * `tools=10` is the part that matters: the payload was not even a working MCP
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
            // error_log() RATHER THAN warnPermissionConfig(), which is this class's
            // other stderr seam, and the reason is that it is the only one a test
            // can OBSERVE: `error_log()` honours the `error_log` ini setting, so
            // {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()}
            // points it at a file and asserts this text, where a
            // `fwrite(STDERR, …)` would be unassertable and would additionally
            // print into the suite's own output. Same destination in production —
            // an unset `error_log` ini means stderr — and same construction-time
            // window every other launch notice here uses. It is NOT routed into
            // {@see $projectTierRefusals}: that collector's subject is a path this
            // launch declined to READ, and this file was read, granted and partly
            // started. Its key is the path, which the two refusals above already
            // own, so an entry here would collide with them.
            error_log(sprintf(
                'sugarcrush: MCP config %s could not be fully started (%s: %s); continuing without it.',
                $path,
                $e::class,
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
     * Build the built-in coding tools, with a shared InstructionFileLoader
     * wired into every tool that surfaces nested CLAUDE.md/AGENTS.md content
     * (Read/Edit/Glob/Write) so those files are actually reachable when a user
     * runs the real CLI binary.
     *
     * THIS ARRAY IS THE WHOLE MODEL-FACING TOOL SET. Its BUILT-IN half is ten
     * entries as of this writing, and THAT count — not this array's length — is
     * the domain for every "N built-in tools" figure in `README.md`.
     * `src/Tools/BuiltIn/` holds exactly those ten concrete `Tool` classes.
     *
     * The array is longer than ten only when the project ships a `.mcp.json` AND
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
     *
     * @return list<Tool>
     */
    public static function tools(?string $root = null, ?InstructionFileLoader $loader = null, ?SkillRegistry $skills = null): array
    {
        $root = self::requireRoot($root);
        $loader ??= self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        // ONE tracker across Read/Edit/Glob: the "announce a path-scoped skill
        // the first time we touch a file it covers" rule is only correct if all
        // three share the announced-set. Three trackers would re-announce the
        // same skill once per tool (crush_feat.md section 7 E4).
        $nudge = SkillPathNudge::new($skills);

        return [
            new Bash($root),
            new Read($root, instructionLoader: $loader, skillNudge: $nudge),
            new Edit($root, instructionLoader: $loader, skillNudge: $nudge),
            new Glob($root, instructionLoader: $loader, skillNudge: $nudge),
            new Grep($root),
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
            // APPENDED, so the ten built-ins keep the wire order the model has
            // learned and an MCP config can only ever ADD names. Empty unless
            // this project ships a `.mcp.json` AND the user trusted this root —
            // see {@see mcpTools()} and {@see mcpClient()}.
            //
            // The doc-block above deliberately still says TEN: that count is the
            // BUILT-IN set, which is what `README.md`'s figure and
            // `BinSugarcrushWiringTest`'s scanned assertion are both about. What
            // this array returns is that set PLUS whatever the project's MCP
            // servers advertise, which is a per-project number nothing in `src/`
            // can know.
            ...self::mcpTools($root),
        ];
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
     * and everything actually deleted is reported on stderr rather than going
     * unmentioned. A failure is swallowed: an unprunable database is not a
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
     * @param array<int, array{id: string, name: ?string, updated_at: string, messages: int}> $report
     */
    private static function reportPrunedSessions(array $report, int $retentionDays): void
    {
        $count = count($report);
        fwrite(STDERR, sprintf(
            "sugarcrush: retention removed %d unnamed %s untouched for %d+ days:\n",
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

        $model = getenv('SUGARCRUSH_MODEL');
        if ($model === false || $model === '') {
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
