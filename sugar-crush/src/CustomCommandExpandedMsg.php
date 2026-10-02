<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying a file-based command's expanded prompt back from the
 * forked child that expanded it (audit 15b-20).
 *
 * WHY THE EXPANSION RUNS OFF update(): a template's `` !`…` `` forms are real
 * shell commands sharing a {@see Commands\CommandSpec::SHELL_BUDGET_SECONDS}
 * budget, and {@see Commands\CommandSpec::runShellSubstitution()} waits on them
 * with a blocking `stream_select()` loop. Run inside {@see Chat::update()}, a
 * slow `` !`npm test` `` froze the whole TUI — no repaint, no Escape, no
 * Ctrl+C — for up to the full budget. {@see Chat} now forks the expansion from
 * a Cmd and re-enters its submit path when this arrives, consuming $expanded in
 * place of expanding the template a second time (which would run every shell
 * form twice).
 *
 * $generation is the latch, exactly as on {@see TurnHooksResolvedMsg}: the
 * pending submission bumped {@see Chat::$generation} and a double-Escape cancel
 * bumps it again, so an expansion for a command the user abandoned is dropped.
 */
final class CustomCommandExpandedMsg implements Msg
{
    /**
     * @param int          $generation    the generation the pending submission armed
     * @param string       $text          the command line as typed (`/name args`)
     * @param ?string      $expanded      the expanded prompt, byte-exact; null when
     *                                    the child ended without reporting one
     * @param list<string> $gatedCommands every `` !`…` `` the child put to the
     *                                    session's permission gate, in order, so
     *                                    the parent can replay them against ITS
     *                                    gate — the child's copy of the Auto-mode
     *                                    circuit breaker dies with the child
     */
    public function __construct(
        public readonly int $generation,
        public readonly string $text,
        public readonly ?string $expanded,
        public readonly array $gatedCommands = [],
    ) {}
}
