<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Everything one launch shares between its built-in tools, handed to each
 * {@see BuildsFromCatalog::fromCatalog()}.
 *
 * The path-resolving tools (Read, Edit, Glob, Grep, Write) take the SAME
 * loader and nudge trackers: the "already announced" sets are per instance,
 * so one tool with its own copy would re-announce a CLAUDE.md or a scoped
 * skill another tool had already shown the model.
 */
final readonly class ToolBuildContext
{
    public function __construct(
        public string $root,
        public InstructionFileLoader $loader,
        public SkillRegistry $skills,
        public SkillPathNudge $skillNudge,
        public RulePathNudge $ruleNudge,
        public ?LspClient $lsp = null,
        public bool $rgAvailable = false,
        public bool $fdAvailable = false,
    ) {
    }
}
