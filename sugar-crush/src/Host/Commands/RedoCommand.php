<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

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
    private const NOTHING = 'Nothing to redo: /redo steps forward over what /rewind or /undo set aside, until the next prompt is sent.';

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
                return CommandResult::reply($text, self::NOTHING);
            }
            [$from, $to] = [$stack[0], $stack[1]];
            $checkpointer = $store->workspaceCheckpointer($context->projectRoot());

            $moveFiles = false;
            $filesNote = '';
            if (WorkspaceCheckpointer::isCaptured($from['workspace'])) {
                $drift = $checkpointer->changes($from['workspace']);
                if (\is_string($drift)) {
                    $filesNote = ' The files were left as they are: ' . $drift . '.';
                } elseif ($drift !== []) {
                    $filesNote = ' The files were left as they are: they do not match the checkpoint the conversation was at, so moving them would overwrite changes.';
                } elseif (!WorkspaceCheckpointer::isCaptured($to['workspace'])) {
                    $filesNote = ' The files were left as they are: ' . Checkpoints::noSnapshotReason($to['workspace']) . '.';
                } else {
                    $ahead = $checkpointer->changes($to['workspace']);
                    if (\is_string($ahead)) {
                        $filesNote = ' The files were left as they are: ' . $ahead . '.';
                    } else {
                        $moveFiles = $ahead !== [];
                    }
                }
            }

            $step = $store->redoCheckpoint($sessionId);
            if ($step === null) {
                return CommandResult::reply($text, self::NOTHING);
            }
            if ($moveFiles && \is_array($to['workspace'])) {
                $filesNote = ' ' . Checkpoints::fileRestoreReport($checkpointer->restore($to['workspace']));
            }

            [$messages, $draft, $cursor] = Checkpoints::checkpointChatState($step['state']);
            $restored = max(0, Checkpoints::agentVisibleCount($messages) - Checkpoints::agentVisibleCount($context->history));
            $response = $step['tip']
                ? "Redid {$restored} messages: back where you were before the rewind." . $filesNote
                : "Redid {$restored} messages, to checkpoint {$step['index']}." . $filesNote . ' /redo again to go further.';

            return Checkpoints::restored($messages, $draft, $cursor, $text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, "Error during redo: {$e->getMessage()}");
        }
    }
}
