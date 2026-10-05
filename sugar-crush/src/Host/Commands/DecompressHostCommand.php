<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Lang;
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
            $id === null => Lang::t('host.decompress.usage'),
            $block === null => Lang::t('host.decompress.unknown', ['id' => $id]),
            !$block->isRange() => Lang::t('host.decompress.step_summary', ['id' => $id]),
            $block->deactivatedByUser => Lang::t('host.decompress.already', ['id' => $id]),
            !$block->active => self::inside($before, $block),
            default => null,
        };
        if ($reply !== null) {
            return CommandResult::reply($text, $reply);
        }

        $ledger = $before->withBlockDecompressed((int) $id);

        return LedgerCommand::respond($context, $text, $before, $ledger, Lang::t('host.decompress.done', [
            'id' => $id,
            'topic' => $block->topic,
            'from' => RefTag::label((int) $block->fromRef),
            'to' => RefTag::label((int) $block->toRef),
            'tokens' => TokenCount::compact($block->compressedTokens),
        ]));
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
            ? Lang::t('host.decompress.inactive', ['id' => $block->id])
            : Lang::t('host.decompress.inside', ['id' => $block->id, 'outer' => $outer->id]);
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
                $block->deactivatedByUser => Lang::t('host.decompress.state.decompressed'),
                $block->active => Lang::t('host.decompress.state.active'),
                default => Lang::t('host.decompress.state.inside', ['outer' => $ledger->consumerOf($block->id)?->id ?? '?']),
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
            ? Lang::t('host.decompress.none')
            : Lang::t('host.decompress.heading') . "\n" . implode("\n", $lines) . "\n" . Lang::t('host.decompress.hint');
    }
}
