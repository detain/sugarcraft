<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * The engine's own answer to a blank-returning child session.
 *
 * WHY THIS EXISTS. A launched sub-agent sometimes COMPLETES its turn with a
 * final answer that is blank (or whitespace-only) — the fleet lesson the
 * orchestrating humans code by hand ("blank return → resume the SAME session
 * with 'continue'") was never a machine rule: {@see EngineExecutor} and
 * {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool} both failed such a run on the
 * spot, and the whole fleet died silently on a model that stayed quiet. A
 * blank completion is never useful, so the engine now resumes the SAME child
 * session itself with one minimal continue instruction, up to
 * {@see MAX_CONSECUTIVE_BLANKS} blanks in a row; any non-blank completion
 * resets the streak.
 *
 * IT IS A PER-RUN TRACKER, NOT A SERVICE. One instance guards one child
 * session: construct it where the run loop lives, call
 * {@see observeBlank()} on every blank completion (it answers whether a
 * resume is still owed) and {@see nonBlank()} on every real report. The
 * budget belongs to the session, never to the fleet — a resumed child that
 * itself launches children gets its own instance per child, so the depth
 * cap keeps multiplication bounded.
 *
 * EXHAUSTION IS HONEST. At the tenth consecutive blank the run fails through
 * the exact machinery a mechanical failure already uses (a Failed
 * {@see AgentResult} / a refusal ToolResult), with a reason that names the
 * blank-return stall and the resume count consumed — fed to the stage retry
 * budget, the pause record and `/workflow resume` like any other failure.
 *
 * NO TIMERS ARE ARMED ({@see E646} family law): every decision here happens
 * on a completion event the engine already waits for, never on a clock.
 */
final class BlankReturnResume
{
    /**
     * The give-up point: a tenth CONSECUTIVE blank completion ends the run.
     * Blanks #1 through #9 each buy one same-session resume, so at most
     * {@see MAX_CONSECUTIVE_BLANKS} minus one resumes are issued for one
     * streak.
     */
    public const MAX_CONSECUTIVE_BLANKS = 10;

    /**
     * The whole resume instruction. The child already carries its transcript
     * — the session was interrupted mid-report, not mid-instruction — so the
     * minimal word that a human operator would type is the minimal word the
     * engine sends.
     */
    public const INSTRUCTION = 'continue';

    /** @var int Blanks in the CURRENT streak (reset by {@see nonBlank()}). */
    private int $streak = 0;

    /** @var int Resume instructions issued over the whole run. */
    private int $resumesIssued = 0;

    /**
     * A completion is blank when nothing but whitespace was reported.
     *
     * The engine trims before this point only for the verdict — a report of
     * "   " says exactly as little as "" and is treated identically.
     */
    public static function isBlank(string $content): bool
    {
        return \trim($content) === '';
    }

    /**
     * Record a blank completion and answer whether a resume is still owed.
     *
     * @return bool true when the caller must resume the same session with
     *               {@see INSTRUCTION}; false at the tenth consecutive blank,
     *               where the caller must fail honestly instead
     */
    public function observeBlank(): bool
    {
        ++$this->streak;
        if ($this->streak >= self::MAX_CONSECUTIVE_BLANKS) {
            return false;
        }

        ++$this->resumesIssued;

        return true;
    }

    /**
     * Record a non-blank completion: the streak is broken, the budget of a
     * later streak starts whole again. Cumulative counts survive for the
     * disclosure notes.
     */
    public function nonBlank(): void
    {
        $this->streak = 0;
    }

    /** Consecutive blanks observed so far (reset by {@see nonBlank()}). */
    public function blankStreak(): int
    {
        return $this->streak;
    }

    /** Resume instructions issued over the whole run. */
    public function resumesIssued(): int
    {
        return $this->resumesIssued;
    }

    /**
     * The failure tail naming the stall, in the bracket shape the workflow
     * verdict reasons already use (see `WorkflowEngine::scanVerdict()`'s
     * " [failed on all N attempts]" suffix): a driver reading the stage error
     * learns what happened and how much of the budget went to it.
     */
    public function stallNote(): string
    {
        return \sprintf(
            ' [blank-return stall: %d consecutive blank returns, %d continue-resume(s) consumed — the child session stayed silent and the engine gave up]',
            $this->streak,
            $this->resumesIssued,
        );
    }

    /**
     * The success tail disclosing that the report rode on resumes — the same
     * shape as the G3 schema disclosure: bracketed, appended to the text the
     * driver reads, and EMPTY when nothing was resumed, so a clean run's
     * bytes are untouched.
     */
    public function resumedNote(): string
    {
        if ($this->resumesIssued === 0) {
            return '';
        }

        return \sprintf(
            "\n\n[resumed %d blank return(s) with a continue instruction]",
            $this->resumesIssued,
        );
    }
}
