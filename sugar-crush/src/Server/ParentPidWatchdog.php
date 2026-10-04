<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Crush\Support\Daemonize;

/**
 * `serve --parent-pid <pid>` / `SUGARCRUSH_SERVER_PARENT_PID` (Appendix O
 * §4.6; roadmap O-4a): stop the server when the process that spawned it is
 * gone — an editor or a TUI that started a private server and then crashed
 * must not leave it running forever (kilo's `KILO_PARENT_PID`).
 *
 * The parent is watched by IDENTITY, not by number: its start time is captured
 * when the watchdog is armed, so a pid the kernel hands to an unrelated process
 * after the parent died still reads as "gone" ({@see Daemonize::isSameProcess()}).
 * A poll rather than a signal, because nothing tells a non-child that another
 * process exited, and a detached server is nobody's child.
 */
final class ParentPidWatchdog
{
    /** How often the parent is checked. Seconds. */
    public const INTERVAL_SECONDS = 1.0;

    private ?TimerInterface $timer = null;

    private function __construct(
        public readonly int $pid,
        private readonly ?int $startTime,
    ) {
    }

    /**
     * The value of `--parent-pid` (or the variable) as a pid, or null when
     * none was given.
     *
     * @throws ServerConfigException when it is not a positive integer
     */
    public static function parse(?string $value): ?int
    {
        if ($value === null || \trim($value) === '') {
            return null;
        }
        $value = \trim($value);
        if (\preg_match('/^[1-9]\d{0,9}$/', $value) !== 1 || (int) $value > 0x7fffffff) {
            throw new ServerConfigException(\sprintf('parent pid "%s" is not a process id', $value));
        }

        return (int) $value;
    }

    /**
     * A watchdog on $pid as it is NOW.
     *
     * @throws ServerConfigException when $pid is not running: there is no
     *         parent to outlive, and starting anyway would make the flag a no-op
     */
    public static function of(int $pid): self
    {
        $startTime = Daemonize::startTime($pid);
        if (!Daemonize::isSameProcess($pid, $startTime)) {
            throw new ServerConfigException(\sprintf('parent pid %d is not running', $pid));
        }

        return new self($pid, $startTime);
    }

    /** Whether the parent is still the process it was when this was armed. */
    public function parentAlive(): bool
    {
        return Daemonize::isSameProcess($this->pid, $this->startTime);
    }

    /**
     * Poll on $loop and call $onGone once, the first time the parent is found
     * gone; the timer is then cancelled.
     *
     * @param \Closure(int): void $onGone receives the parent pid
     */
    public function arm(LoopInterface $loop, \Closure $onGone, float $interval = self::INTERVAL_SECONDS): void
    {
        $this->disarm($loop);
        $this->timer = $loop->addPeriodicTimer($interval, function () use ($loop, $onGone): void {
            if ($this->parentAlive()) {
                return;
            }
            $this->disarm($loop);
            $onGone($this->pid);
        });
    }

    public function disarm(LoopInterface $loop): void
    {
        if ($this->timer !== null) {
            $loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }
}
