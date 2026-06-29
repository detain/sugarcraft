<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

final readonly class ConfirmRemoveHook implements HookInterface
{
    public function name(): string
    {
        return 'confirm-rm';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^Bash$';
    }

    public function execute(HookContext $context): HookResult
    {
        $command = $context->toolArgs['command'] ?? '';

        if (preg_match('/\brm\b[^\n]*\s-[a-z]*[rf]/i', $command)) {
            return HookResult::deny(
                'This hook prevents recursive/force rm. Use interactive rm instead.'
            );
        }

        return HookResult::allow();
    }
}
