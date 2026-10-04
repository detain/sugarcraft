<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * `/undo` — take back the last turn (moved out of `Chat` in roadmap O-2h).
 *
 * WHEN THIS SESSION HAS AUTO-COMMITTED (step 3.G), it reverts the last commit,
 * Aider's way: `git checkout HEAD~1 -- <files>` then `git reset --soft HEAD~1`,
 * and the model is told the change was undone so it does not simply make it
 * again. Aider's refusals apply — the commit is not this session's, it is a
 * merge (or the root), a file it changed has uncommitted changes now, a file it
 * changed did not exist before it, or it is already on a remote — and each is
 * answered with why, changing nothing. The conversation stays where it is.
 *
 * OTHERWISE (item 3.A-2): the conversation returns to the checkpoint taken
 * before the last prompt, the prompt goes back into the box, and the files go
 * back to how that turn found them (opencode's `/undo`) — the same as
 * `/rewind 1 --both`, and undone in turn by `/redo`.
 */
final class UndoCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $text = '/undo';
        $committer = $context->sessionId === null || $context->root === null
            ? null
            : AutoCommitter::new($context->root)->withSessionId($context->sessionId);
        if ($committer !== null && $committer->hasCommits()) {
            return self::undoAutoCommit($committer);
        }

        return Checkpoints::refusal($context, $text)
            ?? Checkpoints::rewindConversation($context, $text, 1, true);
    }

    /**
     * The auto-commit half: revert the last commit or say which refusal
     * stopped it. On success one row the MODEL sees (Aider's `send_undo_reply`
     * wording) rides after the command's reply, so the next turn knows its
     * change is gone.
     */
    public static function undoAutoCommit(AutoCommitter $committer): CommandResult
    {
        $text = '/undo';
        try {
            $outcome = $committer->undo();
        } catch (\Throwable $e) {
            return CommandResult::reply($text, "Error during undo: {$e->getMessage()}");
        }

        $short = $outcome['sha'] === null ? '' : substr($outcome['sha'], 0, 7);
        if (!$outcome['ok']) {
            $hint = $outcome['refusal'] === AutoCommitter::REFUSED_NOT_OURS
                ? ' `/rewind --both` still restores the conversation and files to the last checkpoint.'
                : '';

            return CommandResult::reply($text, 'Nothing was undone: ' . $outcome['reason'] . '.' . $hint);
        }

        $files = implode(', ', $outcome['files']);
        if ($outcome['kind'] === 'snapshot') {
            return CommandResult::reply($text, "Un-committed {$short} ({$outcome['subject']}): your changes to {$files} are back to uncommitted, as they were.");
        }

        return CommandResult::reply($text, "Reverted {$short} ({$outcome['subject']}): {$files} went back to the previous commit.")
            ->withRows(Message::system(
                "The user ran /undo: the commit {$short} \"{$outcome['subject']}\" was reverted with "
                . '`git checkout HEAD~1 -- <files>` and `git reset --soft HEAD~1`, so the change to '
                . "{$files} is gone. Wait for further instructions before attempting that change again; "
                . 'ask if it is unclear why it was reverted.',
            ));
    }
}
