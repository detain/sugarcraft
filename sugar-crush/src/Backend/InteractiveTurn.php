<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;

/**
 * Opt-in declaration that a {@see Backend} can put a tool-permission ASK
 * raised INSIDE a running turn to its caller, and take the answer back
 * (roadmap 1.C-1 = Appendix O §5.1).
 *
 * A separate method rather than a flag on `completeAsync()`, and a side
 * interface rather than a wider {@see Backend}, for the reasons
 * {@see ObservesReasoning} gives: a parameter added to the base contract is a
 * load-time fatal for every narrower implementation. A separate METHOD
 * matters for a further reason of its own: the promise this makes is a duty
 * on the CALLER. A turn started here blocks on every question until somebody
 * answers it, so a caller that merely passes `$onEvent` through — and would
 * drop a {@see \SugarCraft\Crush\Events\PermissionAsked} on the floor — must
 * not get this behaviour by accident. Plain `completeAsync()` keeps settling
 * every ASK the way it always has (the attached approver, or the fail-closed
 * "no approver is attached" refusal).
 */
interface InteractiveTurn extends Backend
{
    /**
     * {@see Backend::completeAsync()}, plus a two-way permission channel.
     *
     * Every ASK the turn raises reaches `$onEvent` — in the turn's own event
     * order, between the tool events around it — as a
     * {@see \SugarCraft\Crush\Events\PermissionAsked} carrying a
     * {@see PendingAsk}. The receiver answers by calling
     * {@see PendingAsk::reply()}, now or from a later `Msg`; until it does,
     * the turn waits and its idle ceiling is paused. Every question is
     * settled exactly once, and the settlement is reported on the same
     * channel as a {@see \SugarCraft\Crush\Events\PermissionResolved}: the
     * receiver's own reply, or `cancelled` when the turn ends (cancel, the
     * idle ceiling, a dead child) with the question still open. A reply after
     * that is a harmless no-op.
     *
     * Where the turn cannot run off the caller's loop (no ext-pcntl), a
     * question can only be answered synchronously, from inside the
     * `$onEvent` call that delivered it. One left open there settles
     * `cancelled` at once and falls back to the backend's attached
     * synchronous approver, if any; with none, it is refused.
     *
     * With `$onEvent` null there is nobody to ask, and this is exactly
     * `completeAsync()`.
     *
     * @param callable|null $onEvent `function(object $event): void` — also
     *                               receives PermissionAsked/PermissionResolved
     * @param callable|null $onStep  `function(StepStarted|UsageUpdated $event): void`
     *                               — each step's start (number, context
     *                               pressure) and each response's usage while
     *                               the turn runs (roadmap 1.C-4); display only
     */
    public function completeInteractive(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onStep = null): PromiseInterface;
}
