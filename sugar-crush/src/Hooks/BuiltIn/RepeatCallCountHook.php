<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Backend\ToolCallLoopGuard;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * The counting half of one turn's {@see ToolCallLoopGuard}: records every call
 * that RAN with a hash of its result, and from the 3rd identical call on
 * appends the guard's warning to the result as model-visible
 * `additionalContext` — the seam {@see \SugarCraft\Crush\Runtime::settle()}
 * already appends a permitting PostToolUse note through.
 *
 * Always permits: the call has run, and a warning must never withhold its
 * output. Registered per turn beside {@see RepeatCallGuardHook}, which carries
 * the reasoning for both.
 */
final readonly class RepeatCallCountHook implements HookInterface
{
    public const NAME = 'repeat-call-count';

    public function __construct(
        private ToolCallLoopGuard $guard,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        return HookResult::allow('', $this->guard->afterCall($context->toolName, $context->toolArgs, $context->toolOutput));
    }
}
