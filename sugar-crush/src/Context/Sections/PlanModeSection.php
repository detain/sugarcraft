<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Sections;

use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Context\Stability;
use SugarCraft\Crush\Permissions\PermissionGate;

/**
 * The plan-mode contract (roadmap 5.7-1): present in the system prompt only
 * while the session's gate is in `plan` mode, so the model is TOLD what the
 * gate already enforces ({@see PermissionGate::evaluatePlan()}) instead of
 * discovering it one denied call at a time. Cline's lesson (`05-cline.md`
 * §3.1) is the reason it is both: a plan mode enforced only by prompt runs
 * `rm` the first time the model forgets, and one enforced only by the gate
 * spends a step per refusal.
 *
 * WHERE IT SITS. A PerTurn slot directly ahead of the static `<env>` block
 * ({@see \SugarCraft\Crush\Runtime::systemPromptSections()}), so switching
 * the mode moves only the tail of the prompt: every slot a provider caches
 * ahead of it is byte-identical in both modes. The tool catalog is NOT
 * narrowed in plan mode, for the same reason (DeepSeek harness: "the tool
 * catalog stays the same across modes for request-cache stability"), and the
 * section says so, so a refused tool reads as policy rather than as a bug.
 *
 * FENCED `<system-reminder>`, the harness's own reminder voice — the tag is
 * already on {@see \SugarCraft\Crush\Context\PromptFence}'s roster, so a
 * repository byte elsewhere in the prompt cannot forge one. The body is a
 * class constant with no untrusted input in it; nothing here is escaped
 * because nothing here came from outside this file.
 *
 * This is a SugarCraft architecture section, not a port: no `Mirrors
 * charmbracelet/...` citation attaches to it.
 */
final readonly class PlanModeSection implements PromptSection
{
    /**
     * The contract, verbatim. Every claim names what enforces it: the
     * read-only shell list and the plans-directory write are the gate's, the
     * `AskUser`/`PlanExit` questions are the turn approver's
     * ({@see \SugarCraft\Crush\Tools\BuiltIn\PlanExitTool}), and the
     * `Alt+M` exit is the TUI's ({@see \SugarCraft\Crush\Chat}).
     */
    private const BODY = <<<'MARKDOWN'
        <system-reminder>
        # Plan mode

        The session is in plan mode: explore, analyse and write a plan; change nothing else until the user approves it.

        - Reads run: Read, Grep, Glob and the other read-only tools, and a shell command made only of known read-only commands (`git log`, `git diff`, `grep`, `ls`, `cat` …) with no output redirection or substitution.
        - Every other change is refused by the permission gate, not merely discouraged: editing or creating files, `rm`/`mv`/`cp`, `sed -i`, redirecting output into a file, package installs, and git commands that change the working tree. A refused call returns an error and changes nothing. The tool list is the same in every mode, so a tool being offered does not mean it may run.
        - The one write allowed is the plan itself: a Markdown file directly in `.sugar-crush/plans/` (for example `.sugar-crush/plans/add-retry-backoff.md`), with the Write or Edit tool.
        - Ask the user about anything the plan depends on that the code cannot answer with the AskUser tool, one question at a time, with the answer you would recommend first.
        - A finished plan names the goal, the files and boundaries it touches, the steps in order, the risks, and how the result will be verified. Write it to the plans directory, then call PlanExit with its path to put it to the user for approval.
        - Plan mode ends only when the user approves the plan — through PlanExit, or by leaving plan mode themselves (Alt+M) — and the switch takes effect when the turn ends. The original request is not that approval. If PlanExit is refused or unavailable, summarise the plan in your reply and end the turn.
        </system-reminder>
        MARKDOWN;

    public function fence(): string
    {
        return '<system-reminder>';
    }

    public function stability(): Stability
    {
        return Stability::PerTurn;
    }

    /**
     * Advisory ceiling; see {@see PromptSection::byteBudget()}. PHP_INT_MAX
     * like every other production section.
     */
    public function byteBudget(): int
    {
        return \PHP_INT_MAX;
    }

    public function render(): string
    {
        return self::BODY;
    }
}
