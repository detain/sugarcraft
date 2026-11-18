<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Test seam for the window in which a SIGTERM must still stop a serve: after the
 * serve has installed its signal handlers and before it has armed its timers.
 *
 * ServeStartup starts the protocol and the gateway, which arm timers, only after
 * it has installed the handlers. A stop that lands in that window must survive
 * into Loop::run(); the old direct-call handler lost it, and the serve then ran
 * on forever with those timers armed. No timing-based test can reach that window
 * reliably, so this seam delivers the SIGTERM itself: on the first timer armed
 * after addSignal(), before the timer is armed, and says so on STDERR. Every call
 * delegates to the real loop.
 */
final class SigtermBeforeRunLoop implements LoopInterface
{
    /** Printed on STDERR when the signal is delivered, so a seam that never fires is visible. */
    public const MARKER = 'test-seam: SIGTERM delivered before the first timer was armed';

    private bool $handlersInstalled = false;
    private bool $signalled = false;

    public function __construct(private readonly LoopInterface $inner)
    {
    }

    public function addReadStream($stream, $listener)
    {
        $this->inner->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener)
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream)
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream)
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback)
    {
        $this->signalOnce();

        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $this->signalOnce();

        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener)
    {
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener)
    {
        $this->inner->addSignal($signal, $listener);
        $this->handlersInstalled = true;
    }

    public function removeSignal($signal, $listener)
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run()
    {
        $this->inner->run();
    }

    public function stop()
    {
        $this->inner->stop();
    }

    private function signalOnce(): void
    {
        if (!$this->handlersInstalled || $this->signalled) {
            return;
        }
        // Set before the kill: the handler may arm timers of its own (the drain
        // does), and those re-enter this method.
        $this->signalled = true;
        \fwrite(\STDERR, self::MARKER . "\n");
        \posix_kill(\getmypid(), \SIGTERM);
        \pcntl_signal_dispatch();
    }
}
