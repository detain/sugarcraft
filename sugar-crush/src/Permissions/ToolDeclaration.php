<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * A tool a definition SAYS it will use — a name, and deliberately nothing else.
 *
 * This type exists to keep two questions apart that a {@see ToolCall} cannot,
 * because the pre-flight check that reads a definition and the gating of a real
 * call are asked of the same {@see PermissionGate} and only one of them may
 * touch its state:
 *
 * - {@see PermissionGate::evaluate()} settles an ACTUAL call. In
 *   {@see PermissionMode::Auto} that decision is recorded — it advances the
 *   consecutive-block counter that escalates the third strike of one category
 *   to `Ask` so a human sees it. Calling it is therefore a write.
 * - {@see PermissionGate::refuses()} answers a DECLARATION, reads nothing that
 *   is not on the declaration and writes nothing at all.
 *
 * The first version of {@see \SugarCraft\Crush\Workflows\WorkflowEngine}'s
 * declaration pre-check called `evaluate()` with a `new ToolCall($name)`, which
 * type-checked perfectly and was wrong in the one way that matters: a
 * name-only call is unclassifiable, so `Auto` took its safe branch and RESET
 * the strike counter on the session's one gate. Two real denials followed by
 * one declaration probe left the counter at 0, and the three-strike escalation
 * never fired again for the rest of the session.
 *
 * So the separation is enforced by the TYPE, not by a comment asking callers to
 * remember: `refuses()` accepts only this class, `evaluate()` accepts only a
 * `ToolCall`, and neither will take the other's argument. A caller holding a
 * declaration cannot reach the mutating path without building a `ToolCall` by
 * hand — which is a visible act at the call site rather than an easy mistake.
 * There is intentionally no `toToolCall()` here for the same reason.
 */
final class ToolDeclaration
{
    /**
     * @param string $name The tool name as the definition spells it, e.g.
     *        `Bash`, `Edit`, `mcp__git__push`. No arguments: a declaration has
     *        none, which is exactly why an argument-sensitive rule cannot be
     *        settled from one — see {@see PermissionGate::refuses()}.
     */
    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * The name-only {@see ToolCall} the gate's shared decision path needs.
     *
     * Internal to the permissions namespace by convention — it is `@internal`
     * rather than private because {@see PermissionGate} is a separate class,
     * and it is NOT called `toToolCall()` because the name a caller would
     * reach for when they want to gate something must not be the one that
     * quietly hands them the mutating path's argument.
     *
     * @internal Used by {@see PermissionGate::refuses()} only.
     */
    public function asNamedCallForGateOnly(): ToolCall
    {
        return new ToolCall($this->name);
    }
}
