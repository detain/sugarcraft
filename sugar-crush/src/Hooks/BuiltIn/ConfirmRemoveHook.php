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
        return '^rm$';
    }

    public function execute(HookContext $context): HookResult
    {
        $input = $context->toolInput;

        // Check for recursive or force remove
        if (preg_match('/rm\s+-[rf]+\s+/', $input)) {
            return HookResult::deny(
                'This hook prevents recursive/force rm. Use interactive rm instead.'
            );
        }

        return HookResult::allow();
    }
}
