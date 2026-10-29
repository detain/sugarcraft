<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Sections;

use SugarCraft\Code\Context\PromptSection;
use SugarCraft\Code\Context\Stability;
use SugarCraft\Code\Permissions\PermissionGate;

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
 * ({@see \SugarCraft\Code\Runtime::systemPromptSections()}), so switching
 * the mode moves only the tail of the prompt: every slot a provider caches
 * ahead of it is byte-identical in both modes. The tool catalog IS narrowed
 * in plan mode (C-SEC-7.c): EngineBackend's turnTools() drops the
 * declarations the gate always refuses and keeps the mode's own
 * Write/Bash/PlanExit by name. The DeepSeek harness had kept the catalog
 * stable to spare the request cache that such narrowing costs; this design
 * accepts the one prompt-cache break per mode switch instead — the carve-out
 * documented beside the cache law in `docs/PROMPT_ENGINEERING.md` — rather
 * than advertise tools the gate can never run. A tool missing from the list
 * is policy, not a bug.
 *
 * FENCED `<system-reminder>`, the harness's own reminder voice — the tag is
 * already on {@see \SugarCraft\Code\Context\PromptFence}'s roster, so a
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
     * ({@see \SugarCraft\Code\Tools\BuiltIn\PlanExitTool}), and the
     * `Alt+M` exit is the TUI's ({@see \SugarCraft\Code\Chat}).
     */
    private const BODY = <<<'MARKDOWN'
        <system-reminder>
        # Plan mode

        The session is in plan mode: explore, analyse and write a plan; change nothing else until the user approves it.

        - Reads run: Read, Grep, Glob and the other read-only tools, and a shell command made only of known read-only commands (`git log`, `git diff`, `grep`, `ls`, `cat` …) with no output redirection or substitution.
        - Every other change is refused by the permission gate, not merely discouraged: editing or creating files, `rm`/`mv`/`cp`, `sed -i`, redirecting output into a file, package installs, and git commands that change the working tree. A refused call returns an error and changes nothing. The tool list is not the same in every mode: plan mode drops the tools its gate always refuses, keeping this mode's own `Write`, `Bash` and `PlanExit` even where a rule refuses them; a tool being offered still does not mean it may run — the gate decides each call.
        - The one write allowed is the plan itself: a Markdown file directly in `.candy-code/plans/` (for example `.candy-code/plans/add-retry-backoff.md`), with the Write or Edit tool.
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
