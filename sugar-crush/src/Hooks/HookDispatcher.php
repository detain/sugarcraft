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
 * The four event-type distinctions on HookEvent (blocksOnPreAction(),
 * usesContinueOnBlockOnBlock(), discardsOnBlock(), stderrToUserOnly()) are
 * all read by dispatch()/resolveBlockMessage() below and each produce a
 * genuinely different HookDispatchResult, not just metadata:
 * - usesContinueOnBlockOnBlock(): continueOnBlock=true (see dispatch()).
 * - discardsOnBlock(): message is wiped to ''.
 * - stderrToUserOnly(): message is tagged with self::STDERR_ONLY_PREFIX so
 *   a caller can tell this text may only reach the user, never the agent —
 *   see resolveBlockMessage().
 * - blocksOnPreAction(): the action hasn't happened yet, so the message is
 *   preserved verbatim, untagged, for feeding back to the agent.
 * - Any event matching none of the above (currently SessionEnd,
 *   TeammateIdle): tagged with self::UNSPECIFIED_BLOCK_PREFIX — there is no
 *   pending pre-action for these to stop and no agent turn to feed a
 *   verbatim message into, so this is deliberately NOT the same result as
 *   blocksOnPreAction(); see resolveBlockMessage().
 *
 * Known gap (pre-existing, not specific to this dispatcher): the [exit-1]
 * message prefix that determineExitCode()/stripExitPrefix() use to pick the
 * non-blocking-deny path is not emitted by any shipped HookInterface
 * implementation (ScriptHook, BuiltIn/*Hook) — they all call plain
 * HookResult::deny()/ask()/allow(), and everything that does not permit
 * execution resolves to exit code 2 (see determineExitCode()). The exit-1 path
 * is only reachable today from a hook that manually embeds the "[exit-1]"
 * prefix in its message.
 *
 * @see HookEvent
 */
final class HookDispatcher
{
    /**
     * Marker prepended to a hard-block message when HookEvent::stderrToUserOnly()
     * is true for the dispatched event. Signals to callers that this text may only
     * ever reach the user (there is no agent turn at these lifecycle points), which
     * is the one runtime-visible difference from the blocksOnPreAction() case since
     * HookDispatchResult has no dedicated audience field.
     */
    public const STDERR_ONLY_PREFIX = '[stderr-only] ';

    /**
     * Marker prepended to a hard-block message for an event that matches
     * none of blocksOnPreAction(), usesContinueOnBlockOnBlock(),
     * discardsOnBlock(), or stderrToUserOnly() (currently SessionEnd,
     * TeammateIdle). These events have no pending pre-action for a block to
     * stop and no agent turn to feed a verbatim message into, so they must
     * not be silently treated as blocksOnPreAction() — see
     * resolveBlockMessage().
     */
    public const UNSPECIFIED_BLOCK_PREFIX = '[unspecified-block] ';

    /**
     * Marker prepended to the hard-block message a {@see HookResult::ASK}
     * becomes on this path.
     *
     * An ASK is not a refusal — it is a hook with no verdict deferring to the
     * user — but nothing reachable from a {@see HookDispatchResult} can put a
     * question to anybody, so the only honest terminal state is fail-closed.
     * The tag exists because the message that then travels is the QUESTION,
     * and an untagged question fed back to the agent reads as the reason its
     * call was refused ("Allow Bash to run? (permission mode: default)"),
     * which is a statement no hook made.
     */
    public const UNANSWERED_ASK_PREFIX = '[unanswered-ask] ';

    public function __construct(
        private HookRegistry $registry,
    ) {}

    /**
     * Dispatch a hook event and return the aggregated result.
     *
     * REWRITES ARE RE-SCANNED, on the same contract
     * {@see HookRegistry::executeHooks()} carries and for the same reason: every
     * hook in one pass is handed the same {@see HookContext}, so a hook that
     * rewrote `Bash{command:"ls"}` into `Bash{command:"rm -rf /"}` had its
     * rewrite judged by nobody — including {@see BuiltIn\PermissionGateHook},
     * which this dispatcher shares a registry with and which reads `toolArgs`.
     * So a pass whose only non-allowing result is a rewrite is followed by
     * another pass against the REWRITTEN arguments, until the chain settles,
     * re-proposes what it was given (a fixed point), or exhausts
     * {@see HookRegistry::MAX_REWRITE_PASSES} and is blocked. A settled
     * rewrite comes back on {@see HookDispatchResult::$modifiedInput} — the
     * "same contract" claim was false for as long as it did not, because the
     * caller of an ALLOW carrying nothing runs what it already had.
     *
     * The one contract difference that REMAINS, stated rather than hidden:
     * {@see HookResult::ASK} has no representation here. This dispatcher can
     * put a question to nobody — {@see HookDispatchResult} has three exit
     * codes and no answering seam — so an ASK fails closed as a hard block,
     * tagged with {@see self::UNANSWERED_ASK_PREFIX} so the question text is
     * not mistaken for the reason a hook refused something. That is newly
     * reachable: {@see BuiltIn\PermissionGateHook} is the first shipped
     * ASK-emitting built-in, and it registers into the registries a dispatcher
     * wraps.
     *
     * A SECOND, COSMETIC DIVERGENCE FOLLOWS FROM IT, stated here because the
     * two `scan()`s are written to be read against each other:
     * {@see HookRegistry::scan()} lets an ASK STAND ASIDE and keeps scanning,
     * so a DENY behind an ASK is the result; this one has no such slot, so an
     * ASK takes the hard-block exit at once (see {@see self::scan()}) and a
     * DENY behind it never runs. The OUTCOME is the same either way — both are
     * `exitCode=2, continueOnBlock=false` — so only the message differs, and on
     * the events where a block is not terminal
     * ({@see HookEvent::usesContinueOnBlockOnBlock()}) the ASK does not
     * short-circuit at all and there is no divergence to see.
     *
     * Nothing routes `PreToolUse` here today — {@see \SugarCraft\Crush\Agents\TaskList}
     * is the only wiring and it dispatches TaskCreated/TaskCompleted/TeammateIdle
     * only — so this is a dormant seam kept honest rather than a live fix. It
     * is wired to the contract instead of documented as diverging from it
     * precisely because the divergence is invisible until the day somebody
     * routes a tool call through it.
     *
     * That is also why no shipped consumer READS
     * {@see HookDispatchResult::$modifiedInput} yet: only `PreToolUse` records
     * a rewrite (see {@see self::scan()}), and every live caller dispatches
     * some other event, for which the field is null by construction. The
     * contract is complete on this side so that the first caller to route a
     * tool call through here gets the rewrite instead of silently running the
     * arguments it replaced; {@see \SugarCraft\Crush\Runtime::gate()} and
     * {@see \SugarCraft\Crush\Chat::gateToolCall()} are the two worked examples
     * of applying one, both via {@see HookResult::rewrittenArgs()}, which
     * {@see HookDispatchResult::rewrittenArgs()} deliberately mirrors.
     *
     * @param HookEvent $event The event type to dispatch
     * @param HookContext $context The hook context
     * @return HookDispatchResult The aggregated dispatch result
     */
    public function dispatch(HookEvent $event, HookContext $context): HookDispatchResult
    {
        if ($this->registry->getForEvent($event->value) === []) {
            return HookDispatchResult::allow($event, $context, 'no hooks registered');
        }

        $rewritten = null;
        $passes = 0;

        while (true) {
            [$terminal, $modified] = $this->scan($event, $context, $rewritten?->toolInput);

            if ($terminal !== null) {
                return $terminal;
            }

            if ($modified === null) {
                // ALLOW, settled against the arguments in $context — which on
                // any pass after the first are the rewritten ones, and have to
                // be REPORTED as such: an ALLOW carrying no modifiedInput
                // tells the caller to run what it already has, so settling a
                // rewrite through the plain factory ran the arguments the
                // rewrite existed to replace and burned the re-scan for
                // nothing.
                return $rewritten === null
                    ? HookDispatchResult::allow($event, $context, '')
                    : HookDispatchResult::allowRewritten($event, $context, '');
            }

            // The chain re-proposing the rewrite it was just handed is a fixed
            // point, not ping-pong: it has agreed, and settles without
            // spending the budget.
            if ($rewritten !== null && $modified->toolInput === $rewritten->toolInput) {
                return HookDispatchResult::allowRewritten($event, $modified, '');
            }

            if (++$passes > HookRegistry::MAX_REWRITE_PASSES) {
                // Two mutually-rewriting hooks would re-scan forever, and the
                // only safe terminal state is a block: a chain that cannot
                // agree on what it is about to run has approved nothing.
                return HookDispatchResult::block(
                    event: $event,
                    context: $context,
                    message: $this->resolveBlockMessage($event, sprintf(
                        'Hooks kept rewriting the arguments for %s (%d rewrites without settling); '
                        . 'refusing to run any of them.',
                        $context->toolName,
                        HookRegistry::MAX_REWRITE_PASSES,
                    )),
                    continueOnBlock: false,
                );
            }

            $rewritten = $modified;
            $context = $modified;
        }
    }

    /**
     * One full pass over the matching hooks.
     *
     * $settled IS WHY A FIXED POINT DOES NOT WIN THE PASS OUTRIGHT, the same
     * rule and the same reason as {@see HookRegistry::scan()}: {@see dispatch()}
     * reads "the chain has stopped proposing anything new" off the one rewrite
     * this returns, so reporting the pass's first usable rewrite unconditionally
     * decided that from ONE hook. A hook that re-proposes the same rewrite every
     * pass is a fixed point from pass 2 onward and, being first, settled the
     * chain while a SANITISER behind it — which had by then seen the rewritten
     * arguments and asked for more — was silently dropped, and the call ran
     * unsanitised. The outcome was order-dependent and the permissive order was
     * the unsanitised one. A fixed point is therefore kept only as the FALLBACK,
     * for the pass that turned up nothing else — which is the case
     * {@see dispatch()}'s own fixed-point test is written for.
     *
     * @param ?string $settled the `toolInput` {@see dispatch()} has already
     *        settled on, or null on the first pass
     *
     * @return array{0: ?HookDispatchResult, 1: ?HookContext} [the result that
     *     ends the dispatch outright, the context carrying this pass's rewrite]
     *     — at most one of which is ever non-null
     */
    private function scan(HookEvent $event, HookContext $context, ?string $settled = null): array
    {
        $lastBlockMessage = '';
        $continueOnBlock = false;
        $rewritten = null;
        $fixedPoint = null;

        foreach ($this->registry->getForEvent($event->value) as $hook) {
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
                // Only PreToolUse hooks can modify. Rewrites do not COMPOSE
                // within one pass — a second hook's rewrite was computed
                // against input the first one already replaced — so the first
                // USABLE one wins and the re-scan is where the rest of the
                // chain gets to see what actually happened. "Usable" is the
                // same rule {@see HookRegistry::scan()} applies: an inert
                // rewrite is a no-op for every consumer, so letting it win
                // would discard a later hook's real one and run arguments
                // nobody proposed. Notably the context is NOT swapped mid-pass
                // any more: doing that let hooks that had already run escape
                // judging the rewrite while making it look like they had seen
                // it.
                if ($event === HookEvent::PreToolUse) {
                    $candidate = self::rewrite($context, $result);

                    if ($candidate === null) {
                        continue;
                    }

                    if ($settled !== null && $candidate->toolInput === $settled) {
                        // Proposes exactly what the chain already settled on:
                        // keep it, but keep looking — see $settled above.
                        $fixedPoint ??= $candidate;
                    } else {
                        $rewritten ??= $candidate;
                    }
                }
                continue;
            }

            // Deny case — determine exit code from message prefix
            // [exit-1] = non-blocking deny (stderr shown, execution continues)
            // [exit-2] = hard block (event-specific effect)
            $exitCode = $this->determineExitCode($result);

            if ($exitCode === HookDispatchResult::EXIT_DENY) {
                // Non-blocking deny — continue to next hook, but message goes to user
                continue;
            }

            // Exit code 2: hard block. An ASK arrives here too — see
            // self::UNANSWERED_ASK_PREFIX for why it fails closed and why its
            // message may not travel as though a hook had authored it.
            $lastBlockMessage = $result->isAsk()
                ? self::UNANSWERED_ASK_PREFIX . $result->message
                : $this->stripExitPrefix($result->message);

            if ($event->usesContinueOnBlockOnBlock()) {
                $continueOnBlock = true;
                continue;
            }

            // Every remaining category stops the dispatch loop immediately;
            // resolveBlockMessage() is what actually differs per event.
            return [
                HookDispatchResult::block(
                    event: $event,
                    context: $context,
                    message: $this->resolveBlockMessage($event, $lastBlockMessage),
                    continueOnBlock: false,
                ),
                null,
            ];
        }

        if ($continueOnBlock) {
            return [
                HookDispatchResult::block(
                    event: $event,
                    context: $context,
                    message: $lastBlockMessage,
                    continueOnBlock: true,
                ),
                null,
            ];
        }

        return [null, $rewritten ?? $fixedPoint];
    }

    /**
     * $context carrying a hook's rewrite, or null when there is nothing usable
     * to carry.
     *
     * {@see HookContext::withRewrittenArgs()}, never `withToolInput()`: that
     * one moves the JSON text alone and leaves `toolArgs` describing the call
     * the rewrite REPLACED, which is what every built-in guard and
     * {@see BuiltIn\PermissionGateHook} read — so a re-scan built on it would
     * re-judge the old arguments and report a verdict on a call that is never
     * going to run.
     *
     * A rewrite that will not decode to an argument map is INERT, exactly as
     * it is in {@see HookRegistry::executeHooks()}: both tool-call consumers
     * fall back to the originals for it, and the originals are what this pass
     * just judged, so there is nothing new to re-scan. What counts as an
     * argument map is {@see HookResult::rewrittenArgs()}' single answer for all
     * four consumers of a rewrite — notably it refuses a top-level JSON LIST,
     * which `is_array()` alone accepted and which then made `toolArgs` a
     * positional list no guard's `$args['command']` could see.
     */
    private static function rewrite(HookContext $context, HookResult $result): ?HookContext
    {
        $decoded = $result->rewrittenArgs();

        return $decoded === null
            ? null
            : $context->withRewrittenArgs($decoded, (string) $result->modifiedInput);
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
     * Only ever reached for a result that neither allows nor modifies, so the
     * candidates are a DENY, an {@see HookResult::ASK}, and an action string
     * this class does not recognise. Every one of them is a hard block unless
     * it explicitly asked to be non-blocking:
     *
     * - 1: non-blocking deny — signaled by an [exit-1] prefix, and ONLY by it
     * - 2: everything else — an [exit-2] prefix, a plain deny, an ASK nothing
     *   on this path can answer, or an action from the future
     *
     * The old fallback was 0, which no caller of this method could act on: an
     * ASK returned 0, failed the `=== 1` test, and fell into the hard-block
     * branch anyway — fail-closed by accident, and with the question text
     * reported as a refusal reason. Returning 2 makes that deliberate and
     * closes the same door on any action string added later, for the reason
     * {@see HookResult::permitsExecution()} is an allow-list.
     */
    private function determineExitCode(HookResult $result): int
    {
        if (str_starts_with($result->message, '[exit-1]') && !$result->isAsk()) {
            return HookDispatchResult::EXIT_DENY;
        }

        return HookDispatchResult::EXIT_BLOCK;
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
     * - stderrToUserOnly() (PreCompact/SessionStart): there's no agent turn
     *   to hand the message to at these lifecycle points, so it can only
     *   ever reach the user. Tagged with self::STDERR_ONLY_PREFIX — the one
     *   runtime-visible signal (short of a dedicated HookDispatchResult
     *   field) that this text must not be fed back into agent context,
     *   which is exactly what makes it differ from blocksOnPreAction().
     * - blocksOnPreAction() (PreToolUse/Stop/TaskCreated): the action
     *   hasn't happened yet, so the message is fed back to the agent so it
     *   can adjust — preserved as-is, untagged.
     * - Any event matching none of the above (e.g. SessionEnd,
     *   TeammateIdle): there is no pending pre-action to stop and no agent
     *   turn to feed a verbatim message into, so this must NOT collapse
     *   into the same result as blocksOnPreAction() — tagged with
     *   self::UNSPECIFIED_BLOCK_PREFIX so the two are byte-distinguishable.
     */
    private function resolveBlockMessage(HookEvent $event, string $blockMessage): string
    {
        if ($event->discardsOnBlock()) {
            return '';
        }

        if ($event->stderrToUserOnly()) {
            return self::STDERR_ONLY_PREFIX . $blockMessage;
        }

        if ($event->blocksOnPreAction()) {
            return $blockMessage;
        }

        return self::UNSPECIFIED_BLOCK_PREFIX . $blockMessage;
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
