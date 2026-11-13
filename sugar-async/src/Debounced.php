<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Invokable, disposable handle behind {@see AsyncOps::debounce()}.
 *
 * Only the last call inside the window fires, and only after the full window
 * has elapsed since it. Each call answers a promise: the winning call's
 * promise resolves with the scheduled work's return value, every superseded
 * call's promise resolves with NULL (you were replaced, nothing failed), and
 * a drop via {@see unsubscribe()} likewise resolves NULL — cancellation here
 * is a quiet hand-off, not an error (see Subscription's cancel-is-not-an-error
 * idiom).
 *
 * Implements {@see Subscription}: unsubscribe() releases the armed timer and
 * the pending burst, and a CancellationSource passed at construction disposes
 * the handle when cancelled.
 */
final class Debounced implements Subscription
{
    private ?Deferred $pending = null;

    private ?TimerInterface $timer = null;

    /** @var list<mixed> Arguments of the scheduled call */
    private array $pendingArguments = [];

    private bool $active = true;

    /** Optional removal handle for the dispose registration on a source. */
    private ?Subscription $sourceRegistration = null;

    private function __construct(
        private readonly LoopInterface $loop,
        private readonly float $seconds,
        private readonly \Closure $fn,
    ) {
    }

    /**
     * @param callable(mixed...): mixed $fn  The function to debounce
     * @param float $seconds  Debounce window, already validated by the caller
     * @param CancellationSource|null $source  Cancelling it unsubscribes this handle
     */
    public static function new(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
        ?CancellationSource $source = null,
    ): self {
        $handle = new self(
            $loop ?? \React\EventLoop\Loop::get(),
            $seconds,
            \Closure::fromCallable($fn),
        );

        if ($source !== null) {
            $handle->sourceRegistration = $source->onCancel(
                static function () use ($handle): void {
                    $handle->unsubscribe();
                },
            );
        }

        return $handle;
    }

    /**
     * Schedule a call: any burst already waiting is superseded (answers null)
     * and the window restarts.
     *
     * @return PromiseInterface<mixed>  The fire's value, or null when superseded or dropped
     */
    public function __invoke(mixed ...$arguments): PromiseInterface
    {
        if ($this->active === false) {
            return resolve(null);
        }

        $this->clearTimer();

        if ($this->pending !== null) {
            $superseded = $this->pending;
            $this->pending = null;
            $superseded->resolve(null);
        }

        $deferred = new Deferred();
        $this->pending = $deferred;
        $this->pendingArguments = $arguments;
        $this->timer = $this->loop->addTimer($this->seconds, function () use ($deferred): void {
            $this->timer = null;
            if ($this->pending === $deferred) {
                $this->pending = null;
                $arguments = $this->pendingArguments;
                $this->pendingArguments = [];
                $this->run($arguments, $deferred);
            }
        });

        return $deferred->promise();
    }

    /**
     * Run the scheduled call now instead of waiting out the window.
     * With nothing pending this answers null and starts nothing.
     * Paired by {@see Throttled::flush()} on the throttle handle.
     */
    public function flush(): PromiseInterface
    {
        if ($this->active === false || $this->pending === null) {
            return resolve(null);
        }

        $this->clearTimer();

        $deferred = $this->pending;
        $this->pending = null;
        $arguments = $this->pendingArguments;
        $this->pendingArguments = [];

        $this->run($arguments, $deferred);

        return $deferred->promise();
    }

    public function unsubscribe(): void
    {
        if ($this->active === false) {
            return; // idempotent
        }

        $this->active = false;
        $this->clearTimer();

        if ($this->pending !== null) {
            $deferred = $this->pending;
            $this->pending = null;
            $this->pendingArguments = [];
            $deferred->resolve(null);
        }

        $this->sourceRegistration?->unsubscribe();
        $this->sourceRegistration = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** True while a burst is waiting out the window. */
    public function isPending(): bool
    {
        return $this->pending !== null;
    }

    private function clearTimer(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
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
