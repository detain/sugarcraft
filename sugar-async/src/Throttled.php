<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Invokable, disposable handle behind {@see AsyncOps::throttle()}.
 *
 * The first call of a burst fires immediately and starts the cooldown window;
 * calls landing inside the window are suppressed — each answers NULL (the
 * drop is a quiet hand-off, not an error) — until the window ends. With
 * `trailing: true` the LAST suppressed call is instead kept and fired the
 * moment the window closes (earlier suppressed calls answer null).
 *
 * Implements {@see Subscription}: unsubscribe() cancels the cooldown and
 * drops any queued trailing call as null.
 */
final class Throttled implements Subscription
{
    private bool $cooling = false;

    private ?TimerInterface $cooldownTimer = null;

    private ?Deferred $trailingDeferred = null;

    /** @var list<mixed>|null */
    private ?array $trailingArguments = null;

    private bool $active = true;

    private function __construct(
        private readonly LoopInterface $loop,
        private readonly float $seconds,
        private readonly \Closure $fn,
        private readonly bool $trailing,
    ) {
    }

    /**
     * @param callable(mixed...): mixed $fn  The function to throttle
     * @param float $seconds  Minimum interval between fires, already validated by the caller
     * @param bool $trailing  Fire the last suppressed call at window end
     */
    public static function new(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
        bool $trailing = false,
    ): self {
        return new self(
            $loop ?? \React\EventLoop\Loop::get(),
            $seconds,
            \Closure::fromCallable($fn),
            $trailing,
        );
    }

    /**
     * Lead or queue a call.
     *
     * @return PromiseInterface<mixed>  The fire's value; null when suppressed
     *         (leading-edge mode) or superseded inside the trailing queue
     */
    public function __invoke(mixed ...$arguments): PromiseInterface
    {
        if ($this->active === false) {
            return resolve(null);
        }

        if ($this->cooling === false) {
            $this->startWindow();
            $deferred = new Deferred();
            $this->run($arguments, $deferred);

            return $deferred->promise();
        }

        if ($this->trailing === false) {
            return resolve(null); // suppressed inside the window, no re-arm
        }

        if ($this->trailingDeferred !== null) {
            $superseded = $this->trailingDeferred;
            $this->trailingDeferred = null;
            $this->trailingArguments = null;
            $superseded->resolve(null);
        }

        $deferred = new Deferred();
        $this->trailingDeferred = $deferred;
        $this->trailingArguments = $arguments;

        return $deferred->promise();
    }

    public function unsubscribe(): void
    {
        if ($this->active === false) {
            return; // idempotent
        }

        $this->active = false;

        if ($this->cooldownTimer !== null) {
            $this->loop->cancelTimer($this->cooldownTimer);
            $this->cooldownTimer = null;
        }
        $this->cooling = false;

        if ($this->trailingDeferred !== null) {
            $deferred = $this->trailingDeferred;
            $this->trailingDeferred = null;
            $this->trailingArguments = null;
            $deferred->resolve(null);
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** True while a trailing call waits for the window to close. */
    public function isPending(): bool
    {
        return $this->trailingDeferred !== null;
    }

    private function startWindow(): void
    {
        $this->cooling = true;
        $this->cooldownTimer = $this->loop->addTimer($this->seconds, function (): void {
            $this->cooldownTimer = null;
            $this->cooling = false;
            $this->runTrailing();
        });
    }

    private function runTrailing(): void
    {
        if ($this->trailingDeferred === null) {
            return;
        }

        $deferred = $this->trailingDeferred;
        $arguments = $this->trailingArguments ?? [];
        $this->trailingDeferred = null;
        $this->trailingArguments = null;

        $this->run($arguments, $deferred);
    }

    /** @param list<mixed> $arguments */
    private function run(array $arguments, Deferred $deferred): void
    {
        try {
            $deferred->resolve(($this->fn)(...$arguments));
        } catch (\Throwable $error) {
            $deferred->reject($error);
        }
    }
}
