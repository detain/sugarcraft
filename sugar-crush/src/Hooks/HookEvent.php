<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * Hook lifecycle events that form the escape hatch for anything a permission mode can't express.
 *
 * Exit codes carry the same three-way meaning across every event:
 * - 0 (allow): permits the action; stdout may be shown to user or folded into context
 * - 1 (deny): non-blocking deny — stderr shown to user, execution continues
 * - 2 (block): hard block; effect is event-specific:
 *   - PreToolUse/Stop/TaskCreated: stops action outright, feeds stderr back to agent
 *   - PostToolUse/SubagentStop/TaskCompleted: action already happened, surfaces via continueOnBlock
     *   - PreCompact/SessionStart: stderr only reaches the user (no agent action possible)
 *   - UserPromptSubmit: discards prompt entirely, nothing goes to the agent
 *
 * @see https://github.com/charmbracelet/sugar-craft/blob/master/docs/hooks.md
 */
enum HookEvent: string
{
    case PreToolUse = 'PreToolUse';

    /**
     * Fires in the parent, in provider order, after the tool has run.
     *
     * One caveat when {@see \SugarCraft\Crush\Runtime} dispatches a same-turn
     * batch concurrently: every member of the group is forked BEFORE any of
     * them reaches this event, so a hook here that mutates shared state
     * (writes a file, touches a database) no longer has that mutation
     * observable by a later sibling in the same group, the way it would under
     * sequential dispatch. The number of invocations and their order are
     * unchanged; only the interleave point moved. The safety rule that keeps
     * concurrency invisible in the results covers TOOLS — it cannot cover
     * user-supplied hook bodies. See
     * {@see \SugarCraft\Crush\Tools\ParallelSafe}.
     */
    case PostToolUse = 'PostToolUse';
    case Stop = 'Stop';
    case SubagentStop = 'SubagentStop';
    case SessionStart = 'SessionStart';
    case SessionEnd = 'SessionEnd';
    case UserPromptSubmit = 'UserPromptSubmit';
    case PreCompact = 'PreCompact';
    case TeammateIdle = 'TeammateIdle';
    case TaskCreated = 'TaskCreated';
    case TaskCompleted = 'TaskCompleted';

    /**
     * Returns true if exit code 2 (block) on this event stops the action outright.
     * For these events the action hasn't happened yet, so a block aborts it.
     */
    public function blocksOnPreAction(): bool
    {
        return match ($this) {
            self::PreToolUse,
            self::Stop,
            self::TaskCreated => true,
            default => false,
        };
    }

    /**
     * Returns true if exit code 2 (block) on this event should use continueOnBlock semantics.
     * For these events the action has already happened, so we surface the error via continueOnBlock.
     */
    public function usesContinueOnBlockOnBlock(): bool
    {
        return match ($this) {
            self::PostToolUse,
            self::SubagentStop,
            self::TaskCompleted => true,
            default => false,
        };
    }

    /**
     * Returns true if exit code 2 (block) on this event discards the prompt entirely.
     */
    public function discardsOnBlock(): bool
    {
        return match ($this) {
            self::UserPromptSubmit => true,
            default => false,
        };
    }

    /**
     * Returns true if exit code 2 (block) on this event sends stderr only to the user, never to the agent.
     */
    public function stderrToUserOnly(): bool
    {
        return match ($this) {
            self::PreCompact,
            self::SessionStart => true,
            default => false,
        };
    }
}
