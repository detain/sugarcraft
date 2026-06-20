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
     * Pre-tool-use hook execution.
     */
    public function preToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PreToolUse->value, $context);
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
