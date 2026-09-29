<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PumpOptions;

/**
 * Internal session record. Carries everything {@see MultiPump} needs
 * to demux a single master → stdoutSink pipe; not part of the public
 * API.
 */
final class MultiPumpSession
{
    /** @var bool Whether this session has reached its done state. */
    private bool $done = false;

    /**
     * First wall-clock timestamp at which `tick()` observed the child
     * as exited. Used to bound the post-child master drain to
     * `opts->flushDeadlineSec`. Null until that moment.
     */
    private ?float $childExitedAt = null;

    /**
     * @param resource $stdoutSink
     */
    public function __construct(
        public readonly int $id,
        public readonly MasterPty $master,
        public readonly mixed $stdoutSink,
        public readonly ?Child $child,
        public readonly PumpOptions $opts,
    ) {}

    public function isDone(): bool
    {
        return $this->done;
    }

    public function markDone(): void
    {
        $this->done = true;
    }

    public function childExitedAt(): ?float
    {
        return $this->childExitedAt;
    }

    public function markChildExitedAt(float $now): void
    {
        // Only record the FIRST moment; subsequent calls are no-ops.
        if ($this->childExitedAt === null) {
            $this->childExitedAt = $now;
        }
    }
}
