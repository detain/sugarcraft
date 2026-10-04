<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * How a message sent to a running sub-agent is delivered (roadmap P-D1,
 * Appendix P §5.3) — the sub-agent half of what
 * {@see \SugarCraft\Crush\Backend\QueueMode} is for the main turn.
 *
 * Only {@see Steer}, {@see Interrupt} and {@see Note} are read into the run's
 * conversation by {@see AgentInbox::drain()}; a {@see Followup} waits in the
 * mailbox for the run that continues the conversation once this one ends (a
 * cold resume), and a {@see Control} message is a verb for the harness, never
 * text for the model ({@see AgentInbox::takeControls()}).
 */
enum MessageMode: string
{
    /** Delivered at the run's next step boundary; the current step's tool calls all run first. */
    case Steer = 'steer';

    /**
     * Delivered at the next step boundary too, but the step's sequential tool
     * calls that have not started yet are skipped (they answer
     * {@see \SugarCraft\Crush\Backend\TurnInbox::SKIPPED}), so the agent reads
     * the message before doing more work on a plan it may change.
     */
    case Interrupt = 'interrupt';

    /** Kept for the conversation's next run instead of this one. */
    case Followup = 'followup';

    /** Information for the agent, delivered like {@see Steer}, that asks for no change of course. */
    case Note = 'note';

    /** A harness verb (cancel, pause, resume), read by {@see AgentInbox::takeControls()}. */
    case Control = 'control';

    /** Whether {@see AgentInbox::drain()} hands this message to the running agent. */
    public function deliveredMidRun(): bool
    {
        return match ($this) {
            self::Steer, self::Interrupt, self::Note => true,
            self::Followup, self::Control => false,
        };
    }

    /** Whether a waiting message of this mode skips the current step's unstarted calls. */
    public function skipsUnstartedCalls(): bool
    {
        return $this === self::Interrupt;
    }
}
