<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Compactor;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Util\TokenCount;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Implements `/sweep [n]` (roadmap 3.B-2, DCP §4.8 `/dcp sweep`): prune by
 * hand the tool outputs the person is done with.
 *
 * Usage:
 *   /sweep     — every tool output since the last prompt you sent
 *   /sweep 5   — the last five tool outputs, whichever turns they are in
 *
 * Each output becomes, on every later request, the same one-line placeholder
 * the automatic prune writes — the tool and its main argument, so the model
 * can re-run the call if it needs it ({@see PrunedOutputPlaceholder}). The
 * transcript is untouched: the ledger records the prune
 * ({@see PruneReason::Swept}, by {@see PruneAuthor::User}) and the projector
 * applies it. Skipped, and said so: outputs of the protected tools
 * ({@see PruningPolicy::PROTECTED_TOOLS} — a `Task` report or a `Skill` body
 * cannot be fetched again by re-running a cheap call), outputs already
 * pruned, outputs too small for a placeholder to save anything, and outputs
 * from a transcript older than step replay (1.B-2), which reach the model as
 * prose a prune cannot name. Refused when the session's pruning mode is
 * `off`.
 *
 * Pure ({@see run()}): it reads the rows and the ledger and answers the new
 * ledger and the receipt, so a test pins both without a Chat.
 */
final class SweepCommand
{
    /** The usage line a bad argument gets, in the active locale. */
    public static function usage(): string
    {
        return Lang::t('cmd.sweep.usage');
    }

    /**
     * @param list<Message> $history the transcript as it stands
     * @param string        $argument what followed `/sweep`
     *
     * @return array{0: ContextLedger, 1: string} the ledger to keep, and the receipt
     */
    public static function run(array $history, ContextLedger $ledger, string $argument, ?PruningPolicy $policy = null): array
    {
        $policy ??= PruningPolicy::new();
        $argument = trim($argument);
        $count = null;
        if ($argument !== '') {
            if (preg_match('/^[1-9]\d{0,5}$/', $argument) !== 1) {
                return [$ledger, self::usage()];
            }
            $count = (int) $argument;
        }

        if (!$ledger->effectiveMode()->allowsManualPruning()) {
            return [$ledger, Lang::t('cmd.sweep.off')];
        }

        $outputs = self::outputs(array_values($history), $count === null);
        if ($count !== null) {
            $outputs = \array_slice($outputs, -$count);
        }
        if ($outputs === []) {
            return [$ledger, $count === null
                ? Lang::t('cmd.sweep.nothing-since-prompt')
                : Lang::t('cmd.sweep.nothing-at-all')];
        }

        $swept = [];
        $files = [];
        $tokens = 0;
        $skipped = ['protected' => [], 'already pruned' => 0, 'too small' => 0];
        foreach ($outputs as [$result, $arguments]) {
            $id = (string) $result->id;
            if ($policy->isProtected($result->name)) {
                $skipped['protected'][$result->name] = true;

                continue;
            }
            if ($ledger->isPruned($id)) {
                ++$skipped['already pruned'];

                continue;
            }
            $output = $result->isError() ? (string) $result->error : $result->result;
            $saves = TokenEstimate::ofText($output) - TokenEstimate::ofText(PrunedOutputPlaceholder::for($result->name, $arguments));
            if ($saves <= 0) {
                ++$skipped['too small'];

                continue;
            }
            $ledger = $ledger->withPrune(new PruneEntry($id, PruneKind::Output, PruneReason::Swept, PruneAuthor::User, $saves));
            $swept[$result->name] = ($swept[$result->name] ?? 0) + 1;
            $files[] = $arguments;
            $tokens += $saves;
        }

        return [$ledger, self::receipt($swept, $tokens, $skipped, (new Compactor())->describeTargets($files))];
    }

    /**
     * The tool outputs a prune can name, oldest first, each with the
     * arguments of the call that asked for it: one per call id, from the
     * rows step replay sends as tool results (they carry a step id).
     *
     * @param list<Message> $history
     * @return list<array{0: ToolResult, 1: array<array-key, mixed>}>
     */
    private static function outputs(array $history, bool $sinceLastPrompt): array
    {
        $start = 0;
        if ($sinceLastPrompt) {
            for ($i = \count($history) - 1; $i >= 0; $i--) {
                $row = $history[$i];
                if ($row->role === Role::User && !$row->uiOnly && $row->userVisible && $row->stepId === null) {
                    $start = $i + 1;
                    break;
                }
            }
        }

        $arguments = [];
        foreach ($history as $row) {
            foreach ($row->toolCalls as $call) {
                if ($call instanceof ToolCall && $call->id !== null && $call->id !== '') {
                    $arguments[$call->id] = $call->arguments;
                }
            }
        }

        $outputs = [];
        $seen = [];
        for ($i = $start, $n = \count($history); $i < $n; $i++) {
            $row = $history[$i];
            if ($row->uiOnly || $row->stepId === null || $row->pendingToolCallId !== null) {
                continue;
            }
            foreach ($row->toolResults as $result) {
                if (!$result instanceof ToolResult || $result->id === null || $result->id === '' || isset($seen[$result->id])) {
                    continue;
                }
                $seen[$result->id] = true;
                $outputs[] = [$result, $arguments[$result->id] ?? $result->arguments];
            }
        }

        return $outputs;
    }

    /**
     * @param array<string, int> $swept tool name => outputs swept
     * @param array{protected: array<string, true>, 'already pruned': int, 'too small': int} $skipped
     * @param string $files the files the swept calls named, grouped by
     *        {@see Compactor::describeTargets()} (roadmap 3.B-5); '' for none
     */
    private static function receipt(array $swept, int $tokens, array $skipped, string $files = ''): string
    {
        $parts = [];
        if ($skipped['protected'] !== []) {
            $parts[] = Lang::t('cmd.sweep.skipped.protected', [
                'tools' => implode(', ', array_map(Chat::reportField(...), array_keys($skipped['protected']))),
            ]);
        }
        if ($skipped['already pruned'] > 0) {
            $parts[] = Lang::t('cmd.sweep.skipped.pruned', ['count' => $skipped['already pruned']]);
        }
        if ($skipped['too small'] > 0) {
            $parts[] = Lang::t('cmd.sweep.skipped.small', ['count' => $skipped['too small']]);
        }
        $skippedText = $parts === [] ? '' : Lang::t('cmd.sweep.skipped', ['parts' => implode('; ', $parts)]);

        if ($swept === []) {
            return Lang::t('cmd.sweep.none') . $skippedText;
        }

        $total = array_sum($swept);
        $byTool = [];
        foreach ($swept as $name => $n) {
            $byTool[] = Chat::reportField($name) . ' ×' . $n;
        }

        $receipt = [
            'count' => $total,
            'tokens' => TokenCount::compact($tokens),
            'tools' => implode(', ', $byTool),
            'files' => $files === '' ? '' : Lang::t('cmd.sweep.files', ['files' => $files]),
        ];

        return ($total === 1 ? Lang::t('cmd.sweep.swept.one', $receipt) : Lang::t('cmd.sweep.swept.many', $receipt)) . $skippedText;
    }
}
