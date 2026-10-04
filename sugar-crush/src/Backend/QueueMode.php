<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * What a prompt sent while a turn is running does (roadmap 1.C-3, decision
 * D6; OpenClaw `queueMode`, opencode `delivery`).
 *
 * The TUI binds Enter-while-busy to {@see Steer} and Tab to {@see Followup}.
 * The setting that would let a user make another mode the Enter default is
 * `queueMode` (settings step N-P4g); until it lands the mode is fixed at
 * {@see Steer}, which is why {@see onEnter()} is a method and not a config read.
 */
enum QueueMode: string
{
    /**
     * Delivered into the RUNNING turn at its next step boundary
     * ({@see TurnInbox}); calls of the current step that have not started are
     * skipped so the message is read first. A steer that arrives after the
     * turn's last boundary is sent as the next prompt instead, so it is never
     * lost.
     */
    case Steer = 'steer';

    /** Held until the running turn ends, then sent as the next prompt. */
    case Followup = 'followup';

    /**
     * Stop the running turn at its next step boundary (a soft cancel), then
     * send the message as a new turn. Declared for the `queueMode` setting;
     * no key binds it yet.
     */
    case Interrupt = 'interrupt';

    /** The mode Enter uses while a turn runs, until `queueMode` exists (D6). */
    public static function onEnter(): self
    {
        return self::Steer;
    }
}
