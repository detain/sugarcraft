<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Workspace\CheckpointDiff;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * `/diff [n]` (item 3.A-2) — what changed in the files since checkpoint n:
 * the changed paths and the patch, checkpoint on the left. Counted like
 * `/rewind --files`, so `/diff` shows exactly what `/rewind --files` would
 * undo. Read-only, and the rows are UI-only: a patch on screen is not sent to
 * the model. Moved out of `Chat` in roadmap O-2h.
 */
final class DiffCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $refusal = Checkpoints::refusal($context, $text);
        if ($refusal !== null) {
            return $refusal;
        }

        $argument = CommandText::argument($text);
        if ($argument !== '' && (!ctype_digit($argument) || (int) $argument < 1)) {
            return CommandResult::reply($text, Lang::t('host.diff.usage'));
        }
        $stepsBack = $argument === '' ? 1 : (int) $argument;

        $store = Checkpoints::store($context);
        try {
            $positions = Checkpoints::fileCheckpointPositions($store, (string) $context->sessionId, $stepsBack);
            if ($positions === []) {
                return CommandResult::reply($text, Lang::t('host.diff.none'));
            }
            $target = $positions[min($stepsBack, \count($positions)) - 1];
            if (!WorkspaceCheckpointer::isCaptured($target['workspace'])) {
                return CommandResult::reply($text, Lang::t('host.diff.no_snapshot', [
                    'index' => $target['index'],
                    'reason' => Checkpoints::noSnapshotReason($target['workspace']),
                ]));
            }

            $diff = CheckpointDiff::of($store->workspaceCheckpointer($context->projectRoot()), $target['workspace']);
            if (\is_string($diff)) {
                return CommandResult::reply($text, Lang::t('host.diff.unavailable', ['index' => $target['index'], 'reason' => $diff]));
            }
            if ($diff->isEmpty()) {
                return CommandResult::reply($text, Lang::t('host.diff.unchanged', ['index' => $target['index']]));
            }

            $files = \count($diff->changes);
            $response = Lang::t($files === 1 ? 'host.diff.changed.one' : 'host.diff.changed.other', [
                'count' => $files,
                'index' => $target['index'],
                'steps' => $stepsBack,
            ])
                . "\n\n" . Checkpoints::fenced(implode("\n", $diff->summaryLines()), '')
                . "\n\n" . Checkpoints::fenced($diff->patch, 'diff');
            if ($diff->omittedLines > 0) {
                $response .= "\n\n" . Lang::t('host.diff.omitted', ['lines' => $diff->omittedLines]);
            }

            return CommandResult::reply($text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.diff.error', ['error' => $e->getMessage()]));
        }
    }
}
