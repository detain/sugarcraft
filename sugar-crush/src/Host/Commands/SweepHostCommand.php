<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\SweepCommand;

/**
 * `/sweep [n]` (roadmap 3.B-2): prune by hand the tool outputs since the last
 * prompt, or the last n — see {@see SweepCommand}. The session's ledger is
 * the one its {@see \SugarCraft\Crush\Host\TurnRunner} keeps, so the next turn
 * sends the placeholders. Never mid-turn: both drivers refuse a command while
 * a turn runs, so the turn's own ledger cannot overwrite the sweep.
 */
final class SweepHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $before = $context->contextLedger();
        [$ledger, $reply] = SweepCommand::run($context->history, $before, CommandText::argument($text));

        return LedgerCommand::respond($context, $text, $before, $ledger, $reply);
    }
}
