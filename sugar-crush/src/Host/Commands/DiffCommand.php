<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

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
    public const USAGE = 'Usage: /diff [n] - show what changed in the files since checkpoint n, n a positive whole number (default 1: the one taken before your last prompt).';

    public function run(CommandContext $context, string $text): CommandResult
    {
        $refusal = Checkpoints::refusal($context, $text);
        if ($refusal !== null) {
            return $refusal;
        }

        $argument = CommandText::argument($text);
        if ($argument !== '' && (!ctype_digit($argument) || (int) $argument < 1)) {
            return CommandResult::reply($text, self::USAGE);
        }
        $stepsBack = $argument === '' ? 1 : (int) $argument;

        $store = Checkpoints::store($context);
        try {
            $positions = Checkpoints::fileCheckpointPositions($store, (string) $context->sessionId, $stepsBack);
            if ($positions === []) {
                return CommandResult::reply($text, 'No checkpoints available to diff against.');
            }
            $target = $positions[min($stepsBack, \count($positions)) - 1];
            if (!WorkspaceCheckpointer::isCaptured($target['workspace'])) {
                return CommandResult::reply($text, "Checkpoint {$target['index']} has no file snapshot: " . Checkpoints::noSnapshotReason($target['workspace']) . '.');
            }

            $diff = CheckpointDiff::of($store->workspaceCheckpointer($context->projectRoot()), $target['workspace']);
            if (\is_string($diff)) {
                return CommandResult::reply($text, "No diff against checkpoint {$target['index']}: {$diff}.");
            }
            if ($diff->isEmpty()) {
                return CommandResult::reply($text, "The files match checkpoint {$target['index']}: nothing has changed since.");
            }

            $files = \count($diff->changes);
            $response = sprintf(
                "%d %s changed since checkpoint %d (`/rewind %d --files` puts %s back):\n\n%s\n\n%s",
                $files,
                $files === 1 ? 'file' : 'files',
                $target['index'],
                $stepsBack,
                $files === 1 ? 'it' : 'them',
                Checkpoints::fenced(implode("\n", $diff->summaryLines()), ''),
                Checkpoints::fenced($diff->patch, 'diff'),
            );
            if ($diff->omittedLines > 0) {
                $response .= "\n\n{$diff->omittedLines} more lines of the patch are not shown.";
            }

            return CommandResult::reply($text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, "Error during diff: {$e->getMessage()}");
        }
    }
}
