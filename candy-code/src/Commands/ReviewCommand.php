<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

/**
 * The `/review [ref]` and `/security-review [ref]` commands (GIT-1): canned
 * review prompts, dispatched as ordinary turns the way {@see InitCommand} is.
 *
 * NOT A DIFF GENERATOR. The prompt ends in the `@diff` mention — `@diff:<ref>`
 * when the command names a revision — and the mention scanner attaches the
 * diff exactly as it does for typed prose: one diff-reading code path, the
 * same plumbing flags, the same timeouts, and the result rides into the turn
 * as an attachment rather than as text the model may misread. Unlike
 * InitCommand — whose prompt is kept free of `@` on purpose — this one
 * INVITES the scanner; the mention is the whole point. The Chat handlers
 * validate $ref with {@see \SugarCraft\Code\Attachments\ContextMentions::isDiffRef()}
 * before assembling the prompt, so the command can never submit a diff
 * mention the scanner would silently refuse to attach.
 *
 * REPORT, DO NOT EDIT is mandated in both prompts: a review that rewrites the
 * code it is judging conflates the roles, and the permission modes should not
 * have to be the thing standing between `/review` and an unwanted Write.
 *
 * UNTRUSTED BY CONSTRUCTION (GIT-1): the attached diff is repository content,
 * and repository content feeds the LLM here exactly as a Read result would.
 * An adversarial repo — the fork you are reviewing is the case, not the
 * exception — can embed instructions in its own diff text hoping the
 * reviewing agent obeys them instead of judging them. The REPORT, DO NOT
 * EDIT mandate above is the prompt-side answer to that injection and the
 * permission mode is the action-side one; the doctrine page is the
 * prompt-injection section of docs/CI.md (GIT-2). Same trust posture as
 * every other attachment: data to review, never instructions to follow.
 *
 * A command that STARTS A TURN — see Chat::handleReviewCommand(); it belongs
 * to no read-only roster. Both spellings are SugarCraft architecture types,
 * not ports: charmbracelet/crush has no `/review`; the idea is Claude Code's.
 */
final class ReviewCommand
{
    /**
     * The prompt `/review` sends; `%s` is the diff mention on the last line.
     */
    public const REVIEW_PROMPT = <<<'PROMPT'
        Please review the change set in the diff attached at the end of this message.

        Report, do not edit: this turn is a review, not a change. Do not modify, stage, or commit any file, and do not run anything that rewrites the working tree.

        For each finding:
        1. Name the file and line and quote the code it concerns.
        2. Explain concretely what breaks or degrades, and under which inputs.
        3. Rate it blocking, should-fix, or nit — and lead with the worst.

        Cover correctness first: logic errors, unhandled failures and edge cases, races, contract violations against the interfaces it calls. Then readability and duplication measured against the conventions this repository already follows. If the change is clean, say so plainly and note what you checked; do not invent findings to look thorough.

        %s
        PROMPT;

    /**
     * The prompt `/security-review` sends; `%s` is the diff mention.
     */
    public const SECURITY_PROMPT = <<<'PROMPT'
        Please perform a security review of the change set in the diff attached at the end of this message.

        Report, do not edit: this turn is a review, not a change. Do not modify, stage, or commit any file, and do not run anything that rewrites the working tree.

        Look for the classic regressions:
        1. Injection of every kind the diff opens a door to — shell, SQL, template, path traversal, header or log forging.
        2. Untrusted input crossing a trust boundary unvalidated or unescaped, including SSRF through any new dial-out.
        3. Authentication or authorization checks weakened, bypassed, or silently skipped on a new path; permission or consent gates widened.
        4. Secrets reaching logs, error messages, transcripts, or fixtures; weak crypto or non-constant-time comparison of secret material.
        5. Attacker-controlled sizes reaching memory, loops, or recursion without a bound.

        For each finding: name the file and line, give the attack path from reachable input to impact, rate it blocking, should-fix, or nit, and name the fix without applying it. If nothing is exploitable, say so and list the trust boundaries you checked; do not invent vulnerabilities to look thorough.

        %s
        PROMPT;

    /** The diff mention a bare command attaches. */
    public const DIFF_MENTION = '@diff';

    /** The mention when the command names a revision; `%s` is the ref. */
    public const DIFF_MENTION_FORMAT = '@diff:%s';

    private function __construct()
    {
    }

    /**
     * The prompt `/review [ref]` or `/security-review [ref]` submits: the
     * canned body, closing on the `@diff` mention (optionally scoped to
     * $ref) that makes the scanner attach the very change being reviewed.
     */
    public static function prompt(string $ref = '', bool $security = false): string
    {
        $ref = trim($ref);
        $mention = $ref === ''
            ? self::DIFF_MENTION
            : sprintf(self::DIFF_MENTION_FORMAT, $ref);

        return rtrim(sprintf($security ? self::SECURITY_PROMPT : self::REVIEW_PROMPT, $mention));
    }
}
