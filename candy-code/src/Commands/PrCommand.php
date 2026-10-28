<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

/**
 * The `/pr` command (roadmap GAP-GIT-04, plan §6.1 R13): ask the agent to
 * push the current branch and open a pull request for it with the user's own
 * `gh`.
 *
 * A CANNED PROMPT, NOT A HANDLER. Opening a pull request is a judgement
 * call about the change — what the title should say, what the body owes the
 * reviewer, whether the repository's contribution rules forbid what is
 * staged — exactly the part a fixed command could not do well. The agent
 * already holds the branch, the diff and the tools, so the command is a
 * prompt, dispatched as an ordinary turn through
 * {@see \SugarCraft\Code\Chat::dispatchCommand()} — the same road `/init`
 * takes, so the spend cap, the compaction tiers and the UserPromptSubmit
 * hook judge it exactly as they judge typed prose.
 *
 * THE WRITE IS GATED WHERE IT HAPPENS. `gh pr create` posts to the user's
 * host, so it runs inside the turn through the Bash tool, where the
 * session's permission mode gates it like any other command — the Manual
 * default still asks, and a deny rule still denies. That is why this command
 * needs no pre-clamp of its own: unlike a review, whose whole contract is
 * read-only, `/pr` is honestly a write, and it is gated as one. It belongs
 * to no read-only roster.
 *
 * ATTRIBUTION. The `attribution.pr` layered setting already reaches the turn
 * through {@see \SugarCraft\Code\Tools\BuiltIn\Bash}'s git guidance, which
 * renders it when non-empty; the prompt defers to whatever that guidance
 * says rather than duplicating the template.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush
 * has no `/pr`; the idea is Claude Code's and opencode's.
 */
final class PrCommand
{
    /**
     * The prompt `/pr` sends. `%s` is the focus paragraph — empty for a bare
     * `/pr`. Kept free of `@` so the mention scanner, which reads what the
     * user typed, finds nothing to attach in it.
     */
    public const PROMPT = <<<'PROMPT'
        Push the current branch to its remote and open a pull request for it with the `gh` command-line tool.

        Start by looking at what is actually being submitted: the branch's commits against its base, and the resulting diff. If the branch is not pushed yet, push it first. Then open the pull request with `gh pr create`, writing a title and body that describe THIS change — what it does and why — based on the commits and the diff, not on the branch name. Follow the repository's own contribution and pull-request rules where it has them, and follow any pull-request attribution line the session's git guidance carries.

        Every `gh` call runs inside this turn through the Bash tool, so it is subject to the session's permission rules. If it fails — no credentials, no remote, the host refusing — name exactly what failed and hand back the command the user can run themselves. Do not work around a failed or refused step by pushing somewhere else or posting to another host.

        When it succeeds, report the pull request URL.
        %s
        PROMPT;

    /** The paragraph `/pr <focus>` appends; `%s` is the focus text. */
    public const FOCUS_FORMAT = "\nThe user asked you to pay particular attention to: %s";

    private function __construct()
    {
    }

    /**
     * The prompt for `/pr`, with $focus (the command's argument, if any)
     * appended as an extra instruction.
     */
    public static function prompt(string $focus = ''): string
    {
        $focus = trim($focus);

        return rtrim(sprintf(self::PROMPT, $focus === '' ? '' : sprintf(self::FOCUS_FORMAT, $focus)));
    }
}
