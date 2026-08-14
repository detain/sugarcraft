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
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
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

        return new Chat(
            backend: self::backend($root, $skills),
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
            hooks: self::hooks(),
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
     */
    public static function agentManager(?string $root = null, ?SkillRegistry $skills = null): AgentManager
    {
        $root = self::requireRoot($root);
        [$provider, $model] = self::provider();

        $manager = new AgentManager($provider, $skills ?? self::skillRegistry($root));

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
        $registry = new AgentPresetRegistry([
            rtrim($root, '/') . '/.sugar-crush/agents',
            self::configDirPath() . '/agents',
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
     */
    public static function backend(?string $root = null, ?SkillRegistry $skills = null): Backend
    {
        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        $providerType = getenv('SUGARCRUSH_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                return self::backendFor($providerType, $root, $skills);
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
                return self::backendFor($persisted, $root, $skills);
            } catch (\Throwable $e) {
                fwrite(STDERR, "sugarcrush: persisted provider '{$persisted}' unavailable ({$e->getMessage()}); falling back to echo.\n");
            }
        }

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        return (new EngineBackend(new EchoProvider(), 'echo'))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks())
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
     *
     * @throws \Throwable
     */
    public static function backendFor(string $providerName, ?string $root = null, ?SkillRegistry $skills = null): Backend
    {
        // See backend(): whichever of the two a run enters through, the sweep
        // happens once. sweepOnce() latches, so backend() delegating here does
        // not sweep twice.
        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;
        $factory = new ProviderFactory();
        $provider = $factory->create($factory->defaultConfig($providerName));
        $model = getenv('SUGARCRUSH_MODEL') ?: ($factory->defaultConfig($providerName)['model'] ?? 'gpt-4o');

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        return (new EngineBackend($provider, (string) $model))
            ->withTools(self::tools($root, $loader, $skills))
            ->withHooks(self::hooks())
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
        self::ensureDir(self::configDirPath());

        file_put_contents(self::userConfigPath(), $json);
    }

    /**
     * Discover every skill reachable from $root and hand back the populated
     * registry: built-in (src/Skills/BuiltIn), user (~/.sugar-crush/skills),
     * project ({$root}/.sugar-crush/skills), and foreign imports from other
     * coding CLIs' conventions — {$root}/.claude/skills, ~/.claude/skills,
     * {$root}/.opencode/skills, ~/.config/opencode/skills (see {@see
     * \SugarCraft\Crush\Skills\ForeignSkillDiscovery}).
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

        return $registry;
    }

    private static function hooks(): HookManager
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->registerBuiltIns(); // audit + confirm-rm + protect-files guards

        return $hooks;
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
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');

        return $home . '/.sugar-crush';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
