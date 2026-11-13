<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

/**
 * candy-scriptui re-export of the sugar-pty process contract, widened
 * with the lifecycle surface the spin chain actually calls, so
 * {@see \SugarCraft\ScriptUi\Model\SpinModel}, {@see \SugarCraft\ScriptUi\Command\SpinCommand}
 * and every test double type against ONE pinned interface.
 *
 * The consolidation (scriptui-01, gate G1) added the drain/ladder verbs
 * here explicitly: a run-loop pump, a signal-only terminate, and a
 * bounded idempotent close are the contract every child of the spin
 * lifecycle must honour, whoever ends up owning the implementation.
 *
 * @see \SugarCraft\Pty\Contract\Process for the canonical contract.
 * @see \SugarCraft\ScriptUi\Tests\Process\ProcessContractTest for the pin.
 */
interface Process extends \SugarCraft\Pty\Contract\Process
{
    /**
     * Pump both capture pipes right now, bounded and non-blocking.
     * Cheap to call per tick: appends new bytes only.
     */
    public function drain(): void;

    /**
     * Register the capture pipes on `$loop` so bytes move while the run
     * loop is alive (scriptui-02: the cure for a child blocking at the
     * ~64KiB OS pipe buffer with nobody reading). Idempotent per loop.
     */
    public function attachPump(\React\EventLoop\LoopInterface $loop): void;

    /**
     * Unregister the read-stream pump. No-op when not attached; every
     * settle path calls it so watchers never outlive the loop.
     */
    public function detachPump(): void;

    /**
     * Signal the child (SIGTERM); does NOT reap. close() reaps.
     */
    public function terminate(): void;

    /**
     * Reap the child, bounded (TERM→grace→KILL ladder). Idempotent:
     * every call after the first returns the same code.
     */
    public function close(): int;

    /** Captured stdout bytes (drains first). */
    public function stdout(): string;

    /** Captured stderr bytes (drains first). */
    public function stderr(): string;
}
