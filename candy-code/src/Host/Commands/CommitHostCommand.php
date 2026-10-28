<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Workspace\AutoCommittedMsg;
use SugarCraft\Code\Workspace\AutoCommitter;
use SugarCraft\Code\Workspace\CommitMessageWriter;

/**
 * `/commit [message]` (C-AGT-6.a, roadmap GAP-GIT-03) — commit every changed
 * path in the project, drafting the message on the title model when the user
 * gave none. The built-in `/commit` both Claude Code and OpenClaw ship; the
 * gap this closes is that sugar-crush could only commit as fast as its
 * `autoCommit` setting commits on its own.
 *
 * The work runs OFF-TURN, as a {@see CommandEffect::async()}: staging, the
 * title model's call and `git commit` are subprocess-and-network time a UI
 * frame must not wait inside — the answer lands as a later row, like `/btw`'s
 * and `/bg`'s do. While the call is in flight the transcript already holds the
 * echoed command, so a user who sends a second prompt sees what is owed to
 * them. Mid-turn the command is refused by the turn controller before it ever
 * dispatches, which also keeps it from staging files an agent is still writing.
 *
 * The commit rides the hardened {@see AutoCommitter::commit()} path through
 * {@see AutoCommitter::commitAll()}: the user's configured `core.hooksPath`
 * runs, the attribution trailer rides, and the record is written with kind
 * `command` under the session id — the same pairing `/undo` already reads, so
 * the next `/undo` takes this commit back before anything else, under the
 * rule every kind shares: a commit that introduces a path its parent did not
 * have is refused by name, loudly, rather than half-reverted.
 * Drafting reuses the {@see CommitMessageWriter} contract the `turn`-mode
 * auto-commit dialed from day one: same request, same subject-reading, same
 * plain fallback when there is no title model or the call fails.
 *
 * NOT read-only-safe, by the default (no roster edit): a read-only window
 * refuses with the shared `host.command.read_only` sentence, exactly as `/pr`
 * does — writing to the repository is the command's whole point.
 *
 * One honest wording note: the settled outcome resolves an
 * {@see AutoCommittedMsg} — the existing DTO whose lander bills the title
 * model's usage on both hosts. The TUI prints that DTO's own notice line (the
 * turn-mode "Auto-committed …" wording); served hosts get the `host.commit.*`
 * row {@see self::settledRow()} describes. One DTO, two landers, no new Msg
 * class and no handler-arm edit.
 */
final class CommitHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/commit']));
        }

        $committer = AutoCommitter::new($context->projectRoot())
            ->withSessionId($context->sessionId ?? '')
            ->withTrailer(AutoCommitter::trailerFor($context->workspace?->userConfig['attribution'] ?? null));

        if ($committer->topLevel() === null) {
            return CommandResult::reply($text, Lang::t('host.commit.not_repo'));
        }
        if ($committer->changedPaths() === []) {
            return CommandResult::reply($text, Lang::t('host.commit.nothing'));
        }

        $message = CommandText::argument($text);
        $sessionId = $context->sessionId;

        return $message === ''
            ? CommandResult::echo($text)->withEffect(CommandEffect::async(
                self::draftThunk($committer, $context->titleBackend, $context->history, $sessionId),
                self::settledRow(...),
            ))
            : CommandResult::echo($text)->withEffect(CommandEffect::async(
                self::commitThunk($committer, $message, null, $sessionId),
                self::settledRow(...),
            ));
    }

    /**
     * The commit itself as a thunk resolving to an {@see AutoCommittedMsg} —
     * never rejecting: a hook that refuses the commit answers with its own
     * complaint, the way every off-turn command row does.
     *
     * @return \Closure(): PromiseInterface<Msg>
     */
    public static function commitThunk(AutoCommitter $committer, string $subject, ?Usage $usage, ?string $sessionId): \Closure
    {
        return static fn (): PromiseInterface => \React\Promise\resolve(self::settle($committer, $subject, $usage, $sessionId));
    }

    /**
     * Draft-then-commit as one thunk: the title model is asked (through the
     * same request and the same subject-reading the `turn`-mode settle uses,
     * with the last user prompt as the context line), and whatever comes
     * back — usable, unparseable, or an exception — the commit still lands,
     * with the model's subject when there is one and a plain
     * {@see CommitMessageWriter::fallback()} subject when there is not. A
     * title model that failed is no reason to leave the tree uncommitted.
     *
     * @param list<Message> $history
     * @return \Closure(): PromiseInterface<Msg>
     */
    public static function draftThunk(AutoCommitter $committer, ?Backend $titleBackend, array $history, ?string $sessionId): \Closure
    {
        $context = '';
        foreach (array_reverse(Message::agentVisible($history)) as $message) {
            if ($message->role === Role::User) {
                $context = $message->content;
                break;
            }
        }

        return static function () use ($committer, $titleBackend, $context, $sessionId): PromiseInterface {
            $paths = $committer->changedPaths();
            $finish = static fn (mixed $reply, ?Usage $usage): Msg => self::settle(
                $committer,
                (is_string($reply) ? CommitMessageWriter::subjectFrom($reply) : null)
                    ?? CommitMessageWriter::fallback($paths),
                $usage,
                $sessionId,
            );

            if ($titleBackend === null) {
                return \React\Promise\resolve($finish(null, null));
            }

            try {
                $promise = $titleBackend->completeAsync(CommitMessageWriter::request($committer->draftDiff($paths), $context));
            } catch (\Throwable) {
                return \React\Promise\resolve($finish(null, null));
            }

            return $promise->then(
                static fn (Message $reply): Msg => $finish($reply->content, $reply->usage),
                static fn (\Throwable $e): Msg => $finish(null, null),
            );
        };
    }

    /**
     * The commit as one settled DTO: the answer both hosts land, never an
     * exception. A hook that refused the commit (or a rarer throw from the
     * collector) arrives as the commit's own reason, one line, untrusted
     * bytes stripped.
     */
    private static function settle(AutoCommitter $committer, string $subject, ?Usage $usage, ?string $sessionId): AutoCommittedMsg
    {
        try {
            $outcome = $committer->commitAll($subject);
        } catch (\Throwable $e) {
            return new AutoCommittedMsg(
                error: self::oneLine($e->getMessage()),
                usage: $usage,
                sessionId: $sessionId,
            );
        }

        return new AutoCommittedMsg(
            sha: $outcome['sha'],
            subject: $outcome['subject'],
            paths: $outcome['paths'],
            error: $outcome['ok'] ? null : $outcome['reason'],
            usage: $usage,
            sessionId: $sessionId,
        );
    }

    /**
     * The row a settled commit lands as on a served host (ISS-097 channel:
     * describe returns the row, not the text). The failure carries its own
     * verdict; a success is an ordinary local line naming the commit, its
     * subject and how many files went in.
     */
    public static function settledRow(mixed $msg): ?Message
    {
        if (!$msg instanceof AutoCommittedMsg) {
            return null;
        }

        if ($msg->sha === null) {
            return Message::assistant(Lang::t('host.commit.failed', [
                'reason' => self::oneLine($msg->error ?? Lang::t('host.commit.no_reason')),
            ]))->withUiOnly()->withError(true);
        }

        $count = \count($msg->paths);

        return Message::assistant(Lang::t('host.commit.committed', [
            'sha' => substr($msg->sha, 0, 7),
            'subject' => $msg->subject,
            'files' => $count === 1 ? '1 file' : $count . ' files',
        ]))->withUiOnly();
    }

    /** The reason as one bounded display line, control bytes stripped. */
    private static function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', Sanitize::untrusted($text)));

        return $line === '' ? Lang::t('host.commit.no_reason') : mb_substr($line, 0, 200);
    }
}
