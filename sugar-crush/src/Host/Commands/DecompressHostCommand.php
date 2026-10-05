<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Util\TokenCount;

/**
 * `/decompress [bN]` (roadmap 3.B-4, DCP `/dcp decompress`): take back a
 * section the model compressed — its rows are sent in full again from the
 * next turn, and the sections it had covered stand again in its place. With
 * no argument it lists the session's compressed sections. A section inside
 * another active one is refused: the outer one hides it either way. Never
 * mid-turn, like every ledger command.
 */
final class DecompressHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $before = $context->contextLedger();
        $argument = CommandText::argument($text);
        if ($argument === '') {
            return CommandResult::reply($text, self::listing($before));
        }

        $id = self::blockId($argument);
        $block = $id === null ? null : $before->block($id);
        $reply = match (true) {
            $id === null => 'Usage: /decompress bN — name a compressed section as listed by /decompress.',
            $block === null => "No compressed section b{$id} in this session.",
            !$block->isRange() => "b{$id} is a step summary the harness wrote, not a section the model compressed; only Compress sections can be taken back.",
            $block->deactivatedByUser => "b{$id} is already decompressed; /recompress b{$id} restores it.",
            !$block->active => self::inside($before, $block),
            default => null,
        };
        if ($reply !== null) {
            return CommandResult::reply($text, $reply);
        }

        $ledger = $before->withBlockDecompressed((int) $id);

        return LedgerCommand::respond($context, $text, $before, $ledger, sprintf(
            'Decompressed b%d (%s): %s…%s are sent in full again from the next turn (~%s tokens). /recompress b%d restores the summary.',
            $id,
            $block->topic,
            RefTag::label((int) $block->fromRef),
            RefTag::label((int) $block->toRef),
            TokenCount::compact($block->compressedTokens),
            $id,
        ));
    }

    /** The block id `bN` / `N` names, or null. */
    public static function blockId(string $written): ?int
    {
        return preg_match('/^[bB]?(\d+)$/', trim($written), $m) === 1 && (int) $m[1] >= 1 ? (int) $m[1] : null;
    }

    /** Why a consumed block cannot be taken back on its own. */
    public static function inside(ContextLedger $ledger, CompressionBlock $block): string
    {
        $outer = $ledger->consumerOf($block->id);

        return $outer === null
            ? "b{$block->id} is not active: the rows it named are no longer in the conversation."
            : "b{$block->id} is inside b{$outer->id}. Restore b{$outer->id} first: /decompress b{$outer->id}.";
    }

    /** Every section the model compressed, one line each. */
    public static function listing(ContextLedger $ledger): string
    {
        $lines = [];
        foreach ($ledger->blocks as $block) {
            if (!$block->isRange()) {
                continue;
            }
            $state = match (true) {
                $block->deactivatedByUser => 'decompressed',
                $block->active => 'active',
                default => 'inside b' . ($ledger->consumerOf($block->id)?->id ?? '?'),
            };
            $lines[] = sprintf(
                'b%d · %s · %s…%s · −%s +%s · %s',
                $block->id,
                $block->topic,
                RefTag::label((int) $block->fromRef),
                RefTag::label((int) $block->toRef),
                TokenCount::compact($block->compressedTokens),
                TokenCount::compact($block->summaryTokens),
                $state,
            );
        }

        return $lines === []
            ? 'No compressed sections in this session. /compress [focus] asks the model to make one.'
            : "Compressed sections:\n" . implode("\n", $lines) . "\n/decompress bN takes one back; /recompress bN restores it.";
    }
}
