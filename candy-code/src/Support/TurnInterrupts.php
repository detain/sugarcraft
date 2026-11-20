<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The turn child's view of the parent's interrupts for a tool that waits
 * inside its call: a soft cancel, or a steer the step boundary has not taken.
 *
 * WHY A PROCESS-SCOPED SEAM. The frames arrive on the turn's channel, which
 * only the turn loop holds, but the waits that must notice them sit several
 * frames below it (SubagentsTool::wait, ShellSend's screen hold). A tool's own
 * sleep holds that same loop, so the socket is not read for as long as the
 * sleep runs. Threading a probe through every tool signature for this would
 * widen a contract every tool shares, so the turn child names its probe once
 * ({@see listen()}) and a waiting tool asks ({@see pending()}), the way
 * {@see ToolCancelRequests} answers cancel_tool.
 *
 * OWNED BY ONE PROCESS. A forked member inherits this state but must not read
 * the turn socket, so every answer outside the listening process is false.
 *
 * Not latched, unlike ToolCancelRequests: each ask reports what is waiting
 * now, because the step boundary takes the frames away once it reads them.
 */
final class TurnInterrupts
{
    /** @var (\Closure(): bool)|null */
    private static ?\Closure $source = null;

    private static int $ownerPid = 0;

    /**
     * Name where this process's interrupts come from, the turn child's
     * channel, and forget any earlier turn's.
     *
     * @param \Closure(): bool $source true while a soft cancel or a steer is waiting
     */
    public static function listen(\Closure $source): void
    {
        self::$source = $source;
        self::$ownerPid = (int) getmypid();
    }

    /** Stop listening (the turn is over, or a test is done). */
    public static function forget(): void
    {
        self::$source = null;
        self::$ownerPid = 0;
    }

    /** Whether the parent has sent this turn something a waiting tool should stop for. */
    public static function pending(): bool
    {
        if (self::$source === null || (int) getmypid() !== self::$ownerPid) {
            return false;
        }

        return (self::$source)();
    }
}
