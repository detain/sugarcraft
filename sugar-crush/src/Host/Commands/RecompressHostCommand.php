<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Lang;

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
            $id === null => Lang::t('host.recompress.usage'),
            $block === null || !$block->isRange() => Lang::t('host.decompress.unknown', ['id' => $id]),
            !$block->deactivatedByUser => $block->active
                ? Lang::t('host.recompress.already', ['id' => $id])
                : DecompressHostCommand::inside($before, $block),
            default => null,
        };
        if ($reply !== null) {
            return CommandResult::reply($text, $reply);
        }

        $ledger = $before->withBlockRecompressed((int) $id);

        return LedgerCommand::respond($context, $text, $before, $ledger, Lang::t('host.recompress.done', [
            'id' => $id,
            'topic' => $block->topic,
            'from' => RefTag::label((int) $block->fromRef),
            'to' => RefTag::label((int) $block->toRef),
        ]));
    }
}
