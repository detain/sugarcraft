<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Goal;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\GoalJudgedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\TranscriptDigest;

/**
 * The `/goal` judge (roadmap 3.D-3): after every turn of a session with a live
 * goal, one tool-less call on the TITLE backend reads the transcript and says,
 * in strict JSON, whether the goal is met — and if not, what is missing, which
 * the follow-up prompt hands back to the agent.
 *
 * Ported from OpenHands' goal loop (`SDK/conversation/goal/prompts.py`): the
 * `{score, complete, missing}` answer and its one load-bearing rule, that a
 * requirement the agent CLAIMED but the transcript does not show is NOT
 * satisfied. Claude Code's `/goal` is the same shape — "a session-scoped
 * prompt-based Stop hook" on a small fast model that "doesn't run commands or
 * read files independently" — and so is this: the judge sees only the digest
 * ({@see TranscriptDigest}), so the agent has to SHOW the result.
 *
 * ONLY EVER THE TITLE BACKEND (audit 15b-12's rule for every side call): the
 * main backend may be agentic or tool-armed, and a judge that could act would
 * be a second agent. No title backend, no judge — `/goal` says so up front.
 *
 * The prompts the agent itself reads ({@see kickoffPrompt()},
 * {@see followupPrompt()}) are sent as ordinary user turns, so the transcript
 * shows exactly what drove each round and every gate a typed prompt passes —
 * the spend cap, the compaction tiers, `UserPromptSubmit` — applies to them.
 */
final class GoalJudge
{
    /** The judge's system prompt. */
    public const JUDGE_PROMPT = 'You are a strict judge. You decide whether an AI coding agent has fully achieved a goal, '
        . 'using only the conversation transcript you are shown. You cannot run commands or read files yourself.' . "\n\n"
        . "Rules:\n"
        . '- A requirement is satisfied only when the transcript shows evidence of it: command or test output, '
        . 'file contents, a tool result. The agent saying it did something, or that it is done, is a claim, not '
        . "evidence: treat a claimed-but-unverified requirement as NOT satisfied.\n"
        . "- The goal is complete only when every part of it is satisfied.\n"
        . "- Judge the goal as written; do not add requirements of your own.\n\n"
        . 'Reply with ONLY a JSON object, no prose and no code fence:' . "\n"
        . '{"score": <integer 0-100, how much of the goal is verifiably done>, '
        . '"complete": <true only if every part is verifiably satisfied>, '
        . '"missing": [<one short string per part that is not yet verifiably satisfied>]}';

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The judge's request: its rules as the system turn, then the goal and the
     * transcript in one user turn.
     *
     * @param array<int, Message> $history
     * @return list<Message>
     */
    public function request(string $condition, array $history): array
    {
        return [
            Message::system(self::JUDGE_PROMPT),
            Message::user(
                "The goal:\n<goal>\n" . TranscriptDigest::fenced($condition, 'goal') . "\n</goal>\n\n"
                . "The transcript, oldest first:\n<transcript>\n" . TranscriptDigest::of($history) . "\n</transcript>\n\n"
                . 'Is the goal met? Reply with the JSON object only.',
            ),
        ];
    }

    /**
     * The judging call as a thunk resolving to a {@see GoalJudgedMsg} — never
     * rejecting: a failed call or an unreadable answer resolves with $error
     * set, so the caller can say why the loop stopped.
     *
     * @param array<int, Message> $history
     * @return \Closure(): PromiseInterface<Msg>
     */
    public function call(Backend $titleBackend, string $condition, array $history, int $generation, ?string $sessionId): \Closure
    {
        $request = $this->request($condition, $history);

        return static function () use ($titleBackend, $request, $generation, $sessionId): PromiseInterface {
            try {
                $promise = $titleBackend->completeAsync($request);
            } catch (\Throwable $e) {
                return \React\Promise\resolve(new GoalJudgedMsg($generation, $sessionId, null, self::errorText($e)));
            }

            return $promise->then(
                static function (Message $reply) use ($generation, $sessionId): Msg {
                    $verdict = GoalVerdict::parse($reply->content);

                    return new GoalJudgedMsg(
                        $generation,
                        $sessionId,
                        $verdict,
                        $verdict === null ? 'the judge did not answer with the JSON verdict it was asked for' : null,
                        $reply->usage,
                    );
                },
                static fn (\Throwable $e): Msg => new GoalJudgedMsg($generation, $sessionId, null, self::errorText($e)),
            );
        };
    }

    /** The prompt that starts a goal's first turn. */
    public static function kickoffPrompt(GoalState $goal): string
    {
        return sprintf(
            "Work toward this goal until it is met:\n\n**Goal:** %s\n\n"
            . 'Each time you stop, an independent judge reads this conversation and decides whether the goal is '
            . 'met. It cannot run anything itself and only accepts evidence it can see here (command or test '
            . 'output, file contents), so verify the result and show it: saying it is done is not enough.',
            $goal->condition,
        );
    }

    /** The prompt that sends the agent back to work after a "not met" verdict; $goal is the round it starts. */
    public static function followupPrompt(GoalState $goal, GoalVerdict $verdict): string
    {
        $missing = $verdict->missing === []
            ? 'The judge did not find evidence that the goal is met.'
            : "Still missing, according to the judge:\n- " . implode("\n- ", $verdict->missing);

        return sprintf(
            "The goal is not met yet (follow-up %d of %d%s).\n\n**Goal:** %s\n\n%s\n\n"
            . 'Keep working toward the goal. Verify each part and show the evidence.',
            $goal->round,
            $goal->mode->maxRounds(),
            $verdict->score === null ? '' : ', ' . $verdict->scoreLabel(),
            $goal->condition,
            $missing,
        );
    }

    private static function errorText(\Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $e->getMessage()) ?? '');

        return 'the judge call failed' . ($message === '' ? '' : ': ' . mb_substr($message, 0, 200));
    }
}
