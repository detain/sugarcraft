<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
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
        $userConfig = self::readUserConfig();

        return new Chat(
            backend: self::backend($root),
            memoryStore: self::memoryStore(),
            sessionStore: self::sessionStore(),
            themeName: is_string($userConfig['theme'] ?? null) ? $userConfig['theme'] : 'dark',
            onConfigChange: static fn(string $key, string $value) => self::writeUserConfig([$key => $value]),
            mosaic: ToolResult::mosaic(),
        );
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
     */
    public static function backend(?string $root = null): Backend
    {
        $root ??= getcwd();

        $providerType = getenv('SUGARCRUSH_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                return self::backendFor($providerType, $root);
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
                return self::backendFor($persisted, $root);
            } catch (\Throwable $e) {
                fwrite(STDERR, "sugarcrush: persisted provider '{$persisted}' unavailable ({$e->getMessage()}); falling back to echo.\n");
            }
        }

        $loader = self::instructionLoader($root);

        return (new EngineBackend(new EchoProvider(), 'echo'))
            ->withTools(self::tools($root, $loader))
            ->withHooks(self::hooks())
            ->withSkillRegistry(self::skillRegistry($root))
            ->withInstructionLoader($loader);
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
     * @throws \Throwable
     */
    public static function backendFor(string $providerName, ?string $root = null): Backend
    {
        $root ??= getcwd();
        $factory = new ProviderFactory();
        $provider = $factory->create($factory->defaultConfig($providerName));
        $model = getenv('SUGARCRUSH_MODEL') ?: ($factory->defaultConfig($providerName)['model'] ?? 'gpt-4o');

        $loader = self::instructionLoader($root);

        return (new EngineBackend($provider, (string) $model))
            ->withTools(self::tools($root, $loader))
            ->withHooks(self::hooks())
            ->withSkillRegistry(self::skillRegistry($root))
            ->withInstructionLoader($loader);
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
     */
    public static function userConfigPath(): string
    {
        return self::configDir() . '/config.json';
    }

    /**
     * Reads the persisted user config, tolerantly: a missing, unreadable,
     * or invalid-JSON file returns [] rather than throwing - there is
     * nothing yet to persist on a fresh install, and a corrupt file
     * shouldn't block the CLI from starting.
     *
     * @return array<string, mixed>
     */
    public static function readUserConfig(): array
    {
        $path = self::userConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
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
        (new SkillManager(new SkillLoader(), $registry))->loadAll($root);

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
     *
     * @return list<Tool>
     */
    public static function tools(?string $root = null, ?InstructionFileLoader $loader = null): array
    {
        $root ??= getcwd();
        $loader ??= self::instructionLoader($root);

        return [
            new Bash($root),
            new Read($root, instructionLoader: $loader),
            new Edit($root, instructionLoader: $loader),
            new Glob($root, instructionLoader: $loader),
            new Grep($root),
            new WebFetch(),
            new Doctor(),
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
        return new InstructionFileLoader($root ?? getcwd(), self::forcedInstructions());
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
     */
    public static function sessionStore(): EnhancedSessionStore
    {
        return new EnhancedSessionStore(self::configDir() . '/session.db');
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
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        $dir = $home . '/.sugar-crush';
        self::ensureDir($dir);

        return $dir;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
