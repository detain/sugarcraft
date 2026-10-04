<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\SideQuestionAnsweredMsg;
use SugarCraft\Crush\Support\TranscriptDigest;

/**
 * `/btw <question>` (roadmap 5.14b) — a side question about the conversation
 * that does NOT become part of it: the title model answers from a snapshot of
 * the transcript, and both the question and the answer are UI-only rows, so
 * the agent never reads either on a later turn.
 *
 * Claude Code's `/btw` and OpenClaw's `/btw` are the model: "not added to
 * history", answered from the existing context with no tools, and usable WHILE
 * a turn runs — the one command that is. It touches no state the running turn
 * writes (it only appends UI-only rows, as a queued-prompt notice does), so the
 * mid-turn refusal every other command gets does not apply
 * ({@see \SugarCraft\Crush\Host\TurnController::midTurnRoute()}), and the
 * result HOLDS the turn rather than releasing it. The request is OpenHands'
 * `ask_agent` template: a stateless call on a snapshot that records nothing.
 *
 * ONLY EVER THE TITLE BACKEND, by the rule every side call follows (audit
 * 15b-12): the main backend may be a tool-armed agent, and a side question
 * must not act. No title model, no side question.
 */
final class BtwHostCommand implements HostCommand
{
    /** The question's framing (OpenHands' `ask_agent` template). */
    public const PROMPT = 'You answer a side question about a conversation between a user and an AI coding agent. '
        . 'The question is not part of that conversation and the agent will not see your answer. You cannot run '
        . 'tools; answer from the conversation shown, briefly, and say so when it does not contain the answer.';

    /** Longest answer kept; it is one transcript row. */
    public const ANSWER_MAX_CHARS = 4000;

    public function run(CommandContext $context, string $text): CommandResult
    {
        $question = CommandText::argument($text);
        if ($question === '') {
            return CommandResult::reply($text, 'Usage: /btw <question> — ask the title model about this conversation; '
                . 'neither the question nor the answer is sent to the agent.')->holdingTurn();
        }

        $backend = $context->titleBackend;
        if ($backend === null) {
            return CommandResult::reply($text, '/btw needs a title model to answer, and none is configured: set '
                . '`titleModel` (or `SUGARCRUSH_TITLE_MODEL`). The main model is never used for a side question.')
                ->holdingTurn();
        }

        return CommandResult::new(Message::user($text)->withUiOnly())
            ->withEffect(CommandEffect::async(
                self::call($backend, $question, $context->history, $context->sessionId),
                static fn (mixed $msg): ?string => $msg instanceof SideQuestionAnsweredMsg ? self::answerRow($msg) : null,
            ))
            ->holdingTurn();
    }

    /**
     * The side question's request: the framing, then the transcript and the
     * question in one user turn.
     *
     * @param array<int, Message> $history
     * @return list<Message>
     */
    public static function request(string $question, array $history): array
    {
        $transcript = TranscriptDigest::of($history);

        return [
            Message::system(self::PROMPT),
            Message::user(
                "The conversation so far, oldest first:\n<transcript>\n"
                . ($transcript === '' ? '(empty)' : $transcript) . "\n</transcript>\n\n"
                . "<QUESTION>\nBased on the conversation so far, answer the following question.\n\n## Question\n"
                . TranscriptDigest::fenced($question, 'QUESTION') . "\n\n<IMPORTANT>\nThis is a question: do not call "
                . "any tool, just answer it.\n</IMPORTANT>\n</QUESTION>",
            ),
        ];
    }

    /**
     * The call as a thunk resolving to a {@see SideQuestionAnsweredMsg} —
     * never rejecting: a failed call answers with what went wrong.
     *
     * @param array<int, Message> $history
     * @return \Closure(): PromiseInterface<Msg>
     */
    public static function call(Backend $titleBackend, string $question, array $history, ?string $sessionId): \Closure
    {
        $request = self::request($question, $history);

        return static function () use ($titleBackend, $request, $sessionId): PromiseInterface {
            $failed = static fn (\Throwable $e): Msg => new SideQuestionAnsweredMsg(
                $sessionId,
                '_The side question failed: ' . self::oneLine($e->getMessage()) . '_',
            );
            try {
                $promise = $titleBackend->completeAsync($request);
            } catch (\Throwable $e) {
                return \React\Promise\resolve($failed($e));
            }

            return $promise->then(
                static fn (Message $reply): Msg => new SideQuestionAnsweredMsg($sessionId, $reply->content, $reply->usage),
                $failed,
            );
        };
    }

    /**
     * The row text an answer lands as: the model's text without a reasoning
     * preamble or terminal control bytes, bounded, and marked as a side answer.
     */
    public static function answerRow(SideQuestionAnsweredMsg $msg): string
    {
        $answer = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $msg->answer));
        $answer = trim(Sanitize::untrusted($answer));
        if ($answer === '') {
            $answer = '_No answer._';
        } elseif (mb_strlen($answer) > self::ANSWER_MAX_CHARS) {
            $answer = mb_substr($answer, 0, self::ANSWER_MAX_CHARS - 1) . '…';
        }

        return "**btw** — not sent to the agent\n\n" . $answer;
    }

    private static function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', Sanitize::untrusted($text)));

        return $line === '' ? 'no reason given' : mb_substr($line, 0, 200);
    }
}
