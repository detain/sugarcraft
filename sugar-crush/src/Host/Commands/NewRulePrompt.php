<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Core\Util\Sanitize;

/**
 * The `/newrule` command (roadmap 5.14d): ask the agent to distil what this
 * conversation established into a NEW project rule under
 * `<root>/.sugar-crush/rules/`, the project tier
 * {@see \SugarCraft\Crush\Context\RuleLoader} loads into every later session.
 *
 * A CANNED PROMPT, LIKE `/init`. The agent is the one that has read the
 * conversation, so it drafts the rule; the command is a prompt dispatched as an
 * ordinary turn, and the spend cap, the compaction tiers and the
 * UserPromptSubmit hook judge it as they judge typed prose.
 *
 * THE WRITE IS THE POLICY SURFACE. `.sugar-crush/rules` is prompt text the next
 * session treats as the operator's own instructions, so a write there is asked
 * about by {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook::POLICY_ASK_PATTERNS}
 * in every permission mode — `bypass-permissions` included — and the "always"
 * answer cannot remember it (step 0.8b). The user approves the exact file the
 * model drafted, every time; a headless run with nobody to ask refuses it. The
 * prompt says so, so a declined write is not retried through another path.
 *
 * Cline's `/newrule` and Kilo's `new_rule` are the model: a `## Brief overview`
 * plus only the sections the conversation supports, no invented preferences,
 * no recap of the conversation, and no overwriting an existing rule file.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush has
 * no `/newrule`.
 */
final class NewRulePrompt
{
    /** Where the rule goes, relative to the project root: the project rules tier. */
    public const DIRECTORY = '.sugar-crush/rules';

    /** The sections a rule may carry after its overview, in order. */
    public const SECTIONS = [
        'Communication style',
        'Development workflow',
        'Coding best practices',
        'Project context',
        'Other guidelines',
    ];

    /** How many existing rule names the prompt lists before it stops counting them out. */
    public const MAX_LISTED = 40;

    /**
     * The prompt. `%1$s` is the directory, `%2$s` the section list, `%3$s`
     * the existing-files sentence and `%4$s` the focus paragraph (empty for a
     * bare `/newrule`). Kept free of `@` so the mention scanner finds nothing
     * to attach in it.
     */
    public const PROMPT = <<<'PROMPT'
        Please turn what this conversation has established into a project rule for future coding-agent sessions in this repository.

        Write ONE new markdown file in %1$s/ at the project root, named with a short kebab-case name for what it covers (for example %1$s/testing-conventions.md). Every later session in this repository loads it into its system prompt, so it holds standing guidance, not a record of this conversation.

        Start the file with frontmatter: a line ---, then `name: <the file name without .md>`, then `description: <one line saying what the rule covers>`, then a line ---. After it write a `## Brief overview` paragraph, followed by only those of these sections the conversation actually supports: %2$s. Use short bullet points.

        Rules:
        - Record only preferences, corrections and conventions the user stated or clearly confirmed in this conversation. Do not invent preferences, and do not recap the conversation.
        - Never overwrite or edit an existing rule file. %3$s If the name you want is taken, choose another.
        - Write it with the Write tool. This directory is part of the session's policy surface, so the write is put to the user for approval every time; that is expected. If the user declines it, do not try again through another tool or path: say what you would have written.
        - If the conversation holds nothing worth keeping as a standing rule, say so and write nothing.
        %4$s
        PROMPT;

    /** The paragraph `/newrule <focus>` appends; `%s` is the focus text. */
    public const FOCUS_FORMAT = "\nThe user asked the rule to cover: %s";

    private function __construct()
    {
    }

    /**
     * The prompt for `/newrule`, naming the rule files $existing already
     * holds and appending $focus (the command's argument, if any).
     *
     * @param list<string> $existing file names already in {@see DIRECTORY}
     */
    public static function prompt(string $focus = '', array $existing = []): string
    {
        $focus = trim($focus);
        $sections = implode(', ', array_map(static fn (string $s): string => '`## ' . $s . '`', self::SECTIONS));

        return rtrim(sprintf(
            self::PROMPT,
            self::DIRECTORY,
            $sections,
            self::existingSentence($existing),
            $focus === '' ? '' : sprintf(self::FOCUS_FORMAT, $focus),
        ));
    }

    /**
     * The `*.md` file names already in `<$root>/.sugar-crush/rules/`, sorted —
     * [] when the directory does not exist. Read so the model knows which names
     * are taken without spending a tool call on it.
     *
     * @return list<string>
     */
    public static function existingRules(string $root): array
    {
        if ($root === '') {
            return [];
        }

        $files = glob(rtrim($root, '/') . '/' . self::DIRECTORY . '/*.md') ?: [];
        $names = array_values(array_filter(
            array_map(static fn (string $path): string => basename($path), $files),
            static fn (string $name): bool => $name === Sanitize::untrusted($name) && !str_contains($name, "\n"),
        ));
        sort($names);

        return $names;
    }

    /** @param list<string> $existing */
    private static function existingSentence(array $existing): string
    {
        if ($existing === []) {
            return 'There are no rule files in ' . self::DIRECTORY . '/ yet.';
        }

        $listed = \array_slice($existing, 0, self::MAX_LISTED);
        $more = \count($existing) - \count($listed);

        return 'The rule files already there are: ' . implode(', ', $listed)
            . ($more > 0 ? " and {$more} more (list the directory before choosing a name)" : '') . '.';
    }
}
