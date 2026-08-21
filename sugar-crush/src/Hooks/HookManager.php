<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * Manages hook loading and execution.
 */
final class HookManager
{
    public function __construct(
        private HookRegistry $registry,
    ) {}

    /**
     * Load hooks from a YAML hook file, adding each one to the chain.
     *
     * LIVE since crush_code.md Phase 2 item 5:
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} calls this for
     * `~/.sugar-crush/hooks.yaml` — and for `{root}/.sugar-crush/hooks.yaml`
     * only when the user has TRUSTED that project, since a hook entry is a
     * shell command and a project file arrives with whatever was cloned (see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()}) — after
     * {@see registerBuiltIns()}, which is what makes {@see ScriptHook}'s
     * `exit 3`/`exit 4` (ASK and MODIFY) reachable from configuration rather
     * than only from hand-written PHP — the reachability
     * {@see HookRegistry::executeHooks()} and {@see HookRegistry::isReserved()}
     * are written against. Embedders still call it directly for their own
     * files.
     *
     * A LOADED HOOK MAY ONLY ADD TO THE CHAIN, NEVER REPLACE ANYTHING IN IT.
     * {@see HookRegistry::register()} keys by event+name and overwrites, so
     * without the guard below a file saying `name: confirm-rm` on
     * `event: PreToolUse` would UNINSTALL {@see BuiltIn\ConfirmRemoveHook} — a
     * config file switching a guard off by naming it, which is the same hole
     * {@see HookRegistry::isReserved()} closes for the permission gate, and
     * which a file the CLI now reads by convention makes reachable. Refusing
     * BOTH HALVES OF THAT EXAMPLE ARE LOAD-BEARING, and this line spent its
     * whole life naming the wrong one. It read `confirm-remove` — the CLASS
     * name's spelling, not the hook's. {@see BuiltIn\ConfirmRemoveHook::name()}
     * returns `confirm-rm`, so `confirm-remove` is the one name here that
     * collides with nothing and is quietly ACCEPTED as an additional hook.
     * The example demonstrated the opposite of the outcome it claimed, which
     * `docs/HOOKS.md`'s own table has stated correctly the whole time
     * (`confirm-rm` refused, `confirm-remove` accepted). The event half matters
     * for the same reason: the key is event PLUS name, so `confirm-rm` on
     * `PostToolUse` is also accepted. A guard example that names neither
     * coordinate precisely is not an example of the guard.
     *
     * The guard itself was never wrong — it asks
     * {@see HookRegistry::get()} for `$event` + `$name` and hardcodes no list,
     * so it protects whatever is registered. Only this prose was.
     *
     * Refusing is also what keeps the outcome independent of WHICH file was
     * loaded first: a project `.sugar-crush/hooks.yaml` cannot disarm a hook the
     * user wrote in their home directory by reusing its name, in either
     * registration order.
     *
     * @throws \RuntimeException when the file exists and cannot be read
     * @throws \InvalidArgumentException when the file cannot be used, or when
     *         one of its hooks would displace an already-registered hook
     */
    public function loadFromFile(string $path): void
    {
        $this->loadEntries(HookConfig::loadFromFile($path), $path);
    }

    /**
     * {@see loadFromFile()} with the parse already done — same registration
     * contract, same refusals, $source only naming the file the entries came
     * from so the messages stay actionable.
     *
     * Split out for {@see \SugarCraft\Crush\Cli\Bootstrap::hookFileEntries()},
     * which reads each hook file ONCE PER LAUNCH and replays the entries into
     * every hook manager it builds, so a file appearing or changing mid-session
     * cannot install shell into a chain the launch already vetted. Embedders
     * that just want "load my file" keep calling loadFromFile().
     *
     * @param array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float}> $configs
     *
     * @throws \InvalidArgumentException when an entry would displace an
     *         already-registered hook
     */
    public function loadEntries(array $configs, string $source): void
    {
        foreach ($configs as $config) {
            // `disabled: true` MEANS NOT IN THE CHAIN, and it means it by not
            // registering rather than by calling {@see HookRegistry::disable()}.
            // That registry method keys by NAME ALONE, across every event, so
            // routing a config file's disable through it would let an entry
            // named after a hook registered on a DIFFERENT event switch that
            // one off — a config file disarming a guard by naming it, which is
            // the hole {@see HookRegistry::isReserved()} and the
            // already-registered refusal below exist to close, re-opened by
            // the back door. The entry is still fully VALIDATED first (see
            // {@see HookConfig::parse()}), so `disabled: true` beside a
            // misspelled key or an uncompilable matcher still stops the launch.
            if ($config['disabled'] ?? false) {
                continue;
            }

            $hook = ScriptHook::fromConfig($config);
            $event = $hook->event()->value;
            $name = $hook->name();

            if ($this->registry->get($event, $name) !== null) {
                throw new \InvalidArgumentException(
                    "{$source}: a hook named '{$name}' is already registered for {$event}; "
                    . 'a hook file may add to the chain but may not replace what is already in it.',
                );
            }

            $this->registry->register($hook);
        }
    }

    /**
     * Register built-in hooks.
     */
    public function registerBuiltIns(): void
    {
        $this->registry->register(new BuiltIn\ProtectFilesHook());
        $this->registry->register(new BuiltIn\ConfirmRemoveHook());
        $this->registry->register(new BuiltIn\AuditHook());
    }

    /**
     * Register a custom hook.
     */
    public function register(HookInterface $hook): void
    {
        $this->registry->register($hook);
    }

    /**
     * The hook registered for $event under $name, or null.
     *
     * A reader rather than an exposed registry, so a caller can find the one
     * hook it needs — {@see \SugarCraft\Crush\Chat} recovering the launch's
     * {@see BuiltIn\PermissionGateHook} to carry its gate across a provider
     * switch — without gaining the ability to re-key the chain from outside.
     */
    public function hook(string $event, string $name): ?HookInterface
    {
        return $this->registry->get($event, $name);
    }

    /**
     * Pre-tool-use hook execution.
     *
     * A HookResult::ask() decision is passed through verbatim, not collapsed
     * into allow/deny: only the caller owns the UI that can answer it. Until
     * the blocking permission-request flow lands, Runtime's gate treats ASK as
     * not-permitted (HookResult::permitsExecution() is false for it), so an
     * unanswered ASK fails closed rather than running the tool.
     */
    public function preToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PreToolUse->value, $context);
    }

    /**
     * Turn an answered ASK into a settled ALLOW or DENY.
     *
     * ASK is the one action that cannot settle itself: the PreToolUse gate
     * returns it, the UI puts the question to the user, and the answer comes
     * back here. This lives on HookManager rather than HookResult because a
     * HookResult is a readonly value with no notion of a user or a session.
     *
     * @param HookResult $ask the ASK decision preToolUse() returned
     * @param bool $approved true when the user permitted the call
     * @param string $feedback optional "reject with feedback" text that
     *                         replaces the hook's own prompt in the settled
     *                         result's message
     * @throws \InvalidArgumentException when $ask is not an ASK — an already
     *         settled decision must not be re-resolved, since doing so is a
     *         path from DENY to ALLOW
     */
    public function resolveAsk(HookResult $ask, bool $approved, string $feedback = ''): HookResult
    {
        if (!$ask->isAsk()) {
            throw new \InvalidArgumentException(
                "Cannot resolve a '{$ask->action}' hook result: only an ask awaits a user decision.",
            );
        }

        $message = $feedback !== '' ? $feedback : $ask->message;

        if (!$approved) {
            return HookResult::deny($message);
        }

        // An ASK raised over a call an earlier hook already REWROTE settles as
        // that rewrite, not as a bare allow: the question was put about the
        // rewritten arguments ({@see HookRegistry::executeHooks()} re-scans
        // against them), so dropping the rewrite here would run the originals
        // the user was never asked about.
        return $ask->modifiedInput === null
            ? HookResult::allow($message)
            : HookResult::modify($ask->modifiedInput, $message);
    }

    /**
     * Post-tool-use hook execution.
     */
    public function postToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PostToolUse->value, $context);
    }

    /**
     * Apply hooks to a tool call input.
     */
    public function applyPreHooks(
        string $toolName,
        string $input,
        HookContext $baseContext,
    ): HookResult {
        $context = $baseContext->withToolInput($input);
        return $this->preToolUse($context);
    }
}
