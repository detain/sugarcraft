<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;

/**
 * Internal Msg dispatched once {@see \SugarCraft\Code\Chat::measureContextCmd()}'s
 * scheduled pass has landed — the categories are already in
 * {@see \SugarCraft\Code\Host\TurnRunner::heldContext()} by the time this
 * arrives, so it carries no payload beyond the session it was measured for.
 *
 * The wake-up exists because the context pane follows the TodoPane law of
 * "read, never fetched": it reads the runner's snapshot while painting and
 * measures nothing itself (P-CTX.d). What it cannot do from `view()` is
 * START the measurement — {@see \SugarCraft\Code\Host\ContextSources::measure()}
 * reaches the prompt assembly, whose first call can touch disk — so a turn's
 * settle, every compaction landing, `/clear` and a model switch schedule one
 * on the Cmd channel, and route this Msg as the repaint nudge that says a
 * fresher stamp is on the pane now.
 *
 * Dispatched whether or not the measurement saved: a failed pass leaves the
 * previous snapshot in place, and the nudge keeps the pane honest about it.
 */
final class ContextMeasuredMsg implements Msg
{
    public function __construct(public readonly ?string $sessionId)
    {
    }
}
