<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

/**
 * Immutable context object carrying cancellation, deadlines, and
 * key-value metadata across the middleware chain.
 *
 * Mirrors Go's context.Context — each `with*()` method returns a new
 * Context derived from the receiver, forming a parent chain. Callers
 * can attach arbitrary metadata via `withValue()` and short-circuit
 * the chain by calling `cancel()`. Deadline-expiry also sets `done`.
 */
final class Context
{
    private bool $cancelled = false;

    private ?\Throwable $cancelErr = null;

    /**
     * @param array<string, mixed> $ownValues Values attached at this node only
     */
    private function __construct(
        private readonly ?Context $parent,
        private readonly array $ownValues,
        private readonly ?\DateTimeImmutable $deadline,
        private readonly bool $cancelable,
    ) {}

    /**
     * Root context — never done, no values, not cancelable.
     */
    public static function background(): self
    {
        return new self(
            parent: null,
            ownValues: [],
            deadline: null,
            cancelable: false,
        );
    }

    /**
     * Attach a key-value pair and return a new derived Context.
     *
     * The value is stored on the new Context; inherited keys are
     * still reachable via the parent chain (lookup walks upward).
     *
     * @param mixed $v
     */
    public function withValue(string $k, mixed $v): self
    {
        return new self(
            parent: $this,
            ownValues: [$k => $v],
            deadline: $this->deadline,
            cancelable: $this->cancelable,
        );
    }

    /**
     * Return a new derived Context with the given deadline.
     *
     * When the deadline expires, the context is considered done
     * (equivalent to calling `cancel()` with a deadline error).
     */
    public function withDeadline(\DateTimeImmutable $deadline): self
    {
        return new self(
            parent: $this,
            ownValues: $this->ownValues,
            deadline: $deadline,
            cancelable: true,
        );
    }

    /**
     * Return a new derived Context with an active cancellation signal.
     */
    public function withCancelable(): self
    {
        return new self(
            parent: $this,
            ownValues: $this->ownValues,
            deadline: $this->deadline,
            cancelable: true,
        );
    }

    /**
     * Mark this Context (and all derived contexts) as cancelled.
     *
     * Only contexts created with `withCancelable()` can be cancelled.
     * Once cancelled, `done()` returns true and `err()` returns the
     * supplied error (or a generic CancellationException if null).
     */
    public function cancel(?\Throwable $reason = null): void
    {
        if (!$this->cancelable) {
            return;
        }
        $this->cancelled = true;
        $this->cancelErr = $reason ?? new CancellationException();
    }

    /**
     * Returns true when this Context — or any ancestor it was derived
     * from — has been cancelled, or when a deadline in the chain has
     * expired.
     *
     * Mirrors Go's `ctx.Done()` channel semantics: cancelling a parent
     * marks every derived context done, while cancelling a derived
     * context leaves its ancestors live. The walk stops at the first
     * dead node, so `done()` on a long value chain stays O(depth).
     */
    public function done(): bool
    {
        return $this->deadNode() !== null;
    }

    /**
     * Returns the cancellation or deadline error, or null if not done.
     *
     * The error belongs to the first dead node found walking from this
     * context upward — so a context cancelled via an ancestor reports
     * the ancestor's reason (exactly the error handed to `cancel()`).
     */
    public function err(): ?\Throwable
    {
        $dead = $this->deadNode();
        if ($dead === null) {
            return null;
        }
        if ($dead->cancelErr !== null) {
            return $dead->cancelErr;
        }
        if ($dead->cancelled) {
            return new CancellationException();
        }
        return new DeadlineExceededException();
    }

    /**
     * First node in this context's parent chain that is cancelled or
     * past its deadline, or null when the whole chain is live.
     */
    private function deadNode(): ?self
    {
        $now = new \DateTimeImmutable();
        for ($node = $this; $node !== null; $node = $node->parent) {
            if ($node->cancelled) {
                return $node;
            }
            if ($node->deadline !== null && $node->deadline < $now) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Look up a value by key, walking the parent chain.
     *
     * Returns null if the key is not found anywhere in the chain.
     *
     * @return mixed
     */
    public function value(string $k): mixed
    {
        if (\array_key_exists($k, $this->ownValues)) {
            return $this->ownValues[$k];
        }
        return $this->parent?->value($k);
    }
}
