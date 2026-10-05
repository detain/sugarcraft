<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\ReadLedger;

/**
 * Everything one launch shares between its built-in tools, handed to each
 * {@see BuildsFromCatalog::fromCatalog()}.
 *
 * The path-resolving tools (Read, Edit, Glob, Grep, Write) take the SAME
 * loader and nudge trackers: the "already announced" sets are per instance,
 * so one tool with its own copy would re-announce a CLAUDE.md or a scoped
 * skill another tool had already shown the model.
 *
 * `memory` is the launch's {@see MemoryWriter}, the router `/memory add` uses
 * too; null leaves the `Memory` tool answering that no store is configured.
 *
 * `readLedger` is the session's {@see ReadLedger} (roadmap 3.I-2), shared by
 * Read, Edit, Write and ApplyPatch for the same reason the loader is: a read
 * through one must be visible to the others. It is a field rather than a
 * lookup keyed weakly on this object, so the launch that builds the context
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::unfilteredTools()}) owns the
 * ledger it hands out and nothing else can mint a second one for the same
 * build. Defaulted, so a context built without one (a test, an embedder)
 * still gives its tools one shared ledger.
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
        public ?MemoryWriter $memory = null,
        public ReadLedger $readLedger = new ReadLedger(),
    ) {
    }
}
