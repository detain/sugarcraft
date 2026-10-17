<?php

declare(strict_types=1);

namespace SugarCraft\Code;

use SugarCraft\Core\Msg;

/**
 * The forked `statusLine` command finished (ISS-095): its one sanitised line.
 *
 * WHY ITS OWN MSG. {@see StatusLineTickMsg} is the clock; running the command
 * used to happen inside its update arm, synchronously on the TUI thread, so a
 * command spending its budget froze the frame once per tick. The arm now
 * launches a {@see Config\StatusLineCommand::cmd()} off the update path, and a
 * result that arrives two seconds later has to re-enter the model through a
 * message of its own — the same split {@see BangShellResultMsg} makes.
 *
 * The fold calls {@see Config\StatusLineCommand::settle()}, which lands the
 * text, re-arms the TTL from the moment the run ENDED, and clears the
 * in-flight latch that kept overlapping ticks from double-running the
 * command. Like the tick, this is internal to the TUI: it never crosses the
 * wire and carries no turn generation — a stale status line is harmless, and
 * a second run's result simply lands after the first's.
 */
final class StatusLineResultMsg implements Msg
{
    public function __construct(
        public readonly string $line,
    ) {
    }
}
