<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Level-2 on-demand skill loader, exposed to the model as an ordinary tool.
 *
 * Mirrors Claude Code's own Skill invocation model (crush_feat.md section
 * 7.E2): only Level-1 metadata (name + description, via SkillMatcher) is
 * folded into the system prompt at session start. The full SKILL.md body
 * — which can run to thousands of tokens per skill — is deliberately NOT
 * loaded until the model actually decides a skill is relevant and calls
 * this tool. That keeps a 50+-skill roster cheap for every turn that never
 * needs one, instead of paying the full I/O + parse cost for every skill
 * on every session start (the defect the progressive-loading design in
 * SkillLoader exists to avoid).
 *
 * Live since W3.S8: {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} appends
 * this tool to every engine tool list, over the same SkillRegistry it hands
 * EngineBackend, so a real bin/sugarcrush session can invoke it.
 */
final readonly class SkillTool implements Tool
{
    /**
     * The one placeholder a skill body may name, spelled as Claude Code's
     * skills spell it and as {@see \SugarCraft\Crush\Commands\CommandSpec}
     * spells it for file-based slash commands.
     */
    public const ARGUMENTS_PLACEHOLDER = '$ARGUMENTS';

    public function __construct(
        private SkillRegistry $registry,
        private ?SkillLoader $loader = null,
    ) {
    }

    public function name(): string
    {
        return 'Skill';
    }

    public function description(): string
    {
        return 'Load the full SKILL.md instructions for one skill whose name you already '
            . 'have. Skill names and their one-line descriptions are the entries in the '
            . '"Available skills" section of the system prompt; this tool looks up exactly '
            . 'one of them by name and does not itself list, search, or run skills, so do '
            . 'not call it to discover what exists or for a task no listed skill covers. '
            . 'The result is the instruction body of that skill prefixed with a '
            . '"## Skill: name" marker, or an error when the name is empty, is not '
            . 'model-invocable, or its file cannot be read. Pass `args` to hand the skill '
            . 'its input: each $ARGUMENTS in the body is replaced by it, and a body that '
            . 'names no $ARGUMENTS gets it appended on a final "ARGUMENTS:" line. Do not '
            . 'call this tool again for a skill whose body is already in the conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Skill name to invoke'],
                'args' => [
                    'type' => 'string',
                    'description' => 'Optional input for the skill. Replaces each $ARGUMENTS in its '
                        . 'body; appended as a final "ARGUMENTS: ..." line when the body has none.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $name = $args['name'] ?? '';
        $skillArgs = $args['args'] ?? '';

        // Untyped tool-call JSON can carry a number or an array for either
        // field. Under strict_types the registry lookup and the substitution
        // below would throw a TypeError out of execute() — a crash the model
        // never sees — so a malformed call is answered as one, the way Read
        // and Grep answer theirs. `args` is not coerced: a JSON array has no
        // single string a skill body could have meant.
        foreach (['name' => $name, 'args' => $skillArgs] as $field => $value) {
            if (!is_string($value)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: {$field} must be a string",
                    isError: true,
                );
            }
        }

        if ($name === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: name cannot be empty',
                isError: true,
            );
        }

        $skill = $this->registry->get($name);

        // isAutoInvocable() re-checked here (not just registry->get()'s own
        // disabled-skill filtering) so a skill marked
        // disable-model-invocation:true stays unreachable through this tool
        // even if some other caller adds it to the registry directly —
        // matches SkillRegistry::findForPrompt()'s own rationale for
        // routing through isAutoInvocable() rather than re-inlining the check.
        if ($skill === null || !$this->registry->isAutoInvocable($name)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Skill not found or not model-invocable: {$name}",
                isError: true,
            );
        }

        $loader = $this->loader ?? new SkillLoader();

        // Level 2: load the body only now, on invocation — not at startup.
        try {
            $body = $loader->loadSkillBody($skill->sourcePath);
        } catch (\RuntimeException $e) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error loading skill body: {$e->getMessage()}",
                isError: true,
            );
        }

        $body = self::substituteArguments($body, $skillArgs);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: "## Skill: {$skill->name}\n\n{$body}",
            isError: false,
        );
    }

    /**
     * Fill the body's `$ARGUMENTS` with the model's `args` (audit F-H4: the
     * schema advertised `args` and execute() dropped it, so a skill written
     * around its input ran without it and the model was never told).
     *
     * Mirrors Claude Code's skill invocation: every `$ARGUMENTS` becomes the
     * argument string; a body that names none gets `ARGUMENTS: <args>`
     * appended; no arguments leaves a placeholder-free body byte-identical and
     * empties the placeholders in one that has them — the same "a missing
     * argument expands to nothing" rule
     * {@see \SugarCraft\Crush\Commands\CommandSpec::expandTemplate()} applies,
     * for the same reason: a leftover `$ARGUMENTS` reaching the model reads as
     * a token it is expected to know.
     *
     * TWO DELIBERATE DEPARTURES FROM THE SLASH-COMMAND TEMPLATE, both because
     * a skill body is not a template written for this substitution:
     *
     *  - Appending when there is no placeholder. A slash command's author
     *    chose its template's shape and the arguments come from the operator,
     *    so CommandSpec refuses to tack them on after the author's closing
     *    instruction. Here the arguments come from the MODEL, which passed them
     *    because it meant the skill to act on them; dropping them is the
     *    silent loss being fixed, and a labelled trailing line cannot be
     *    mistaken for the author's prose.
     *  - Only `$ARGUMENTS`. Skill bodies routinely carry shell — `awk '{print
     *    $1}'`, `echo $$` — so `$1`..`$9` and the `$$` escape would rewrite
     *    working instructions. CommandSpec's expander also resolves `` !`…` ``
     *    and `@path`, which a skill body must reach the model as written, so
     *    it is not reused here.
     *
     * str_replace() is a single left-to-right pass that never rescans what it
     * inserted, so an `args` that itself contains `$ARGUMENTS` is not expanded
     * again. Surrounding whitespace is trimmed, matching what
     * {@see \SugarCraft\Crush\Chat::expandCustomCommand()} hands
     * CommandSpec, and an all-whitespace `args` counts as none.
     */
    public static function substituteArguments(string $body, string $arguments): string
    {
        $arguments = trim($arguments);

        if (str_contains($body, self::ARGUMENTS_PLACEHOLDER)) {
            return str_replace(self::ARGUMENTS_PLACEHOLDER, $arguments, $body);
        }

        if ($arguments === '') {
            return $body;
        }

        return $body . "\n\nARGUMENTS: {$arguments}";
    }
}
