<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use React\EventLoop\LoopInterface;

/**
 * The one loop that carries this process's {@see ChildPollTrait::onExit()}
 * SIGCHLD listeners.
 *
 * SIGCHLD has one process-global disposition. A second loop that arms its own
 * listener replaces the first loop's handler, and puts SIG_DFL back when its
 * last listener goes, so the first loop never hears its child exit. Only the
 * loop recorded here arms the signal; every other loop takes the fallback
 * poll while it holds listeners.
 *
 * The loop is held weakly, so a loop dropped with listeners still armed does
 * not pin the claim.
 *
 * @internal
 */
final class SigchldOwner
{
    /** @var \WeakReference<LoopInterface>|null */
    private static ?\WeakReference $owner = null;

    private static int $listeners = 0;

    private function __construct()
    {
    }

    /** The loop holding armed listeners, or null when none does. */
    public static function current(): ?LoopInterface
    {
        $loop = self::$owner?->get();
        if ($loop === null || self::$listeners <= 0) {
            self::$owner = null;
            self::$listeners = 0;

            return null;
        }

        return $loop;
    }

    /**
     * Record one armed listener on $loop.
     *
     * @return bool false, recording nothing, when another loop holds the claim
     */
    public static function claim(LoopInterface $loop): bool
    {
        $current = self::current();
        if ($current !== null && $current !== $loop) {
            return false;
        }
        self::$owner = \WeakReference::create($loop);
        self::$listeners++;

        return true;
    }

    /** Drop one armed listener on $loop; the claim goes with the last one. */
    public static function release(LoopInterface $loop): void
    {
        if (self::current() !== $loop) {
            return;
        }
        self::$listeners--;
        if (self::$listeners <= 0) {
            self::$owner = null;
            self::$listeners = 0;
        }
    }
}
