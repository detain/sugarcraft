<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Context\Pruning\ContextLedger;

/**
 * The shared exit of the two context-ledger commands, `/sweep` and
 * `/pruning` (roadmap 3.B-2): keep the ledger the command produced as the
 * session's when it differs from the one it started from, and answer with
 * the command's reply as a UI-only exchange. The transcript is untouched —
 * the next turn sends the placeholders.
 */
final class LedgerCommand
{
    private function __construct()
    {
    }

    public static function respond(
        CommandContext $context,
        string $text,
        ContextLedger $before,
        ContextLedger $ledger,
        string $reply,
    ): CommandResult {
        if ($ledger !== $before) {
            $context->saveContextLedger($ledger);
        }

        return CommandResult::reply($text, $reply);
    }
}
