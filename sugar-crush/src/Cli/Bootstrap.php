<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
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
use SugarCraft\Crush\Tools\Tool;

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
     * The trusted project roots this process resolved, keyed by the
     * `config.json` they came out of — see {@see trustedRootsForThisProcess()}
     * for why the answer may not be recomputed mid-session.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedRoots = [];

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

        return new Chat(
            backend: self::backend($root, $skills, $permissionGate),
            memoryStore: self::memoryStore(),
            sessionStore: $sessionStore,
            currentSessionId: $sessionId,
            currentSessionName: $sessionName,
            titleBackend: self::titleBackend(),
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
        );
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
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}) are
     * deliberately NOT merged here — that is crush_code.md Phase 1 item 3, and
     * it lands as its own change alongside the palette badging that makes an
     * imported preset distinguishable from a native one.
     *
     * @return list<Agent>
     */
    public static function agentRoster(string $root, string $provider, string $model): array
    {
        $agents = [];

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

        foreach (self::agentPresets($root) as $name => $preset) {
            $agents[$name] = Agent::fromPreset($preset, $provider, $model);
        }

        return array_values($agents);
    }

    /**
     * The native agent presets on disk, project directory first so a checked-in
     * preset overrides a same-named one in the user's home.
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
        $registry = new AgentPresetRegistry([
            rtrim($root, '/') . '/.sugar-crush/agents',
            self::trustedConfigDirPath() . '/agents',
        ]);

        try {
            return $registry->list();
        } catch (\Throwable $e) {
            fwrite(STDERR, "sugarcrush: agent presets unavailable ({$e->getMessage()}); continuing with the built-in agents.\n");

            return [];
        }
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
     *      reads JSON history on stdin and writes the reply to stdout.
     *   3. A provider name persisted by a previous Ctrl+P "Switch model"
     *      (see writeUserConfig()) — makes that choice survive a restart
     *      without needing $SUGARCRUSH_PROVIDER exported every time.
     *   4. (default) the offline EchoProvider, still run through the engine so the
     *      binary launches with zero network and zero config.
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

        $cmd = getenv('SUGARCRUSH_BACKEND_CMD');
        if ($cmd !== false && $cmd !== '') {
            return new CommandBackend($cmd);
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
            ->withRoot($root);
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
            ->withRoot($root);
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

        $configPath = ProviderFactory::defaultConfigPath();
        if (is_file($configPath)) {
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
        return self::configDirPath() . '/config.json';
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
        // {@see userConfigPath()}.
        $dir = self::configDirPath();
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
     * The project roots listed under `trustedProjectHooks`, canonicalised.
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
        $raw = $config[self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            self::warnPermissionConfigOnce(
                self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY . ' is not a list of project paths; '
                . 'no project hook file was trusted',
            );

            return [];
        }

        $roots = [];
        foreach ($raw as $index => $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                self::warnPermissionConfigOnce(
                    self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY . "[{$index}] is not a project path; entry skipped",
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
                    self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY . "[{$index}] is '{$entry}', which is relative to "
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
        $path = self::trustedConfigDirPath() . '/config.json';

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
     * Build the built-in coding tools, with a shared InstructionFileLoader
     * wired into every tool that surfaces nested CLAUDE.md/AGENTS.md content
     * (Read/Edit/Glob) so those files are actually reachable when a user
     * runs the real CLI binary.
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
            new WebFetch(),
            new WebSearch(),
            new Doctor(),
            // Level-2 of the progressive-disclosure design: the system prompt
            // carries only each skill's name+description, and the model pulls
            // a full SKILL.md body through this tool only when it decides one
            // is relevant (crush_feat.md section 7 E1/E2).
            new SkillTool($skills, new SkillLoader()),
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
     * @see \SugarCraft\Crush\Session\SessionStore::pruneSessions() for why
     *      named sessions are exempt from retention entirely.
     */
    public static function sessionStore(): EnhancedSessionStore
    {
        $store = new EnhancedSessionStore(self::configDir() . '/session.db');

        $retentionDays = self::sessionRetentionDays();
        if ($retentionDays > 0) {
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
     * store has no column for both. The `$SUGARCRUSH_BACKEND_CMD` and
     * default paths have no provider name at all, so they are labelled
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
            $cmd = getenv('SUGARCRUSH_BACKEND_CMD');

            return $cmd !== false && $cmd !== '' ? ['command', 'unknown'] : ['echo', 'echo'];
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
     * a provider at all (`$SUGARCRUSH_BACKEND_CMD`'s shell-out, or the
     * offline Echo default). Same precedence {@see backend()} applies:
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

        $cmd = getenv('SUGARCRUSH_BACKEND_CMD');
        if ($cmd !== false && $cmd !== '') {
            return null;
        }

        $persisted = self::readUserConfig()['provider'] ?? null;

        return is_string($persisted) && $persisted !== '' ? $persisted : null;
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
        $providerName = self::selectedProviderName();
        if ($providerName === null) {
            return null;
        }

        try {
            $factory = new ProviderFactory();
            $config = $factory->defaultConfig($providerName);

            $model = getenv('SUGARCRUSH_TITLE_MODEL');
            if ($model === false || $model === '') {
                $configured = self::readUserConfig()['titleModel'] ?? null;
                $model = is_string($configured) && $configured !== ''
                    ? $configured
                    : (string) ($config['model'] ?? '');
            }
            if ($model === '') {
                return null;
            }
            $config['model'] = $model;

            return new EngineBackend($factory->create($config), $model);
        } catch (\Throwable) {
            return null;
        }
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
     * @throws PermissionConfigException when this user's home cannot be determined
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

        return $home . '/.sugar-crush';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
