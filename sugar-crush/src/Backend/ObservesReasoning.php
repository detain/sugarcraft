<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * Opt-in declaration that a {@see Backend} can report the model's THINKING
 * live, separately from the reply it is composing (E456/E494).
 *
 * Round 56 carried a reasoning fragment from the provider's chunk, through
 * {@see \SugarCraft\Crush\Runtime::run()}'s `$onProgress`, across
 * {@see EngineBackend::completeAsync()}'s fork as its own `reasoning` frame,
 * and out of a fifth `$onReasoning` parameter — and there it stopped, because
 * {@see \SugarCraft\Crush\Chat} passed four arguments and had no way to know
 * which backends would accept a fifth. This interface is that way.
 *
 * ## Why a capability and NOT a fifth parameter on {@see Backend}
 *
 * MEASURED, not assumed, because the obvious fix is the wrong one. PHP treats
 * an implementation with FEWER parameters than its interface as a load-time
 * fatal — `Declaration of X::completeAsync(…) must be compatible with
 * Backend::completeAsync(…)` — even when the interface's extra parameter is
 * optional. A token-stream census of this package at the time of writing found
 * four-parameter `completeAsync()` declarations spread across eight test files
 * and, by the same rule, in every third-party backend outside this repo;
 * `Backend`'s own docblock advertises it as an extension point ("anything that
 * returns text"). Widening it would break all of them at once, and would do so
 * at `require` time rather than at the call.
 *
 * This is exactly the reasoning {@see ReportsContextWindow} already gives for
 * the same choice, and the shape is deliberately identical, down to the
 * consumer asking "does this backend do X?" rather than "is this an
 * EngineBackend?".
 *
 * ## Why not a bare marker interface
 *
 * Because PHP would then let a backend claim the capability while declaring a
 * four-parameter `completeAsync()`, and the sink would be dropped on the floor
 * with no diagnostic — userland functions accept surplus positional arguments
 * silently. Redeclaring the method here makes the claim a STRUCTURAL fact the
 * engine enforces at load time rather than a promise in prose.
 *
 * ## Why the three other in-repo backends do NOT implement it
 *
 * {@see EchoBackend} quotes the user's own message back; there is no model to
 * think. {@see CommandBackend} and {@see StreamingCommandBackend} shell out to
 * an arbitrary command whose wire protocol is undifferentiated text, so telling
 * a thought from an answer there would mean GUESSING — and a wrong guess on
 * this particular channel moves model output into (or out of) the assistant's
 * own committed words. Not implementing this is the safe default and the honest
 * answer for all three.
 */
interface ObservesReasoning extends Backend
{
    /**
     * {@see Backend::complete()} plus a live observer of the model's thinking.
     *
     * @param callable|null $onReasoning `function(string $delta): void`. A
     *                      DELTA, never a running total. The EMPTY string never
     *                      reaches it: an empty fragment is meaningful one layer
     *                      down — for {@see EngineBackend} it is a heartbeat
     *                      that moves the idle deadline for a chunk with nothing
     *                      to show — and is meaningless as a paint instruction,
     *                      so it is dropped before the caller's sink is called.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null): Message;

    /**
     * {@see Backend::completeAsync()} plus a live observer of the model's
     * thinking.
     *
     * Subject to the same cross-process rule `$onEvent` is: an implementation
     * that moves the work off-process MUST deliver these in the CALLER's
     * process. A callback invoked inside a forked child writes into a copy of
     * the caller's state and vanishes on exit, which is why
     * {@see EngineBackend} carries each fragment back over its socket as its
     * own frame rather than calling the sink where it is produced.
     *
     * @param callable|null $onReasoning see {@see complete()}
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null): PromiseInterface;
}
