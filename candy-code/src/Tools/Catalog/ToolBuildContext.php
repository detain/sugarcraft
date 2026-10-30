<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Catalog;

use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\LSP\LspClient;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\ReadLedger;

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
 * ({@see \SugarCraft\Code\Cli\Bootstrap::unfilteredTools()}) owns the
 * ledger it hands out and nothing else can mint a second one for the same
 * build. Defaulted, so a context built without one (a test, an embedder)
 * still gives its tools one shared ledger.
 *
 * `backgroundShells` is the session's {@see \SugarCraft\Code\Background\BackgroundShellRegistry}
 * (SHL-1): Bash's `run_in_background` spawns into it, ShellOutput tails it,
 * and ShellStop kills through it — three tools, one table, for the same
 * one-instance reason as the ledger. NULL (the default, for a context built
 * without one) is a refusal to background rather than a silent home-tree
 * write: Bash answers that no registry is attached, and the read/stop tools
 * say the same. The launch path always passes the per-root table from
 * {@see \SugarCraft\Code\Cli\Bootstrap::backgroundShells()}.
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
        public ?\SugarCraft\Code\Background\BackgroundShellRegistry $backgroundShells = null,
        /**
         * This launch's frozen MCP bridge/dispatch split (MCP-7), null when no
         * client exists. Carried as data so the pair can be conditionally
         * BUILT by the catalog like every other tool, instead of being
         * appended to the finished list by hand.
         */
        public ?\SugarCraft\Code\MCP\McpToolShape $mcpShape = null,
        /**
         * C-CTX-4.f: the user's `references` — extra directories the READ
         * tools (Read, Grep, Glob, Ls) may walk beside the session root.
         * A list of canonical absolute directory paths, already validated
         * (exists, is a directory, not inside the session root) by
         * {@see \SugarCraft\Code\Cli\LaunchContext::referenceReadRoots()};
         * refusals became launch notices there, so a tool only ever sees
         * usable roots. Empty by default, which is every pre-C-CTX-4.f
         * build and every single-root jail. Write, Edit, Bash and the
         * worktree jail never consult this field — it widens reads only.
         *
         * @var list<string>
         */
        public array $readRoots = [],
        /**
         * C-PRV-3.d: this launch's advisor backend — the tool-less engine on
         * the `advisorModel` / `$CANDY_CODE_ADVISOR_MODEL` model — or null
         * when the user named no advisor model. Null is the COMMON case and
         * it is load-bearing: unlike the titler's fallback ladder, an unset
         * advisor model means the Advisor tool is not offered at all, so the
         * `Advisor` catalog entry builds from nothing here
         * ({@see \SugarCraft\Code\Tools\Catalog\ToolCatalog::CONDITIONAL_BUILDS}).
         */
        public ?\SugarCraft\Code\Backend $advisorBackend = null,
        /**
         * C-PRV-3.d: loads the session's persisted transcript for the advisor
         * digest — `fn(string $sessionId): array<int, \SugarCraft\Code\Message>`
         * — wired through the same door the auto-approval reviewer reads
         * ({@see \SugarCraft\Code\Cli\Bootstrap::sessionStore()} with pruning
         * off). Null leaves the advisor answering from the question alone; the
         * envelope already says "(empty)" rather than lying about a digest.
         */
        public ?\Closure $advisorTranscript = null,
        /**
         * C-PRV-3.d: the session's durable spend totals —
         * `fn(string $sessionId): ?array` in
         * {@see \SugarCraft\Code\Host\EnhancedSessionStore::usageTotals()}'s
         * shape — the lower-bound source the Advisor checks its call against
         * before dialing. Null skips the durable check; the per-turn counter
         * still stands.
         */
        public ?\Closure $advisorSpend = null,
    ) {
    }
}
