<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP\Serve;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Tool;

/**
 * The read-only tool set `mcp serve` exposes to an external MCP client.
 *
 * C-MCP-5.a (plan_comparison §6 ruling, CONFIRMED wording): the serve verb is
 * STDIO-ONLY with a READ-ONLY roster. This class is where "read-only" is
 * written down exactly once: the five wire names below are the whole surface,
 * and each one must still classify as {@see ToolPermissionClass::Read} in the
 * catalog for the build to stand — a future reclassification of one of them
 * (say, a `Grep` that starts spawning something the gate cannot judge) turns
 * a serve launch into a loud refusal rather than a silent widening of what an
 * external client may run.
 *
 * The roster is built through {@see ToolCatalog::build()} with the same
 * context a launch would use, so path containment (PathJail), the shared
 * read ledger, and the instruction/skill nudge pairing behave exactly as they
 * do for the model's own calls — there is no second implementation of "what
 * Read means here" to drift.
 *
 * Deliberately NOT built here: the LSP client (spawning servers is beyond the
 * read-only contract), the memory writer, background shells, the MCP bridge
 * shape and the advisor backend — none of the five listed tools consults
 * them, and passing null keeps the read-only claim structural instead of a
 * comment. `rg`/`fd` capability probes ride the existing
 * {@see Bootstrap::capabilityPresent()} memo, so this file adds no new
 * child-spawn site for the lifetime census.
 */
final class McpServeRoster
{
    /**
     * The exact wire names `mcp serve` lists and answers, in list order.
     *
     * @var list<string>
     */
    public const NAMES = ['Read', 'Glob', 'Grep', 'Ls', 'RepoMap'];

    private function __construct()
    {
    }

    /**
     * Build the roster for one root.
     *
     * The optional seams exist so a test can pin the roster over a temp tree
     * without inheriting this process's HOME-derived state; production passes
     * nothing but the root and gets the same loader/skill-nudge wiring a
     * launch would (minus every write-capable collaborator, which is the
     * point).
     *
     * @param InstructionFileLoader|null $loader    null builds a plain jail-rooted loader for $root.
     * @param SkillRegistry|null         $skills    null uses an EMPTY registry — serve never injects skills.
     * @param bool|null                  $rgAvailable null probes with the existing launch helper.
     * @param bool|null                  $fdAvailable null probes with the existing launch helper.
     *
     * @return list<Tool> one entry per name in {@see NAMES}, in that order.
     *
     * @throws \RuntimeException when a required name is not in the catalog build, or is
     *         no longer a Read-class tool. Fail-loud by design: half a read-only
     *         roster is still a roster an external client will trust.
     */
    public static function build(
        string $root,
        ?InstructionFileLoader $loader = null,
        ?SkillRegistry $skills = null,
        ?bool $rgAvailable = null,
        ?bool $fdAvailable = null,
    ): array {
        $loader ??= new InstructionFileLoader($root);
        $skills ??= new SkillRegistry();

        $context = new ToolBuildContext(
            root: $root,
            loader: $loader,
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => (new RuleLoader($root))->load()),
            rgAvailable: $rgAvailable ?? Bootstrap::capabilityPresent('rg'),
            fdAvailable: $fdAvailable ?? Bootstrap::capabilityPresent('fd'),
        );

        $byName = [];
        foreach (ToolCatalog::build($context) as $tool) {
            $byName[$tool->name()] = $tool;
        }

        $roster = [];
        foreach (self::NAMES as $name) {
            $class = ToolCatalog::permissionOf($name);
            if ($class !== ToolPermissionClass::Read) {
                throw new \RuntimeException(
                    'mcp serve roster: ' . $name . ' is no longer a read-only catalog tool'
                    . ' (it classifies as ' . ($class?->value ?? 'unknown') . '); refusing to serve it.',
                );
            }
            $tool = $byName[$name] ?? null;
            if (!$tool instanceof Tool) {
                throw new \RuntimeException('mcp serve roster: ' . $name . ' is not in the catalog build.');
            }
            $roster[] = $tool;
        }

        return $roster;
    }
}
