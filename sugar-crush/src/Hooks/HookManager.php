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
     * Load hooks from a YAML/JSON hook file.
     *
     * INTENTIONALLY DORMANT: nothing in `src/` or `bin/` calls this, so on a
     * stock `bin/sugarcrush` run the only hooks that exist are the built-ins
     * {@see registerBuiltIns()} registers plus whatever
     * {@see \SugarCraft\Crush\Cli\Bootstrap} hands over. This is the seam an
     * EMBEDDER uses to install its own hooks, and it is also the seam that
     * makes {@see ScriptHook}'s `exit 3`/`exit 4` (ASK and MODIFY) reachable
     * from configuration rather than only from hand-written PHP — the
     * reachability {@see HookRegistry::executeHooks()} and
     * {@see HookRegistry::isReserved()} are written against. Wiring a
     * discovery path for it (`~/.sugar-crush/hooks.yaml` and friends) is a
     * separate step; until then, treat every "reachable from a plain hook
     * file" claim in this package as "reachable to an embedder".
     */
    public function loadFromFile(string $path): void
    {
        $configs = HookConfig::loadFromFile($path);

        foreach ($configs as $config) {
            $hook = ScriptHook::fromConfig($config);
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
