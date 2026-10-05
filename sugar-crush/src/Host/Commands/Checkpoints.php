<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * What `/rewind`, `/undo`, `/redo` and `/diff` share (item 3.A-2; moved out of
 * `Chat` in roadmap O-2h): the refusal when a session cannot hold
 * checkpoints, the conversation and file rewinds, and the reading of a
 * checkpoint's stored state.
 *
 * The git work runs synchronously, as it did in `Chat::update()`. Making it
 * asynchronous (a Cmd and a result Msg) is the W4-d follow-up; this seam is
 * where it lands, since both drivers already apply a command's result.
 */
final class Checkpoints
{
    private function __construct()
    {
    }

    /**
     * Why a checkpoint command cannot run here, or null when it can: no store,
     * a store without checkpoints, or no session.
     */
    public static function refusal(CommandContext $context, string $text): ?CommandResult
    {
        if ($context->sessionStore === null) {
            return CommandResult::reply($text, Lang::t('host.checkpoint.no_store'));
        }

        if (!$context->sessionStore instanceof EnhancedSessionStore) {
            return CommandResult::reply($text, Lang::t('host.checkpoint.unsupported_store'));
        }

        if ($context->sessionId === null) {
            return CommandResult::reply($text, Lang::t('host.checkpoint.no_session'));
        }

        return null;
    }

    /**
     * Rewind the conversation $stepsBack checkpoints — and, with $withFiles,
     * the files to that checkpoint's snapshot. Call after {@see refusal()}.
     *
     * WITH FILES, NOTHING MOVES UNLESS BOTH CAN: a snapshot whose restore would
     * be refused (HEAD has moved since — restoring would silently undo those
     * commits, Cline's rule — or the repository is gone) refuses the whole
     * command before the conversation is touched. A checkpoint that simply has
     * no snapshot (taken in the home directory, a failed capture) still
     * rewinds the conversation and says why the files stayed.
     *
     * WITHOUT, THE FILE RESTORE IS OFFERED ONLY WHEN IT WOULD CHANGE SOMETHING
     * (Zed): the reply names how many files differ and the command that puts
     * them back, and says nothing about files that already match.
     */
    public static function rewindConversation(CommandContext $context, string $text, int $stepsBack, bool $withFiles): CommandResult
    {
        $store = self::store($context);
        $sessionId = (string) $context->sessionId;

        try {
            $checkpoints = $store->listCheckpoints($sessionId, $stepsBack);
            if ($checkpoints === []) {
                return CommandResult::reply($text, Lang::t('host.checkpoint.none'));
            }
            $target = $checkpoints[min($stepsBack, \count($checkpoints)) - 1];
            $targetIndex = (int) $target['index'];
            $workspace = self::checkpointWorkspace($target['state_data']);
            $checkpointer = $store->workspaceCheckpointer($context->projectRoot());
            $captured = WorkspaceCheckpointer::isCaptured($workspace);

            $restoreFiles = false;
            $filesNote = '';
            if ($withFiles) {
                if (!$captured) {
                    $filesNote = Lang::t('host.checkpoint.files_left', ['reason' => self::noSnapshotReason($workspace)]);
                } else {
                    $changes = $checkpointer->changes($workspace);
                    if (\is_string($changes)) {
                        return CommandResult::reply(
                            $text,
                            Lang::t('host.checkpoint.files_unrestorable', [
                                'index' => $targetIndex,
                                'reason' => $changes,
                                'steps' => $stepsBack,
                            ]),
                        );
                    }
                    $restoreFiles = $changes !== [];
                    if (!$restoreFiles) {
                        $filesNote = ' ' . Lang::t('host.checkpoint.files_match');
                    }
                }
            }

            $state = $store->restoreCheckpoint($sessionId, $targetIndex, self::redoTipState($context), $context->root);
            if ($state === null) {
                return CommandResult::reply($text, Lang::t('host.checkpoint.not_found', ['index' => $targetIndex]));
            }

            if ($restoreFiles && \is_array($workspace)) {
                $filesNote = ' ' . self::fileRestoreReport($checkpointer->restore($workspace));
            } elseif (!$withFiles && $captured && \is_array($workspace)) {
                $changes = $checkpointer->changes($workspace);
                if (\is_array($changes) && $changes !== []) {
                    $filesNote = Lang::t(
                        \count($changes) === 1 ? 'host.checkpoint.files_differ.one' : 'host.checkpoint.files_differ.other',
                        ['count' => \count($changes)],
                    );
                }
            }

            [$messages, $draft, $cursor] = self::checkpointChatState($state);
            // Counted AFTER the legacy trim, so "Rewound N" is the rows the
            // restore really took away: the prompt, its reply and everything
            // the turn added in between.
            $rewound = \count($context->history) - \count($messages);
            $response = Lang::t('host.checkpoint.rewound', ['count' => $rewound, 'index' => $targetIndex]) . $filesNote
                . Lang::t('host.checkpoint.redo_hint');

            return self::restored($messages, $draft, $cursor, $text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.checkpoint.error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * `/rewind [n] --files` — put the files back to checkpoint n's snapshot and
     * leave the conversation, and every checkpoint row, as it is.
     */
    public static function rewindFiles(CommandContext $context, string $text, int $stepsBack): CommandResult
    {
        $store = self::store($context);

        try {
            $positions = self::fileCheckpointPositions($store, (string) $context->sessionId, $stepsBack);
            if ($positions === []) {
                return CommandResult::reply($text, Lang::t('host.checkpoint.none'));
            }
            $target = $positions[min($stepsBack, \count($positions)) - 1];
            if (!WorkspaceCheckpointer::isCaptured($target['workspace'])) {
                return CommandResult::reply(
                    $text,
                    Lang::t('host.checkpoint.no_file_snapshot', [
                        'index' => $target['index'],
                        'reason' => self::noSnapshotReason($target['workspace']),
                    ]),
                );
            }

            $result = $store->workspaceCheckpointer($context->projectRoot())->restore($target['workspace']);
            $response = match ($result['status']) {
                'restored' => Lang::t('host.checkpoint.files_restored_to', [
                    'index' => $target['index'],
                    'written' => $result['written'],
                    'deleted' => $result['deleted'],
                ]),
                'unchanged' => Lang::t('host.checkpoint.files_already_match', ['index' => $target['index']]),
                default => Lang::t('host.checkpoint.nothing_restored', ['reason' => $result['reason']]),
            };

            return CommandResult::reply($text, $response);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.checkpoint.error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * The checkpoints `/rewind --files` and `/diff` count back through, newest
     * first, at most $limit of them: the checkpoint the conversation is
     * rewound to, when it is (the redo stack's lowest row), then the live
     * ones. So right after `/rewind`, `/rewind --files` is "the files of the
     * checkpoint you just rewound to" — what the rewind's reply offers.
     *
     * @return list<array{index: int, workspace: array<string, mixed>|null}>
     */
    public static function fileCheckpointPositions(EnhancedSessionStore $store, string $sessionId, int $limit): array
    {
        $positions = [];
        $stack = $store->redoStack($sessionId);
        if (\count($stack) >= 2) {
            $positions[] = ['index' => $stack[0]['index'], 'workspace' => $stack[0]['workspace']];
        }
        if (\count($positions) < $limit) {
            foreach ($store->listCheckpoints($sessionId, $limit - \count($positions)) as $checkpoint) {
                $positions[] = [
                    'index' => (int) $checkpoint['index'],
                    'workspace' => self::checkpointWorkspace($checkpoint['state_data']),
                ];
            }
        }

        return $positions;
    }

    /**
     * The state `/redo` returns to last: the conversation as it stands now,
     * stored in the pre-turn shape a checkpoint has, with an empty draft (the
     * box holds the command that is rewinding).
     *
     * @return array<string, mixed>
     */
    public static function redoTipState(CommandContext $context): array
    {
        return [
            'messages' => CompactionService::withoutContextReminders($context->history),
            TurnController::CHECKPOINT_PRE_TURN_KEY => true,
            'inputBuf' => '',
            'inputCursor' => null,
            'inFlight' => false,
            'agentContext' => [
                'currentSessionId' => $context->sessionId,
            ],
        ];
    }

    /**
     * The workspace outcome a decoded checkpoint state carries, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function checkpointWorkspace(mixed $state): ?array
    {
        if (!\is_array($state)) {
            return null;
        }
        $key = EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY;
        $workspace = $state[$key] ?? $state['state_data'][$key] ?? null;

        return \is_array($workspace) ? $workspace : null;
    }

    /** @param array<string, mixed>|null $workspace */
    public static function noSnapshotReason(?array $workspace): string
    {
        $reason = \is_string($workspace['reason'] ?? null) ? $workspace['reason'] : null;

        return $reason === null
            ? Lang::t('host.checkpoint.no_snapshot')
            : Lang::t('host.checkpoint.no_snapshot_because', ['reason' => $reason]);
    }

    /** @param array{status: string, written: int, deleted: int, reason: string} $result */
    public static function fileRestoreReport(array $result): string
    {
        return match ($result['status']) {
            'restored' => Lang::t('host.checkpoint.files_restored', [
                'written' => $result['written'],
                'deleted' => $result['deleted'],
            ]),
            'unchanged' => Lang::t('host.checkpoint.files_match'),
            default => Lang::t('host.checkpoint.files_unrestored', ['reason' => $result['reason']]),
        };
    }

    /**
     * The messages, draft and caret a checkpoint state restores.
     *
     * @param array<string, mixed> $state
     * @return array{0: list<Message>, 1: string, 2: ?int}
     */
    public static function checkpointChatState(array $state): array
    {
        $messages = $state['state_data']['messages'] ?? $state['messages'] ?? [];
        // Raw arrays become Message objects, healing any placeholder whose tool
        // call died with the checkpointing process (crush_feat.md §1 E7).
        $messages = array_map(
            static fn (array $msg): Message => Chat::reviveCheckpointMessage($msg),
            \is_array($messages) ? array_values($messages) : [],
        );
        $draft = $state['state_data']['inputBuf'] ?? $state['inputBuf'] ?? '';
        $draft = \is_string($draft) ? $draft : '';
        // E4: the caret offset the checkpoint captured, as a flat codepoint
        // offset; absent (null) for hand-saved or pre-E4 checkpoints, which
        // restore with the caret at the end.
        $cursor = $state['state_data']['inputCursor'] ?? $state['inputCursor'] ?? null;

        // AN OLDER CHECKPOINT STILL ENDS ON THE PROMPT ITS DRAFT RE-SEEDS (audit
        // SES-1): before the save side learned to store the pre-turn
        // transcript, every auto-save serialised the history WITH the user's
        // line, so restoring one as-is leaves the prompt in the transcript and
        // in the box at once — Enter sends it twice. Only when the checkpoint
        // lacks the pre-turn marker: in a current one a trailing user row equal
        // to the draft is a real earlier turn (the same prompt sent twice).
        $key = TurnController::CHECKPOINT_PRE_TURN_KEY;
        $preTurnShape = ($state['state_data'][$key] ?? $state[$key] ?? false) === true;
        if (!$preTurnShape) {
            $messages = self::withoutLegacyTrailingPrompt($messages, $draft);
        }

        return [$messages, $draft, \is_int($cursor) ? $cursor : null];
    }

    /**
     * A checkpoint's conversation restored, its draft offered back, and the
     * command and its reply appended as UI-only rows.
     *
     * @param list<Message> $messages
     */
    public static function restored(array $messages, string $draft, ?int $cursor, string $text, string $response): CommandResult
    {
        return CommandResult::reply($text, $response)
            ->withEffect(CommandEffect::restoreCheckpoint($messages, $draft, $cursor));
    }

    /** @param list<Message> $messages */
    public static function agentVisibleCount(array $messages): int
    {
        return \count(array_filter($messages, static fn (Message $message): bool => !$message->uiOnly));
    }

    /**
     * $text in a Markdown code fence one backtick longer than the longest run
     * inside it, so a patch that itself contains a fence cannot close this one
     * early.
     */
    public static function fenced(string $text, string $info): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $text, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, \strlen($run));
            }
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence . $info . "\n" . $text . "\n" . $fence;
    }

    /**
     * A pre-SES-1 checkpoint's $messages with the prompt it was taken for cut
     * off the end.
     *
     * That save serialised the dispatched history, whose tail was
     * `[...notes, user prompt, 70% reminder?]`, so the prompt is the last user
     * row and only system rows can follow it. It is dropped together with
     * those followers only when its content is exactly the restored draft —
     * trimmed, the way a submit trims it — so a checkpoint that does not end on
     * its own prompt is restored untouched.
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    public static function withoutLegacyTrailingPrompt(array $messages, string $draft): array
    {
        $prompt = trim($draft);
        if ($prompt === '') {
            return $messages;
        }

        $messages = array_values($messages);
        for ($i = \count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if ($message->role === Role::System) {
                continue;
            }

            return $message->role === Role::User && $message->content === $prompt
                ? \array_slice($messages, 0, $i)
                : $messages;
        }

        return $messages;
    }

    /** The store a checkpoint command reads — {@see refusal()} has vouched for it. */
    public static function store(CommandContext $context): EnhancedSessionStore
    {
        $store = $context->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            throw new \LogicException('A checkpoint command needs an EnhancedSessionStore; ask refusal() first.');
        }

        return $store;
    }
}
