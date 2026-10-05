<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * `/redo` (item 3.A-2) — step forward one checkpoint over what `/rewind` or
 * `/undo` set aside, back to where the first of them started. The
 * conversation always moves. Moved out of `Chat` in roadmap O-2h.
 *
 * THE FILES MOVE ONLY WHEN THEY ARE WHERE THE CONVERSATION IS: they must match
 * the snapshot of the checkpoint being left, which is true after `/undo` or
 * `/rewind --both` and false after a conversation-only rewind (the files never
 * went back) or once they have been edited since. Moving them in any other
 * case would overwrite work the redo knows nothing about, so they are left
 * alone and the reply says why.
 */
final class RedoCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $text = '/redo';
        $refusal = Checkpoints::refusal($context, $text);
        if ($refusal !== null) {
            return $refusal;
        }
        $store = Checkpoints::store($context);
        $sessionId = (string) $context->sessionId;

        try {
            $stack = $store->redoStack($sessionId);
            if (\count($stack) < 2) {
                return CommandResult::reply($text, Lang::t('host.redo.nothing'));
            }
            [$from, $to] = [$stack[0], $stack[1]];
            $checkpointer = $store->workspaceCheckpointer($context->projectRoot());

            $moveFiles = false;
            $filesNote = '';
            if (WorkspaceCheckpointer::isCaptured($from['workspace'])) {
                $drift = $checkpointer->changes($from['workspace']);
                if (\is_string($drift)) {
                    $filesNote = Lang::t('host.checkpoint.files_left', ['reason' => $drift]);
                } elseif ($drift !== []) {
                    $filesNote = Lang::t('host.redo.files_drifted');
                } elseif (!WorkspaceCheckpointer::isCaptured($to['workspace'])) {
                    $filesNote = Lang::t('host.checkpoint.files_left', ['reason' => Checkpoints::noSnapshotReason($to['workspace'])]);
                } else {
                    $ahead = $checkpointer->changes($to['workspace']);
                    if (\is_string($ahead)) {
                        $filesNote = Lang::t('host.checkpoint.files_left', ['reason' => $ahead]);
                    } else {
                        $moveFiles = $ahead !== [];
                    }
                }
            }

            $step = $store->redoCheckpoint($sessionId);
            if ($step === null) {
                return CommandResult::reply($text, Lang::t('host.redo.nothing'));
            }
            if ($moveFiles && \is_array($to['workspace'])) {
                $filesNote = ' ' . Checkpoints::fileRestoreReport($checkpointer->restore($to['workspace']));
            }

            [$messages, $draft, $cursor] = Checkpoints::checkpointChatState($step['state']);
            $restored = max(0, Checkpoints::agentVisibleCount($messages) - Checkpoints::agentVisibleCount($context->history));
            $response = $step['tip']
                ? Lang::t('host.redo.to_tip', ['count' => $restored]) . $filesNote
                : Lang::t('host.redo.to_checkpoint', ['count' => $restored, 'index' => $step['index']]) . $filesNote
                    . Lang::t('host.redo.again');

            return Checkpoints::restored($messages, $draft, $cursor, $text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.redo.error', ['error' => $e->getMessage()]));
        }
    }
}
