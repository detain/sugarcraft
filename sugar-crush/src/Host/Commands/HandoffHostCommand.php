<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Support\TranscriptDigest;
use SugarCraft\Crush\Usage;

/**
 * `/handoff [focus]` (roadmap 5.14c) — carry the work into a NEW session that
 * starts from a state summary of this one, instead of from its whole transcript.
 *
 * Cline's `/newtask` and Kilo's plan hand-over are the model: when a context is
 * full of dead ends, the cheapest way on is a fresh session that knows only
 * where the work stands. The summary is the W5 state block
 * ({@see StateSummaryTemplate}, roadmap 2.5), so the new session reads the same
 * fixed headings a compaction leaves, and every later compaction there merges
 * with it as the previous state row.
 *
 * WHO WRITES IT. The session's summary model, when there is one and the spend
 * cap allows, writes the model headings; the derived ones (files read and
 * modified, the latest request verbatim) are read off the transcript and any
 * heading the model left out is filled from the heuristic block, the audit a
 * compaction's block gets. With no model — or one that failed or wrote no
 * block — the heuristic block alone is the seed, so `/handoff` always opens a
 * session.
 *
 * LINKED AS A BRANCH. The new session is a fork ({@see SessionStore::forkSession()},
 * kind `branch`, `parent_id` this session) whose transcript is then replaced by
 * the one seed row, so the picker groups it under its source, it inherits the
 * working directory, the todos and the checkpoints — `/rewind` there can still
 * step back into the full conversation — and its context ledger starts empty,
 * since the rows the parent's ledger names are not in it.
 *
 * THE SEED IS A USER ROW the agent reads on its first turn: a transcript that
 * opens on an assistant row is one strict providers reject (audit 15b-03). It
 * carries the `[summary] ` marker, so the compaction paths treat it as the
 * prior state rather than as a request.
 */
final class HandoffHostCommand implements HostCommand
{
    /**
     * The framing the summary model is given. `%s` is the heading list, built
     * from {@see StateSummaryTemplate::MODEL_HEADINGS} so the two cannot drift.
     */
    public const PROMPT = <<<'PROMPT'
        You are writing a handoff. The conversation below, between a user and an AI coding agent, continues in a NEW
        session, and the agent there starts from your summary alone: write enough for a developer new to the work to
        pick up exactly where it left off. You cannot run tools.

        Write ONE state block: a line <session-state>, then these headings in this order, each on its own line with its
        content under it, then a line </session-state>:
        %s
        Write "none" under a heading with nothing to record. Carry any security-relevant instruction or constraint the
        user stated (what not to touch, what not to send, a permission boundary) into Constraints VERBATIM, quoted. Keep
        file paths, command names and error strings exactly as they appear. Do not list files and do not restate the
        user's latest request: both are filled in mechanically. Record only what the conversation shows; inventing a
        decision or a preference is worse than leaving it out. No preamble and no commentary outside the block.
        PROMPT;

    /** What each heading's line in {@see PROMPT} adds after its name. */
    private const HEADING_NOTES = [
        'Progress' => ' (three sub-lists: ### Done, ### In progress, ### Blocked)',
        'Pending tasks' => ' (with their ids where the conversation gave any)',
        'Errors and fixes' => ' (each error string verbatim, then what fixed it)',
    ];

    /**
     * @param Backend|null $summaryBackend the backend that writes the summary;
     *        read only when $summaryBackendSet, else the context's title backend
     * @param bool $summaryBackendSet whether the driver chose the backend —
     *        a TUI at its spend cap passes null and true, so nothing is asked
     */
    public function __construct(
        private readonly ?Backend $summaryBackend = null,
        private readonly bool $summaryBackendSet = false,
    ) {
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        $store = $context->sessionStore;
        if ($store === null) {
            return CommandResult::reply($text, 'Session store not configured. Set a SessionStore to use /handoff.');
        }

        $sessionId = $context->sessionId;
        if ($sessionId === null) {
            return CommandResult::reply($text, 'No active session. Start a conversation first.');
        }

        if (Message::agentVisible($context->history) === []) {
            return CommandResult::reply($text, 'Nothing to hand off yet: the agent has not been sent anything in this session.');
        }

        $backend = $this->summaryBackendSet ? $this->summaryBackend : $context->titleBackend;

        return CommandResult::reply($text, $backend === null
            ? 'Handing off: opening a new session from a state summary of this one…'
            : 'Handing off: the summary model is writing the state summary the new session starts from…')
            ->withEffect(CommandEffect::async(
                self::call($store, $context->transcripts, $sessionId, $context->history, $backend, CommandText::argument($text)),
                static fn (mixed $msg): ?string => $msg instanceof HandoffSeededMsg ? self::describe($msg) : null,
            ));
    }

    /**
     * The summary model's request: the framing, then the transcript, any state
     * block an earlier compaction left, and the user's focus, in one user turn.
     *
     * @param array<int, Message> $history
     * @return list<Message>
     */
    public static function request(array $history, string $focus = ''): array
    {
        $transcript = TranscriptDigest::of($history);
        $content = "The conversation so far, oldest first:\n<transcript>\n"
            . ($transcript === '' ? '(empty)' : $transcript) . "\n</transcript>";

        $previous = StateSummaryTemplate::latestIn(array_map(
            static fn (Message $m): string => $m->content,
            Message::agentVisible($history),
        ));
        if ($previous !== null) {
            $content .= "\n\nAn earlier compaction of this conversation left this state block. Carry forward what still "
                . "holds; where it and the conversation disagree, the conversation wins:\n<prior-summary>\n"
                . TranscriptDigest::fenced($previous->render(), 'prior-summary') . "\n</prior-summary>";
        }

        $focus = trim($focus);
        if ($focus !== '') {
            $content .= "\n\nThe user asked the handoff to pay particular attention to:\n<focus>\n"
                . TranscriptDigest::fenced($focus, 'focus') . "\n</focus>";
        }

        return [
            Message::system(self::systemPrompt()),
            Message::user($content . "\n\nWrite the state block now. Do not call any tool."),
        ];
    }

    /** {@see PROMPT} with its heading list filled in. */
    public static function systemPrompt(): string
    {
        $lines = array_map(
            static fn (string $heading): string => '  ## ' . $heading . (self::HEADING_NOTES[$heading] ?? ''),
            StateSummaryTemplate::MODEL_HEADINGS,
        );

        return sprintf(self::PROMPT, implode("\n", $lines));
    }

    /**
     * The block a model reply carries, audited against $fallback — or null
     * when the reply names none of the model headings, which is no block.
     */
    public static function stateFromReply(string $reply, StateSummaryTemplate $fallback): ?StateSummaryTemplate
    {
        $reply = Sanitize::untrusted((string) preg_replace('/<think>.*?<\/think>/is', '', $reply));
        [$block] = StateSummaryTemplate::extractFromReply($reply);
        $parsed = StateSummaryTemplate::parse($block ?? $reply);
        if (\count($parsed->missingHeadings()) === \count(StateSummaryTemplate::MODEL_HEADINGS)) {
            return null;
        }

        return $parsed->filledFrom($fallback);
    }

    /** The seed row's content: the rendered block, marked as a summary row. */
    public static function seedRow(StateSummaryTemplate $state): string
    {
        return StateSummaryTemplate::ROW_PREFIX . $state->render();
    }

    /**
     * The handoff as a thunk resolving to a {@see HandoffSeededMsg} — never
     * rejecting: a failed summary falls back to the heuristic block, and a
     * session that cannot be opened says why.
     *
     * @param array<int, Message> $history
     * @return \Closure(): PromiseInterface<HandoffSeededMsg>
     */
    public static function call(
        SessionStore|EnhancedSessionStore $store,
        TranscriptStore $transcripts,
        string $parentId,
        array $history,
        ?Backend $backend,
        string $focus = '',
    ): \Closure {
        $fallback = CompactionService::heuristicState(array_values($history));
        $request = self::request($history, $focus);

        $open = static function (StateSummaryTemplate $state, bool $modelWritten, ?Usage $usage, ?string $note) use ($store, $transcripts, $parentId): HandoffSeededMsg {
            $seed = self::seedRow($state);
            try {
                // The fork copies the STORED parent, so what is still waiting
                // on the debounce is written first (audit R2).
                $transcripts->flush();
                $id = $store->forkSession($parentId, SessionKind::Branch);
                $transcripts->save($id, [Message::user($seed)]);
                $transcripts->saveLedger($id, ContextLedger::new());
            } catch (\Throwable $e) {
                return new HandoffSeededMsg($parentId, null, $seed, $modelWritten, $usage, 'the new session could not be opened: ' . self::oneLine($e->getMessage()));
            }

            return new HandoffSeededMsg($parentId, $id, $seed, $modelWritten, $usage, $note);
        };

        return static function () use ($backend, $request, $fallback, $open): PromiseInterface {
            if ($backend === null) {
                return \React\Promise\resolve($open($fallback, false, null, null));
            }

            $failed = static fn (\Throwable $e): HandoffSeededMsg => $open(
                $fallback,
                false,
                null,
                'the summary model failed (' . self::oneLine($e->getMessage()) . '), so the summary was written from the transcript',
            );
            try {
                $promise = $backend->completeAsync($request);
            } catch (\Throwable $e) {
                return \React\Promise\resolve($failed($e));
            }

            return $promise->then(
                static function (Message $reply) use ($fallback, $open): HandoffSeededMsg {
                    $state = self::stateFromReply($reply->content, $fallback);

                    return $state === null
                        ? $open($fallback, false, $reply->usage, 'the summary model wrote no state block, so the summary was written from the transcript')
                        : $open($state, true, $reply->usage, null);
                },
                $failed,
            );
        };
    }

    /**
     * The row a landing adds: where the work went, or why it did not. A TUI
     * that moved onto the new session reads it there; a headless host, whose
     * session id is fixed for life, leaves the new one for a client to open.
     */
    public static function describe(HandoffSeededMsg $msg): string
    {
        if ($msg->sessionId === null) {
            return 'Handoff failed: ' . ($msg->error ?? 'no session was opened') . '.';
        }

        $line = "Handed off to session {$msg->sessionId}, a branch of {$msg->fromSessionId} that starts from "
            . ($msg->modelWritten ? 'the summary model\'s' : 'a mechanical') . ' state summary of it';

        return $line . ($msg->error === null ? '.' : ' — ' . $msg->error . '.');
    }

    private static function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', Sanitize::untrusted($text)));

        return $line === '' ? 'no reason given' : mb_substr($line, 0, 200);
    }
}
