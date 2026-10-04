<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningMode;

/**
 * Implements `/pruning [auto|manual|off]` (roadmap 3.B-2, DCP §4.13 manual
 * mode): show or set how much of this session's context pruning happens on
 * its own ({@see PruningMode}).
 *
 * Usage:
 *   /pruning          — the mode in force, and where it comes from
 *   /pruning auto     — prune superseded rows at each turn start; refs shown
 *   /pruning manual   — prune only on `/sweep`; refs shown
 *   /pruning off      — no strategies, no refs, `/sweep` refused
 *   /pruning default  — forget this session's choice and follow the setting
 *
 * The choice is the SESSION's: it lives on the session's ledger
 * ({@see ContextLedger::$mode}), so it is saved with the session, kept by a
 * branch and put back by `/rewind`. Without one the session follows
 * `contextPruning.mode` / `SUGARCRUSH_CONTEXT_PRUNING`
 * ({@see PruningMode::configured()}).
 *
 * Pure ({@see run()}): the new ledger and the reply, from the old ledger and
 * the argument.
 */
final class PruningCommand
{
    public const USAGE = 'Usage: /pruning [auto|manual|off|default]';

    /**
     * @param ContextLedger $ledger the session's ledger, its default mode
     *                              already the configured one
     *
     * @return array{0: ContextLedger, 1: string} the ledger to keep, and the reply
     */
    public static function run(ContextLedger $ledger, string $argument): array
    {
        $argument = strtolower(trim($argument));
        if ($argument === '') {
            return [$ledger, self::status($ledger)];
        }
        if ($argument === 'default') {
            $ledger = $ledger->withMode(null);

            return [$ledger, 'This session now follows the configured mode. ' . self::status($ledger)];
        }

        $mode = PruningMode::fromSetting($argument);
        if ($mode === null) {
            return [$ledger, self::USAGE];
        }
        $ledger = $ledger->withMode($mode);

        return [$ledger, self::status($ledger)];
    }

    /** One line: the mode in force, what it does, and where it comes from. */
    public static function status(ContextLedger $ledger): string
    {
        $mode = $ledger->effectiveMode();
        $source = $ledger->mode !== null
            ? 'set for this session with /pruning'
            : 'the configured mode (`' . PruningMode::SETTING . '` / `' . PruningMode::ENV . '`)';

        return sprintf('Context pruning: %s — %s. %s.', $mode->value, self::meaning($mode), ucfirst($source));
    }

    private static function meaning(PruningMode $mode): string
    {
        return match ($mode) {
            PruningMode::Auto => 'superseded rows are pruned at each turn start, and tool results carry their ref tags',
            PruningMode::Manual => 'nothing is pruned on its own; /sweep prunes by hand, and tool results carry their ref tags',
            PruningMode::Off => 'no strategies and no ref tags, and /sweep is refused; an over-full request is still relieved',
        };
    }
}
