<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Immutable collection of {@see Subscription} value objects with a
 * fluent builder surface.
 *
 * Models return an instance from their {@see Model::subscriptions()} method.
 * The runtime diffs the old set against the new one on each update cycle,
 * starting new subscriptions, cancelling dropped ones, and leaving stable
 * ones untouched.
 *
 * @internal
 */
final class Subscriptions
{
    /**
     * @param list<Subscription> $subscriptions
     */
    public function __construct(
        private readonly array $subscriptions = [],
    ) {}

    /**
     * Add a tick subscription — fires `produce` every `$seconds`.
     *
     * @param non-empty-string $id
     * @param \Closure(): Msg $produce
     */
    public function withTick(string $id, float $seconds, \Closure $produce): self
    {
        return new self(array_merge($this->subscriptions, [
            new Subscription($id, Kind::Tick, ['seconds' => $seconds], $produce),
        ]));
    }

    /**
     * Add a key subscription — fires `produce` on keyboard events.
     *
     * @param non-empty-string $id
     * @param \Closure(): Msg $produce
     */
    public function withKey(string $id, \Closure $produce): self
    {
        return new self(array_merge($this->subscriptions, [
            new Subscription($id, Kind::Key, [], $produce),
        ]));
    }

    /**
     * Add a signal subscription — fires `produce` when the given signal fires.
     *
     * @param non-empty-string $id
     * @param int $signo Signal number (e.g., SIGWINCH)
     * @param \Closure(): Msg $produce
     */
    public function withSignal(string $id, int $signo, \Closure $produce): self
    {
        return new self(array_merge($this->subscriptions, [
            new Subscription($id, Kind::Signal, ['signo' => $signo], $produce),
        ]));
    }

    /**
     * Add a custom subscription.
     *
     * @param non-empty-string $id
     * @param array<string,mixed> $params
     * @param \Closure(): Msg $produce
     */
    public function withCustom(string $id, array $params, \Closure $produce): self
    {
        return new self(array_merge($this->subscriptions, [
            new Subscription($id, Kind::Custom, $params, $produce),
        ]));
    }

    /**
     * @return list<Subscription>
     */
    public function all(): array
    {
        return $this->subscriptions;
    }

    /**
     * Check whether any subscription with the given id exists.
     */
    public function has(string $id): bool
    {
        foreach ($this->subscriptions as $s) {
            if ($s->id === $id) {
                return true;
            }
        }
        return false;
    }
}
