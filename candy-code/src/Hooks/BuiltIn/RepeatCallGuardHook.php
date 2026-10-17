<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks\BuiltIn;

use SugarCraft\Code\Backend\ToolCallLoopGuard;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookResult;

/**
 * The refusing half of one turn's {@see ToolCallLoopGuard} on the hook chain:
 * denies an identical call (same tool, same canonical arguments) whose last
 * identical runs all returned the same result — from the 5th such call on.
 * Its counting partner is {@see RepeatCallCountHook} on `PostToolUse`.
 *
 * WHY A HOOK and not a check inside {@see \SugarCraft\Code\Runtime}: the hook
 * chain is the one point BOTH of Runtime's dispatch paths (sequential and the
 * concurrent group) already gate on, and Runtime already renders a PreToolUse
 * DENY as a refused call the model reads. Routing the guard through it needs
 * no new seam in Runtime.
 *
 * NOT IN {@see \SugarCraft\Code\Hooks\HookManager::registerBuiltIns()}: the
 * guard's ledger is per TURN, while a hook manager lives for the launch. So
 * {@see \SugarCraft\Code\Backend\EngineBackend} registers a fresh pair on a
 * per-turn copy of its manager — including on a `withoutHooks()` turn, because
 * this is a runaway-loop brake on the harness's own step budget, not one of
 * the permission guards that opt-out exists to drop.
 */
final readonly class RepeatCallGuardHook implements HookInterface
{
    public const NAME = 'repeat-call-guard';

    public function __construct(
        private ToolCallLoopGuard $guard,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        $refusal = $this->guard->beforeCall($context->toolName, $context->toolArgs);

        return $refusal === null ? HookResult::allow() : HookResult::deny($refusal);
    }
}
