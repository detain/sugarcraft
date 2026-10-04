<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * Messages that arrive for a turn WHILE it runs, delivered at its step
 * boundaries (roadmap 1.C-3; the drain seam Appendix P §5.3 shares).
 *
 * {@see EngineBackend::runTurn()} drains it at the top of every step and
 * appends what it hands back to the conversation before the step's provider
 * call, so the model reads a message the user typed mid-turn at the next
 * boundary instead of after the whole turn. {@see \SugarCraft\Crush\Runtime}
 * also asks {@see pending()} before each sequential tool call: once something
 * is waiting, the step's calls that have not started are answered with
 * {@see SKIPPED} rather than run, so the message is read before more work is
 * done on a plan it may change (OpenClaw's steering rule).
 *
 * Two implementations are planned on the one seam: {@see SocketSteerInbox}
 * (the main turn's `steer` frames, 1.C-3) and a mailbox inbox for sub-agents
 * (P-D1); {@see CompositeTurnInbox} lets a turn read both.
 */
interface TurnInbox
{
    /** The synthetic result an unstarted call gets once a message is waiting. */
    public const SKIPPED = 'Skipped to process an incoming message.';

    /**
     * Everything that arrived since the last drain, oldest first, each handed
     * out once, as the rows to append to the conversation.
     *
     * @param int $step the 1-based step the messages are delivered at (an
     *                  implementation may acknowledge delivery with it)
     *
     * @return list<TypedMessage>
     */
    public function drain(int $step): array;

    /**
     * Whether a message is waiting, WITHOUT handing it out — the probe before
     * each sequential tool call. A later {@see drain()} still delivers it.
     */
    public function pending(): bool;
}
