<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * The report of a workflow run the user CANCELLED with Esc Esc, delivered by
 * {@see Chat::driveWorkflowFiber()} once the run has wound down.
 *
 * NOT an {@see AssistantMsg}, and that is the point. Esc Esc already released
 * the turn the run occupied, so by the time this lands the user may have
 * started another one; an AssistantMsg would settle THAT turn — clear its
 * `inFlight`, drain its queue — on the strength of a report about a different
 * one. This Msg only appends the report to the transcript.
 */
final class CancelledWorkflowReportMsg implements Msg
{
    public function __construct(
        public readonly Message $message,
    ) {
    }
}
