<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * Event-driven wake-up for a producer and a waiting consumer, in place of a
 * poll loop over a mailbox.
 *
 * {@see wait()} answers a promise that resolves when {@see notify()} fires. A
 * notify with no waiter is remembered as one pending signal, so the next wait
 * resolves at once: a message posted between two waits is never lost. Pending
 * signals do not stack; any number of notifies before a wait cost one wake-up,
 * which suits "something changed, go look" rather than counting events.
 *
 * Every waiter resolves on a notify, not just the first. A waiter bounded by a
 * timeout rejects with a TimeoutException when the budget runs out first, and
 * one cancelled through its promise is withdrawn from the set. Mirrors the
 * mailbox polls candy-code's agent mailboxes ran on a timer.
 */
final class Notifier
{
    /** @var array<int, array{deferred: Deferred, timer: ?TimerInterface}> */
    private array $waiters = [];
    private int $nextId = 0;
    private bool $signalled = false;

    private function __construct(
        private readonly LoopInterface $loop,
    ) {
    }

    /** @param LoopInterface|null $loop  Loop for the timeouts; null uses the shared Loop::get() */
    public static function new(?LoopInterface $loop = null): self
    {
        return new self($loop ?? Loop::get());
    }

    /** Wake every waiter, or remember one pending signal when none is waiting. */
    public function notify(): void
    {
        if ($this->waiters === []) {
            $this->signalled = true;
            return;
        }
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $waiter) {
            if ($waiter['timer'] !== null) {
                $this->loop->cancelTimer($waiter['timer']);
            }
            $waiter['deferred']->resolve(null);
        }
    }

    /** Number of waits currently parked on a notify. */
    public function waiting(): int
    {
        return count($this->waiters);
    }

    /**
     * @param float|null $seconds  Budget (positive and finite); null waits without a bound
     * @return PromiseInterface<null>  Resolves on notify; rejects with TimeoutException or OperationCancelledException
     */
    public function wait(?float $seconds = null): PromiseInterface
    {
        if ($seconds !== null && ($seconds <= 0 || !is_finite($seconds))) {
            throw new \InvalidArgumentException('Notifier wait seconds must be a positive finite number or null');
        }
        if ($this->signalled) {
            $this->signalled = false;
            return resolve(null);
        }

        $id = $this->nextId++;
        $deferred = new Deferred(function () use ($id): never {
            $this->withdraw($id);
            throw new OperationCancelledException('Wait cancelled');
        });
        $this->waiters[$id] = ['deferred' => $deferred, 'timer' => null];

        if ($seconds !== null) {
            $timer = $this->loop->addTimer($seconds, function () use ($id, $seconds): void {
                $waiter = $this->waiters[$id] ?? null;
                if ($waiter === null) {
                    return;
                }
                unset($this->waiters[$id]);
                $waiter['deferred']->reject(new TimeoutException(
                    'Wait timed out after ' . MessageFormat::seconds($seconds),
                    seconds: $seconds,
                ));
            });
            $this->waiters[$id]['timer'] = $timer;
        }

        return $deferred->promise();
    }

    private function withdraw(int $id): void
    {
        $waiter = $this->waiters[$id] ?? null;
        if ($waiter === null) {
            return;
        }
        unset($this->waiters[$id]);
        if ($waiter['timer'] !== null) {
            $this->loop->cancelTimer($waiter['timer']);
        }
    }
}
