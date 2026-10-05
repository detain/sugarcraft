<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Context\Pruning\RefTag;

/**
 * `/recompress [bN]` (roadmap 3.B-4, DCP `/dcp recompress`): restore a
 * section `/decompress` took back — its summary is sent in place of its rows
 * again from the next turn, and it covers the sections it had covered. With
 * no argument it lists the session's compressed sections.
 */
final class RecompressHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $before = $context->contextLedger();
        $argument = CommandText::argument($text);
        if ($argument === '') {
            return CommandResult::reply($text, DecompressHostCommand::listing($before));
        }

        $id = DecompressHostCommand::blockId($argument);
        $block = $id === null ? null : $before->block($id);
        $reply = match (true) {
            $id === null => 'Usage: /recompress bN — name a decompressed section as listed by /recompress.',
            $block === null || !$block->isRange() => "No compressed section b{$id} in this session.",
            !$block->deactivatedByUser => $block->active
                ? "b{$id} is already compressed."
                : DecompressHostCommand::inside($before, $block),
            default => null,
        };
        if ($reply !== null) {
            return CommandResult::reply($text, $reply);
        }

        $ledger = $before->withBlockRecompressed((int) $id);

        return LedgerCommand::respond($context, $text, $before, $ledger, sprintf(
            'Recompressed b%d (%s): %s…%s are sent as its summary again from the next turn.',
            $id,
            $block->topic,
            RefTag::label((int) $block->fromRef),
            RefTag::label((int) $block->toRef),
        ));
    }
}
