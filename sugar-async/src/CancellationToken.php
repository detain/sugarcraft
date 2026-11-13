<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Read-only view of a cancellation state.
 *
 * Instances are created by CancellationSource. Consumers receive only the
 * token and treat it as observe-only: the trigger restriction is by
 * convention, not by visibility — {@see self::notifyCancellation()} and
 * {@see self::fireCallbacks()} are public `@internal` seams, so a consumer
 * holding the token can fire it. Register callbacks, never trigger them,
 * and do not hand tokens to untrusted code.
 *
 * Mirrors Go's context.Context.Done channel pattern.
 */
final class CancellationToken
{
    private bool $cancelled;

    /** @var array<int, callable(): void>  Callbacks keyed by registration id. */
    private array $callbacks;

    private int $nextCallbackId;

    private bool $callbacksFired;

    /** Shared never-cancelled token (see {@see self::none()}). */
    private static ?self $never = null;

    /** Eternal tokens are the shared sentinel: never cancellable, never retaining. */
    private bool $eternal = false;

    /**
     * @param CancellationSource $source  Minting authority; deliberately NOT
     *     retained. Cancellation requires a live source handle on the caller
     *     side, so a token-side reference is dead weight that would only turn
     *     a refcount teardown into a cycle-GC candidate. The parameter stays
     *     as the construction contract: a token is only ever minted for a
     *     source.
     */
    public function __construct(
        CancellationSource $source,
    ) {
        $this->cancelled = false;
        $this->callbacksFired = false;
        $this->nextCallbackId = 0;
        $this->callbacks = [];
    }

    /**
     * The shared never-cancelled token.
     *
     * For call sites that want the read-side shape without the wire: helpers
     * can default a missing token to this instead of minting a throwaway
     * source per call. isCancelled() is always false and onCancel() never
     * retains (nor ever fires) the callback — the returned handle is born
     * spent — so handing out none() cannot accumulate registrations on a
     * process-global object.
     */
    public static function none(): self
    {
        if (self::$never === null) {
            $sentinel = new self(CancellationSource::new());
            $sentinel->eternal = true;
            self::$never = $sentinel;
        }

        return self::$never;
    }

    /**
     * Returns true if the source has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * @internal  Called by CancellationSource::cancel() to trigger cancellation.
     *            Consumers must use CancellationSource::cancel() — not this method.
     */
    public function notifyCancellation(): void
    {
        if ($this->eternal === true) {
            throw new \LogicException('CancellationToken::none() is the shared never-cancelled sentinel and cannot be cancelled');
        }
        if ($this->cancelled === true) {
            return;
        }
        $this->cancelled = true;
        $this->fireCallbacks();
    }

    /**
     * Register a callback to fire when cancellation is requested.
     * Public contract (mirrors {@see Cancellable::onCancel()} — the contract
     * itself makes it public, no visibility games).
     * Fires immediately if the token is already cancelled, otherwise runs at
     * most once, when cancel() happens.
     *
     * The returned handle removes the registration while it is still pending:
     * unsubscribe() before the cancellation drops the callback without
     * running it. Long-lived tokens reused across many short operations
     * MUST unsubscribe at settlement (see AsyncOps::cancellable() and
     * AsyncOps::retry() for the pattern) — otherwise one closure accumulates
     * per operation until the token fires.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): Subscription
    {
        if ($this->eternal === true) {
            // The sentinel never fires: nothing is retained and the handle is
            // born spent, so a caller-wide default needs no cleanup path.
            return CallbackSubscription::settled();
        }
        if ($this->cancelled) {
            // Already cancelled — fire immediately; the handle is born spent.
            $callback();
            return CallbackSubscription::settled();
        }
        $id = $this->nextCallbackId++;
        $this->callbacks[$id] = $callback;

        return CallbackSubscription::of(function () use ($id): void {
            unset($this->callbacks[$id]);
        });
    }

    /**
     * @internal
     */
    public function fireCallbacks(): void
    {
        if ($this->eternal === true) {
            throw new \LogicException('CancellationToken::none() is the shared never-cancelled sentinel and has no cancellation to fire');
        }
        if ($this->callbacksFired === true) {
            return;
        }
        // Callbacks fire ONLY as part of a cancellation, so firing IMPLIES
        // cancelled — clearing the list while leaving isCancelled()
        // false would desynchronise every observer on the direct
        // (@internal-misuse) call path and turn late onCancel() registrations
        // into ghosts that would never run. The legitimate flow enters
        // through notifyCancellation(), which has already raised the flag, so
        // external cancel-detection semantics for real cancels are unchanged.
        //
        // The flag and the fired mark are raised BEFORE the loop, so an
        // onCancel() registered by a callback during the fire sees a
        // cancelled token and runs immediately instead of haunting an
        // already-cleared list.
        $this->cancelled = true;
        $this->callbacksFired = true;
        // Cancellation must never fail the canceller: a throwing callback is
        // logged, not propagated. This is the single hook-failure contract
        // shared with Subscriptions::disposeAll() and the AsyncOps abort
        // hooks — teardown ladders (Ctrl-C handlers, destructors, session
        // shutdown) run to completion, and every remaining callback still
        // executes.
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                \error_log('sugar-async: cancellation callback failed: ' . $e->getMessage());
            }
        }
        $this->callbacks = [];
    }
}
