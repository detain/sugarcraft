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
     * Load hooks from config file.
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

        return $approved ? HookResult::allow($message) : HookResult::deny($message);
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
