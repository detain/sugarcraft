<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Support\FrontmatterKeyAudit;

/**
 * The `/agents new` command (C-EXT-3.d): ask the agent to draft a NEW preset
 * for this conversation as `.candy-code/agents-proposed/<name>.md` at the
 * project root — a DRAFT tier the loader never reads.
 *
 * WHY A DRAFT TIER AT ALL. The final directory is a policy deny the agent can
 * never cross: {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook::WRITE_ONLY_PATTERNS}
 * refuses writes under `.candy-code/agents/` outright, in every permission
 * mode, because a preset's `permissionMode:` is a self-grant the human cannot
 * see in a diff. A draft under `.candy-code/agents-proposed/` is an ordinary
 * gated write; the consent is the SEPARATE, human-typed `/agents accept <name>`
 * step ({@see AgentsHostCommand}), which re-reads the file, validates its
 * frontmatter against {@see FrontmatterKeyAudit::KNOWN}[AGENT], prints the
 * policy keys verbatim, and only then moves it into the loaded tier. The
 * model never grants itself anything: a draft it writes inertly changes no
 * session until a person runs the accept command with the name in front of
 * them.
 *
 * A CANNED PROMPT, LIKE `/newrule`. The agent has read the conversation, so it
 * drafts the preset; the command is dispatched as an ordinary turn and the
 * spend cap, the compaction tiers and the UserPromptSubmit hook judge it as
 * they judge typed prose. TUI users see it through `Chat::handleAgentsCommand()`
 * (the `withInputBuf(...)->submit()` idiom); served sessions get a
 * {@see CommandEffect::submitPrompt()}.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush has
 * no `/agents new`.
 */
final class NewAgentPrompt
{
    /** Where a draft goes, relative to the project root: the tier NO loader reads. */
    public const DIRECTORY = '.candy-code/agents-proposed';

    /** Where a promoted preset lands — the directory the agent's own writes cannot reach. */
    public const TARGET = '.candy-code/agents';

    /**
     * The keys that decide what a preset is allowed to do. The accept step
     * prints each one VERBATIM before the move, so the human consents to the
     * policy the file carries and not to a prose summary of it.
     */
    public const POLICY_KEYS = ['permissionMode', 'tools', 'mcpServers'];

    /**
     * Modes wider than the plain starting point — a preset declaring one is
     * still acceptable (the accept step is the consent) but shown loudly,
     * because the draft itself cannot show what it would grant.
     */
    public const SELF_GRANTING_MODES = [
        PermissionMode::AcceptEdits->value,
        PermissionMode::Auto->value,
        PermissionMode::DontAsk->value,
        PermissionMode::BypassPermissions->value,
    ];

    /** How many existing preset and draft names the prompt lists before it stops counting them out. */
    public const MAX_LISTED = 40;

    /**
     * The prompt. `%1$s` is the draft directory, `%2$s` the key list, `%3$s`
     * the existing-names sentence and `%4$s` the focus paragraph (empty for a
     * bare `/agents new`). Kept free of `@` so the mention scanner finds
     * nothing to attach in it.
     */
    public const PROMPT = <<<'PROMPT'
        Please turn what this conversation has established into a proposed agent preset for this repository.

        Write ONE new markdown file in %1$s/ at the project root, named with a short lowercase kebab-case name for the kind of agent it describes (for example %1$s/reviewer.md), matching the grammar a-z 0-9 and hyphens. The file is a DRAFT: no loader reads that directory, and it changes no session until the user reviews it and runs /agents accept with the file name.

        Start the file with frontmatter: a line ---, then the keys, then a line ---. You may declare ONLY these keys: %2$s. Three of them are policy, and the accept step prints each one verbatim to the human before it promotes the file: `permissionMode` (use `default` unless the user explicitly asked for a wider mode - `bypass-permissions` and `dont-ask` are self-grants and will be shown loudly), `tools` (a list of tool names the agent may use; leave the key out to inherit the default roster), and `mcpServers`. After the frontmatter write the preset's system prompt: the role, when a delegation should reach for it, and its working rules as short bullet points.

        Rules:
        - Record only what the user stated or clearly confirmed in this conversation. Do not invent preferences, and do not recap the conversation.
        - Never overwrite an existing file. %3$s If the name you want is taken, choose another.
        - Write it with the Write tool into the draft directory ONLY. Writing into the loaded preset directory is denied to you in every permission mode and will not succeed by trying another tool or path. If the user declines the draft write, say what you would have written.
        - If the conversation holds nothing worth keeping as a preset, say so and write nothing.
        %4$s
        PROMPT;

    /** The paragraph `/agents new <focus>` appends; `%s` is the focus text. */
    public const FOCUS_FORMAT = "\nThe user asked this preset to cover: %s";

    private function __construct()
    {
    }

    /**
     * The prompt for `/agents new`, naming the presets and drafts $existing
     * already holds and appending $focus (the words after the verb, if any).
     *
     * @param list<string> $existing file names already in either tier
     */
    public static function prompt(string $focus = '', array $existing = []): string
    {
        $focus = trim($focus);

        return rtrim(sprintf(
            self::PROMPT,
            self::DIRECTORY,
            implode(', ', FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::AGENT]),
            self::existingSentence($existing),
            $focus === '' ? '' : sprintf(self::FOCUS_FORMAT, $focus),
        ));
    }

    /**
     * The `*.md` file names already in `<$root>/.candy-code/agents/` and in
     * `<$root>/.candy-code/agents-proposed/`, merged and sorted — [] when
     * neither directory exists. Read so the model knows which names are taken
     * (a draft OR a live preset) without spending a tool call on it.
     *
     * @return list<string>
     */
    public static function existingPresets(string $root): array
    {
        $names = array_merge(self::namesIn($root, self::TARGET), self::namesIn($root, self::DIRECTORY));
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function namesIn(string $root, string $directory): array
    {
        if ($root === '') {
            return [];
        }

        $files = glob(rtrim($root, '/') . '/' . $directory . '/*.md') ?: [];

        return array_values(array_filter(
            array_map(static fn (string $path): string => basename($path), $files),
            static fn (string $name): bool => $name === Sanitize::untrusted($name) && !str_contains($name, "\n"),
        ));
    }

    /** @param list<string> $existing */
    private static function existingSentence(array $existing): string
    {
        if ($existing === []) {
            return 'There are no preset files in ' . self::TARGET . '/ or drafts in ' . self::DIRECTORY . '/ yet.';
        }

        $listed = \array_slice($existing, 0, self::MAX_LISTED);
        $more = \count($existing) - \count($listed);

        return 'The names already taken by a preset or a draft are: ' . implode(', ', $listed)
            . ($more > 0 ? " and {$more} more (list both directories before choosing a name)" : '') . '.';
    }
}
