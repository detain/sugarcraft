<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-3.a: `/skills reload` — the live registry is swapped in place by a
 * fresh discovery walk, the operator's disabled veto rides across untouched,
 * the reply counts the swap, and a session that launched without a registry
 * refuses instead of swapping a private copy nobody reads.
 *
 * Follows the harness idiom of {@see SkillsEnableDisableTest}: same sandbox
 * shape, same reply-row extraction. Skills are discovered FROM DISK here —
 * the whole point of the command is re-running discovery — so the fixtures
 * are SKILL.md manifests written and removed between calls, and the live
 * registry starts from a hand-registered skill so the swap's counts have a
 * before-state that is NOT just the disk.
 *
 * The counts are DERIVED, not typed: {@see freshRegistry()} runs the same
 * walk into a throwaway registry and the expected numbers come out of the
 * two name sets. Discovery ships a built-in tier the sandbox cannot switch
 * off, so a hardcoded '1 live' would be asserting today's bundle census —
 * what the swap must equal is the walk's own answer, recomputed.
 */
final class SkillsReloadTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-skills-reload-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0o700, true);
        mkdir($this->home . '/.candy-code', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
    }

    public function testReloadRefusesWhenTheSessionHasNoLiveRegistry(): void
    {
        $context = CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project),
        );

        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;
        $last = $rows[\count($rows) - 1];

        $this->assertSame(Role::System, $last->role);
        $this->assertStringContainsString('no live registry', $last->content);

        // Refusal is stable — a second ask must not accidentally succeed.
        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;
        $this->assertStringContainsString('no live registry', $rows[\count($rows) - 1]->content);
    }

    public function testReloadSwapsTheDiscoveredRosterInPlaceAndCountsTheMove(): void
    {
        // The live registry holds ONE hand-registered skill the disk has no
        // manifest for; the disk holds one manifest the walk will find and
        // the live set never carried.
        $registry = new SkillRegistry();
        $registry->register(['ghost' => self::manifestSkill('ghost', 'Registered by hand, never written to disk.')]);
        $this->writeManifest('diskfresh', 'Found by the reload walk.');
        $context = $this->reloadContext($registry);

        $fresh = $this->freshRegistry();
        $expectedAdded = \count(\array_diff($fresh->names(), ['ghost']));
        $expectedTotal = \count($fresh->all());

        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;
        $last = $rows[\count($rows) - 1];

        $this->assertFalse($result->isRefused(), 'the swap succeeded');
        $this->assertSame(Role::Assistant, $last->role);
        $this->assertStringContainsString("Reloaded the skill registry: {$expectedAdded} added, 1 removed, {$expectedTotal} live", $last->content, 'the reply carries the counts of the move');
        $this->assertStringContainsString('turn-context tail', $last->content, 'and tells the operator where the model learns of it');
        $this->assertNull($registry->get('ghost'), 'the ghost that was never on disk is gone');
        $this->assertNotNull($registry->get('diskfresh'), 'the new discovery is live');
        $this->assertEqualsCanonicalizing($fresh->names(), $registry->names(), 'the live set is exactly the walk\'s set');
    }

    /**
     * The whole point of swapping IN PLACE: the identity the workspace (and
     * through it the engine, the Apps and every forked child) already holds
     * is what the swap mutates. A reload that returned a NEW registry would
     * leave every holder reading the old one.
     */
    public function testReloadMutatesTheSharedRegistryAndNothingIsReThreaded(): void
    {
        $registry = new SkillRegistry();
        $this->writeManifest('alpha', 'Alpha walk.');
        $context = $this->reloadContext($registry);
        $before = $this->freshRegistry();
        (new SkillsHostCommand())->run($context, '/skills reload');
        $this->assertEqualsCanonicalizing($before->names(), $registry->names());

        // A SECOND manifest appears on disk; one more reload, same object.
        $this->writeManifest('beta', 'Beta walk.');
        $total = \count($this->freshRegistry()->all());
        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;

        $this->assertStringContainsString("1 added, 0 removed, {$total} live", $rows[\count($rows) - 1]->content);
        $this->assertNotNull($registry->get('beta'), 'the SAME object every holder already reads gained the skill');

        // And discovery can take a skill away too.
        @exec('rm -rf ' . escapeshellarg($this->project . '/.candy-code/skills/beta') . ' 2>&1');
        $total = \count($this->freshRegistry()->all());
        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;

        $this->assertStringContainsString("0 added, 1 removed, {$total} live", $rows[\count($rows) - 1]->content);
        $this->assertNull($registry->get('beta'));
    }

    /** The operator's disabled set is NOT a discovery fact: the veto rides across. */
    public function testDisabledSkillsStayDisabledAcrossReload(): void
    {
        $registry = new SkillRegistry();
        $this->writeManifest('alpha', 'Alpha walk.');
        $context = $this->reloadContext($registry);
        (new SkillsHostCommand())->run($context, '/skills reload');
        $registry->disable('alpha');
        $this->assertNull($registry->get('alpha'));

        // The vetoed skill is still DISCOVERED (the walk finds the manifest
        // again) and still dark: 0/0 counts, total down by the one veto.
        $undisclosedTotal = \count($this->freshRegistry()->all()) - 1;
        $result = (new SkillsHostCommand())->run($context, '/skills reload');
        $rows = $result->rows;

        $this->assertStringContainsString("0 added, 0 removed, {$undisclosedTotal} live", $rows[\count($rows) - 1]->content, 'still discovered, still vetoed');
        $this->assertTrue($registry->isDisabled('alpha'));
        $this->assertNull($registry->get('alpha'));

        // Lifting the veto needs no reload — and the next reload keeps it live.
        $registry->enable('alpha');
        $this->assertNotNull($registry->get('alpha'));
        (new SkillsHostCommand())->run($context, '/skills reload');
        $this->assertFalse($registry->isDisabled('alpha'), 'a reload that RE-ADDED the veto would be a silent operator edit');
        $this->assertNotNull($registry->get('alpha'));
    }

    public function testReloadWithAnArgumentIsToldTheUsage(): void
    {
        $registry = new SkillRegistry();
        $context = $this->reloadContext($registry);

        $result = (new SkillsHostCommand())->run($context, '/skills reload now');
        $rows = $result->rows;
        $last = $rows[\count($rows) - 1];

        $this->assertSame(Role::System, $last->role);
        $this->assertStringContainsString('/skills reload', $last->content, 'the usage line names the verb, argument shapes and all');
        $this->assertSame([], $registry->names(), 'and nothing was swapped');
    }

    private function reloadContext(SkillRegistry $registry): CommandContext
    {
        return CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project, skills: $registry),
        );
    }

    /** The same discovery walk the command runs, into a throwaway registry. */
    private function freshRegistry(): SkillRegistry
    {
        $fresh = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $fresh))->loadAll(
            $this->project,
            Bootstrap::bundles($this->project),
        );

        return $fresh;
    }

    private function writeManifest(string $name, string $description): void
    {
        $dir = $this->project . '/.candy-code/skills/' . $name;
        mkdir($dir, 0o700, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\nname: {$name}\ndescription: {$description}\n---\n\nWalk body for {$name}.\n",
        );
    }

    private static function manifestSkill(string $name, string $description): Skill
    {
        return Skill::parse(
            "---\nname: {$name}\ndescription: {$description}\n---\n\nGhost body for {$name}.\n",
            $name,
        );
    }
}
