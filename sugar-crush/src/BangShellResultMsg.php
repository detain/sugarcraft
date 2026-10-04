<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * A `!cmd` (roadmap 5.14g) finished: its result row, and the turn slot it
 * occupied.
 *
 * WHY ITS OWN MSG. It used to ride {@see CancelledWorkflowReportMsg}, whose
 * arm only appends a row, because a `!cmd` did not occupy the turn. It does
 * now — the command holds `inFlight` under its own {@see Backend\CancellationToken},
 * so Enter queues behind it and Esc Esc kills its process tree instead of the
 * command running out its 600 s budget — and landing it therefore has to
 * release that slot and the prompts queued behind it, which an append-only
 * arm must never do.
 *
 * $generation is the turn generation the command was started under; a result
 * whose generation is no longer current belongs to a command Esc Esc already
 * cancelled, and is dropped. Null is a result nobody stamped (an embedder
 * calling {@see Commands\BangShell::cmd()} without a turn), appended and
 * nothing else. $cancelled says the command was killed before it finished.
 */
final class BangShellResultMsg implements Msg
{
    public function __construct(
        public readonly Message $message,
        public readonly ?int $generation = null,
        public readonly bool $cancelled = false,
    ) {
    }
}
