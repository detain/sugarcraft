<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Agents\Board\Board;
use SugarCraft\Crush\Agents\Board\BoardMember;

/**
 * A {@see Tool} whose call is a whole delegated run that can talk to the other
 * runs of its parallel batch (roadmap 4.5) — today only
 * {@see BuiltIn\TaskTool}.
 *
 * {@see \SugarCraft\Crush\Runtime::executeConcurrently()} asks each member of a
 * batch whether its call would join a board ({@see boardMember()}); when two
 * or more would, it creates one {@see Board} for the batch and runs each of
 * them through the copy {@see withBoard()} returns, carrying that member's own
 * view. The member's run is then offered `BoardRead` and `BoardPost`, and the
 * board's news reaches it as a notice on its next tool result.
 *
 * A seam rather than a parameter of {@see Tool::execute()}, for the reason
 * {@see SharesSiblingSpend} gives: one tool needs it, and the rest of the
 * corpus must not change shape for it.
 */
interface SharesBoard
{
    /**
     * The participant this call becomes — its agent and what it was asked to
     * do — or null when the call would not run as a batch member at all (a
     * run handed to the background returns before its peers start).
     *
     * @param array<string, mixed> $args the call's arguments, as gated
     */
    public function boardMember(array $args): ?BoardMember;

    /** The same tool, its run seated on $board (a member's view of it). */
    public function withBoard(Board $board): Tool;
}
