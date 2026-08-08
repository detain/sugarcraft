<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * Core hook dispatcher that executes registered hooks for lifecycle events.
 *
 * Exit code semantics (consistent across all events):
 * - 0: allow — action proceeds
 * - 1: non-blocking deny — stderr shown to user, execution continues
 * - 2: hard block — effect depends on event type (see HookEvent for details)
 *
 * @see HookEvent
 */
final class HookDispatcher
{
    public function __construct(
        private HookRegistry $registry,
    ) {}

    /**
     * Dispatch a hook event and return the aggregated result.
     *
     * @param HookEvent $event The event type to dispatch
     * @param HookContext $context The hook context
     * @return HookDispatchResult The aggregated dispatch result
     */
    public function dispatch(HookEvent $event, HookContext $context): HookDispatchResult
    {
        $hooks = $this->registry->getForEvent($event->value);

        if ($hooks === []) {
            return HookDispatchResult::allow($event, $context, 'no hooks registered');
        }

        $lastBlockMessage = '';
        $continueOnBlock = false;
        $modifiedContext = null;

        foreach ($hooks as $hook) {
            if ($this->registry->isDisabled($hook->name())) {
                continue;
            }

            // Skip hooks that don't match the tool (for tool-scoped events)
            if ($this->isToolScopedEvent($event) && !$this->matcherMatches($hook->matcher(), $context->toolName)) {
                continue;
            }

            $result = $hook->execute($context);

            if ($result->isAllowed()) {
                continue;
            }

            if ($result->isModified()) {
                // Only PreToolUse hooks can modify
                if ($event === HookEvent::PreToolUse && $result->modifiedInput !== null) {
                    $modifiedContext = $context->withToolInput($result->modifiedInput);
                    $context = $modifiedContext;
                }
                continue;
            }

            // Deny case — determine exit code from message prefix
            // [exit-1] = non-blocking deny (stderr shown, execution continues)
            // [exit-2] = hard block (event-specific effect)
            $exitCode = $this->determineExitCode($result);

            if ($exitCode === 1) {
                // Non-blocking deny — continue to next hook, but message goes to user
                continue;
            }

            // Exit code 2: hard block
            $lastBlockMessage = $this->stripExitPrefix($result->message);

            if ($event->usesContinueOnBlockOnBlock()) {
                $continueOnBlock = true;
                continue;
            }

            // Every remaining category stops the dispatch loop immediately;
            // resolveBlockMessage() is what actually differs per event.
            return HookDispatchResult::block(
                event: $event,
                context: $context,
                message: $this->resolveBlockMessage($event, $lastBlockMessage),
                continueOnBlock: false,
            );
        }

        if ($continueOnBlock) {
            return HookDispatchResult::block(
                event: $event,
                context: $context,
                message: $lastBlockMessage,
                continueOnBlock: true,
            );
        }

        return HookDispatchResult::allow($event, $context, '');
    }

    /**
     * Returns true if the given pattern matches the tool name.
     *
     * Validates the pattern compiles before use to avoid PREG_* errors
     * from malformed regex patterns in hook matcher() implementations.
     */
    private function matcherMatches(string $pattern, string $toolName): bool
    {
        // Validate the pattern compiles before use
        if (@preg_match('/' . $pattern . '/i', '') === false) {
            return false;
        }

        return preg_match('/' . $pattern . '/i', $toolName) === 1;
    }

    /**
     * Check if an event is tool-scoped (has a toolName matcher).
     */
    private function isToolScopedEvent(HookEvent $event): bool
    {
        return match ($event) {
            HookEvent::PreToolUse,
            HookEvent::PostToolUse,
            HookEvent::TeammateIdle,
            HookEvent::TaskCreated,
            HookEvent::TaskCompleted => true,
            default => false,
        };
    }

    /**
     * Determine exit code from hook result message prefix.
     *
     * Exit codes:
     * - 0: allow (HookResult::isAllowed())
     * - 1: non-blocking deny — signaled by [exit-1] prefix
     * - 2: hard block — signaled by [exit-2] prefix or plain deny
     */
    private function determineExitCode(HookResult $result): int
    {
        $message = $result->message;

        if (str_starts_with($message, '[exit-2]')) {
            return 2;
        }

        if (str_starts_with($message, '[exit-1]')) {
            return 1;
        }

        // Plain deny is treated as hard block (exit code 2)
        if ($result->isDenied()) {
            return 2;
        }

        return 0;
    }

    /**
     * Strip the [exit-N] prefix from a message.
     */
    private function stripExitPrefix(string $message): string
    {
        if (str_starts_with($message, '[exit-2]')) {
            return trim(substr($message, 8));
        }

        if (str_starts_with($message, '[exit-1]')) {
            return trim(substr($message, 8));
        }

        return $message;
    }

    /**
     * Resolve what the hard-block message on the dispatch result should be
     * for a given event, per the effect documented on HookEvent:
     *
     * - discardsOnBlock() (UserPromptSubmit): the prompt is discarded
     *   entirely — nothing, not even the hook's message, survives to reach
     *   the agent. The block still happens; the message is wiped.
     * - blocksOnPreAction() (PreToolUse/Stop/TaskCreated): the action
     *   hasn't happened yet, so the message is fed back to the agent so it
     *   can adjust — preserved as-is.
     * - stderrToUserOnly() (PreCompact/SessionStart): there's no agent turn
     *   to hand the message to at these lifecycle points, so it can only
     *   ever reach the user — preserved as-is so it has somewhere to go.
     * - Any event matching none of the above (e.g. SessionEnd,
     *   TeammateIdle): preserved as-is, same as blocksOnPreAction().
     */
    private function resolveBlockMessage(HookEvent $event, string $blockMessage): string
    {
        if ($event->discardsOnBlock()) {
            return '';
        }

        return $blockMessage;
    }

    // ========================================================================
    // Convenience dispatch methods for each event type
    // ========================================================================

    public function dispatchPreToolUse(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::PreToolUse, $context);
    }

    public function dispatchPostToolUse(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::PostToolUse, $context);
    }

    public function dispatchStop(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::Stop, $context);
    }

    public function dispatchSubagentStop(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::SubagentStop, $context);
    }

    public function dispatchSessionStart(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::SessionStart, $context);
    }

    public function dispatchSessionEnd(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::SessionEnd, $context);
    }

    public function dispatchUserPromptSubmit(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::UserPromptSubmit, $context);
    }

    public function dispatchPreCompact(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::PreCompact, $context);
    }

    public function dispatchTeammateIdle(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::TeammateIdle, $context);
    }

    public function dispatchTaskCreated(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::TaskCreated, $context);
    }

    public function dispatchTaskCompleted(HookContext $context): HookDispatchResult
    {
        return $this->dispatch(HookEvent::TaskCompleted, $context);
    }
}
