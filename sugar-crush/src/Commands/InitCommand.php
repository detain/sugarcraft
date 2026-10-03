<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * The `/init` command (roadmap 5.14e): ask the agent to study the project and
 * write — or improve — its `AGENTS.md`.
 *
 * A CANNED PROMPT, NOT A GENERATOR. The useful content of an instruction file
 * is exactly the part a static scan cannot produce: which commands actually
 * run the tests, what the architecture is FOR, the conventions a reviewer
 * enforces. The agent already has the tools to find those out, so the command
 * is a prompt, dispatched as an ordinary turn through
 * {@see \SugarCraft\Crush\Chat::dispatchCommand()} — the same road a
 * file-based command's expansion takes, so the spend cap, the compaction tiers
 * and the UserPromptSubmit hook judge it exactly as they judge typed prose, and
 * the Write it ends in is gated by the session's permission mode like any other.
 *
 * AGENTS.md, NOT CLAUDE.md: it is the cross-agent spelling
 * {@see \SugarCraft\Crush\Context\InstructionFileLoader} reads after CLAUDE.md,
 * and the one other agents read too. An existing CLAUDE.md, AGENTS.md or alias
 * is read and improved rather than duplicated.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush has
 * no `/init`; the idea is Claude Code's and opencode's.
 */
final class InitCommand
{
    /**
     * The prompt `/init` sends. `%s` is the focus paragraph — empty for a bare
     * `/init`. Kept free of `@` so the mention scanner, which reads what the
     * user typed, finds nothing to attach in it.
     */
    public const PROMPT = <<<'PROMPT'
        Please analyze this project and write an AGENTS.md file at the repository root. It will be loaded into the context of every future coding-agent session in this repository, so it should hold what an agent cannot cheaply rediscover on its own.

        Start by reading what is already there: an existing AGENTS.md, CLAUDE.md, GEMINI.md, .cursorrules, .clinerules, .github/copilot-instructions.md, the README, and the build or package manifests. If an AGENTS.md exists, improve it in place instead of starting over, and keep anything still accurate.

        Cover, concisely:
        1. The commands that build, lint and test the project, including how to run a single test.
        2. The high-level architecture: the big pieces, how they fit together, and where to look for what. Prefer what takes reading several files to understand over a listing of every directory.
        3. The conventions a reviewer would enforce: naming, error handling, testing expectations, and anything unusual about this codebase.

        Leave out generic advice that applies to every project, file-by-file inventories, and anything you did not verify in the repository. Do not invent commands or conventions. Keep it short enough to read in one sitting.
        %s
        PROMPT;

    /** The paragraph `/init <focus>` appends; `%s` is the focus text. */
    public const FOCUS_FORMAT = "\nThe user asked you to pay particular attention to: %s";

    private function __construct()
    {
    }

    /**
     * The prompt for `/init`, with $focus (the command's argument, if any)
     * appended as an extra instruction.
     */
    public static function prompt(string $focus = ''): string
    {
        $focus = trim($focus);

        return rtrim(sprintf(self::PROMPT, $focus === '' ? '' : sprintf(self::FOCUS_FORMAT, $focus)));
    }
}
