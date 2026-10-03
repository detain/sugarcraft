<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Backend\PendingAsk;

/**
 * "A tool call inside the running turn is waiting on a human" — the parent
 * side of the turn child's `ask` frame (Appendix O §5.1, decision D1).
 *
 * It carries the {@see PendingAsk} itself rather than a copy of its fields,
 * because the event's whole point is that the receiver can ANSWER it: the
 * handle is the only route from a later `Msg` (the TUI modal's keypress, a
 * server client's `permission.respond`) back to the child that is blocked on
 * the question. Everything a renderer needs — tool, arguments after any hook
 * rewrite, the hook's question, which replies are on offer — is on the handle.
 *
 * Only {@see \SugarCraft\Crush\Backend\InteractiveTurn::completeInteractive()}
 * emits this. A caller of plain `completeAsync()` never sees one, because it
 * never promised to answer, and the child of such a turn settles every ASK
 * exactly as it always did (the attached approver, or the fail-closed
 * no-approver refusal).
 */
final readonly class PermissionAsked
{
    public function __construct(public PendingAsk $ask)
    {
    }
}
