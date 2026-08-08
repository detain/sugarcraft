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
use SugarCraft\Crush\Tools\BuiltIn\Bash;
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
        return new Chat(
            backend: self::backend($root),
            memoryStore: self::memoryStore(),
            sessionStore: self::sessionStore(),
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
     *   3. (default) the offline EchoProvider, still run through the engine so the
     *      binary launches with zero network and zero config.
     */
    public static function backend(?string $root = null): Backend
    {
        $root ??= getcwd();
        $tools = self::tools($root);

        $hooks = new HookManager(new HookRegistry());
        $hooks->registerBuiltIns(); // audit + confirm-rm + protect-files guards

        $providerType = getenv('SUGARCRUSH_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                $factory = new ProviderFactory();
                $provider = $factory->create($factory->defaultConfig($providerType));
                $model = getenv('SUGARCRUSH_MODEL') ?: ($factory->defaultConfig($providerType)['model'] ?? 'gpt-4o');

                return (new EngineBackend($provider, (string) $model))
                    ->withTools($tools)
                    ->withHooks($hooks);
            } catch (\Throwable $e) {
                fwrite(STDERR, "sugarcrush: provider '{$providerType}' unavailable ({$e->getMessage()}); falling back to echo.\n");
            }
        }

        $cmd = getenv('SUGARCRUSH_BACKEND_CMD');
        if ($cmd !== false && $cmd !== '') {
            return new CommandBackend($cmd);
        }

        return (new EngineBackend(new EchoProvider(), 'echo'))
            ->withTools($tools)
            ->withHooks($hooks);
    }

    /**
     * Build the built-in coding tools, with a shared InstructionFileLoader
     * wired into every tool that surfaces nested CLAUDE.md/AGENTS.md content
     * (Read/Edit/Glob) so those files are actually reachable when a user
     * runs the real CLI binary.
     *
     * @return list<Tool>
     */
    public static function tools(?string $root = null): array
    {
        $root ??= getcwd();
        $loader = self::instructionLoader($root);

        return [
            new Bash($root),
            new Read($root, instructionLoader: $loader),
            new Edit($root, instructionLoader: $loader),
            new Glob($root, instructionLoader: $loader),
            new Grep($root),
            new WebFetch(),
        ];
    }

    public static function instructionLoader(?string $root = null): InstructionFileLoader
    {
        return new InstructionFileLoader($root ?? getcwd());
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
