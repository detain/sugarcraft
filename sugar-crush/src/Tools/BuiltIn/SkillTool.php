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
 * NOTE: like SkillMatcher, this tool is not yet wired into
 * Bootstrap::tools()/EngineBackend — that requires the Skills subsystem to
 * actually be populated in production (crush_feat.md section 7.E1, tracked
 * as its own step). Until that wiring lands, this class is reachable only
 * by direct construction (as the tests below do), not from bin/sugarcrush.
 */
final readonly class SkillTool implements Tool
{
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
        return 'Invoke a named skill by loading its full instructions on-demand';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Skill name to invoke'],
                'args' => ['type' => 'string', 'description' => 'Optional arguments'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $name = $args['name'] ?? '';

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

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: "## Skill: {$skill->name}\n\n{$body}",
            isError: false,
        );
    }
}
