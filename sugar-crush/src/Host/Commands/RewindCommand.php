<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * `/rewind [n] [--chat|--files|--both]` — restore an earlier checkpoint: the
 * conversation, the project's files (item 3.A-2), or both (moved out of
 * `Chat::handleRewindCommand()` in roadmap O-2h).
 *
 * `[n]` counts checkpoints back, `1` when omitted. The scope word may come
 * before or after it, once. Anything else is answered with usage and rewinds
 * NOTHING: the old `(int)` cast clamped to 1, so `/rewind last`,
 * `/rewind help`, `/rewind -2` and `/rewind:all` each performed a one-step
 * rewind — a destructive command run on input that asked for something else
 * (audit 15b-22).
 *
 * A CONVERSATION REWIND CAN BE UNDONE: the rows it steps over go onto the redo
 * stack instead of being deleted (`EnhancedSessionStore::restoreCheckpoint()`),
 * and {@see RedoCommand} walks back up it until the next prompt is sent.
 * `--files` alone touches no row.
 */
final class RewindCommand implements HostCommand
{
    /** The scope words, Cline's and Claude Code's three choices. */
    public const SCOPES = ['--chat' => 'chat', '--files' => 'files', '--both' => 'both'];

    public const USAGE = 'Usage: /rewind [n] [--chat|--files|--both] - step back n checkpoints, n a positive whole number (default 1). --chat (the default) restores the conversation, --files the project\'s files, --both both.';

    public function run(CommandContext $context, string $text): CommandResult
    {
        $refusal = Checkpoints::refusal($context, $text);
        if ($refusal !== null) {
            return $refusal;
        }

        $stepsBack = null;
        $scope = null;
        foreach (CommandText::words($text) as $word) {
            if ($scope === null && isset(self::SCOPES[$word])) {
                $scope = self::SCOPES[$word];
                continue;
            }
            if ($stepsBack === null && ctype_digit($word) && (int) $word >= 1) {
                $stepsBack = (int) $word;
                continue;
            }

            return CommandResult::reply($text, self::USAGE);
        }

        return match ($scope ?? 'chat') {
            'files' => Checkpoints::rewindFiles($context, $text, $stepsBack ?? 1),
            'both' => Checkpoints::rewindConversation($context, $text, $stepsBack ?? 1, true),
            default => Checkpoints::rewindConversation($context, $text, $stepsBack ?? 1, false),
        };
    }
}
