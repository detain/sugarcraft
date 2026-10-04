<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\PruningCommand;

/**
 * `/pruning [auto|manual|off|default]` (roadmap 3.B-2): show or set this
 * session's pruning mode — see {@see PruningCommand}.
 */
final class PruningHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $before = $context->contextLedger();
        [$ledger, $reply] = PruningCommand::run($before, CommandText::argument($text));

        return LedgerCommand::respond($context, $text, $before, $ledger, $reply);
    }
}
