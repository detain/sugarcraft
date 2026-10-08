<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Lane C's two additions to the foreign discovery, pinned where they are born:
 *
 *  - the agents-spec trees (`<root>/.agents/skills`, `~/.agents/skills`), the
 *    tool-neutral convention, tagged SkillSource::AgentSkillsSpec and ordered
 *    lowest among the foreign conventions (spec < claude < opencode) — the
 *    least tool-specific convention loses to the ones a named tool wrote;
 *  - opencode's SECOND user tree, the legacy `~/.opencode/skills` the tool
 *    itself still reads beside its XDG `~/.config/opencode/skills`. Inside one
 *    convention the later registration wins, so the XDG tree beats the legacy
 *    dot tree — and a byte-identical twin of the same skill in both is the
 *    same FILE by content, which SkillLoader::recordShadowing() suppresses
 *    silently rather than reporting a shadow that shadows nothing.
 */
final class AgentsTreeAndLegacyOpencodeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-agents-tree-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        // Both spellings of HOME redirected — every discover*() and loadAll()
        // below also scans the user tier, and the machine's real foreign-skill
        // directories must not leak into these fixtures.
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        parent::tearDown();
    }

    private function createSkillFile(string $dir, string $name, string $description): void
    {
        $skillDir = $dir . '/' . $name;
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\ndescription: $description\n---\n\nBody.");
    }

    public function testDiscoverAgentsReadsTheProjectTreeTaggedSpec(): void
    {
        $projectRoot = $this->tempDir . '/spec-project';
        $this->createSkillFile($projectRoot . '/.agents/skills', 'spec-proj', 'from the spec project tree');

        $result = (new ForeignSkillDiscovery())->discoverAgents($projectRoot);

        $this->assertArrayHasKey('spec-proj', $result);
        $this->assertSame(SkillSource::AgentSkillsSpec, $result['spec-proj']->source);
        $this->assertSame(SkillOrigin::Project, $result['spec-proj']->origin);
    }

    public function testDiscoverAgentsReadsTheUserTreeBehindTheOwnedHome(): void
    {
        $projectRoot = $this->tempDir . '/spec-project-user';
        $this->createSkillFile($this->home . '/.agents/skills', 'spec-user', 'from the spec user tree');

        $result = (new ForeignSkillDiscovery())->discoverAgents($projectRoot);

        $this->assertArrayHasKey('spec-user', $result);
        $this->assertSame(SkillSource::AgentSkillsSpec, $result['spec-user']->source);
        $this->assertSame(SkillOrigin::User, $result['spec-user']->origin);
    }

    public function testDiscoverOpencodeReadsTheLegacyUserTree(): void
    {
        $projectRoot = $this->tempDir . '/legacy-project';
        $this->createSkillFile($this->home . '/.opencode/skills', 'legacy-only', 'from the legacy dot tree');

        $result = (new ForeignSkillDiscovery())->discoverOpencode($projectRoot);

        $this->assertArrayHasKey('legacy-only', $result);
        $this->assertSame(SkillSource::Opencode, $result['legacy-only']->source);
        $this->assertStringEndsWith('/.opencode/skills/legacy-only/SKILL.md', $result['legacy-only']->sourcePath);
    }

    /**
     * THE SILENT COLLAPSE. The same skill installed twice — identical bytes
     * in both opencode user trees — is not a shadowing event worth a line of
     * operator noise: recordShadowing() answers sameSkillFile() and returns
     * without recording, and the XDG tree (registered later within the tier)
     * is the one that stands.
     */
    public function testAByteIdenticalTwinAcrossTheTwoOpencodeUserTreesCollapsesToOneSilently(): void
    {
        $projectRoot = $this->tempDir . '/twin-project';
        $this->createSkillFile($this->home . '/.opencode/skills', 'twin', 'same bytes in both trees');
        $this->createSkillFile($this->home . '/.config/opencode/skills', 'twin', 'same bytes in both trees');

        $loader = new SkillLoader(reportSkips: false);
        $result = (new ForeignSkillDiscovery($loader))->discoverOpencode($projectRoot);

        $this->assertCount(1, $result, 'the twin must collapse to one registration');
        $this->assertSame([], $loader->skipped(), 'a byte-identical twin is the same file by content — nothing was shadowed');
        $this->assertStringEndsWith(
            '/.config/opencode/skills/twin/SKILL.md',
            $result['twin']->sourcePath,
            'the XDG tree is the later registration inside the convention and stands',
        );
    }

    /**
     * THE LOUD DIVERGENCE. Different bytes under one name are a real
     * collision — same tier, same convention — and the operator is told, with
     * the XDG tree named as the winner by the documented within-convention
     * order.
     */
    public function testADifferingTwinAcrossTheTwoOpencodeUserTreesLosesToTheXdgTreeOutLoud(): void
    {
        $projectRoot = $this->tempDir . '/diverged-project';
        $this->createSkillFile($this->home . '/.opencode/skills', 'twin', 'LEGACY COPY');
        $this->createSkillFile($this->home . '/.config/opencode/skills', 'twin', 'XDG COPY');

        $loader = new SkillLoader(reportSkips: false);
        $result = (new ForeignSkillDiscovery($loader))->discoverOpencode($projectRoot);

        $this->assertSame('XDG COPY', $result['twin']->description);
        $loserPath = $this->home . '/.opencode/skills/twin/SKILL.md';
        $this->assertArrayHasKey($loserPath, $loader->skipped(), 'the legacy copy must be reported, not dropped silently');
        $reason = $loader->skipped()[$loserPath];
        $this->assertStringContainsString(
            "shadowed by [user, foreign: opencode] skill {$this->home}/.config/opencode/skills/twin/SKILL.md",
            $reason,
        );
        $this->assertStringContainsString("this [user, foreign: opencode] skill was not loaded", $reason);
        $this->assertCount(1, $loader->skipped());
    }

    /**
     * THE CROSS-CONVENTION ORDER inside one project tier at the merge layer:
     * spec loses to claude loses to opencode, and every loser is recorded.
     */
    public function testTheSpecTreeLosesToClaudeAndOpencodeInsideOneProjectTier(): void
    {
        $projectRoot = $this->tempDir . '/order-project';
        $this->createSkillFile($projectRoot . '/.agents/skills', 'tri', 'SPEC COPY');
        $this->createSkillFile($projectRoot . '/.claude/skills', 'tri', 'CLAUDE COPY');
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'tri', 'OPENCODE COPY');

        $loader = new SkillLoader(reportSkips: false);
        $registry = new SkillRegistry();
        (new SkillManager($loader, $registry))->loadAll($projectRoot);

        $skill = $registry->get('tri');
        $this->assertNotNull($skill);
        $this->assertSame('OPENCODE COPY', $skill->description, 'opencode is the last foreign registration inside a tier');
        $this->assertSame(SkillSource::Opencode, $skill->source);
        $this->assertArrayHasKey($projectRoot . '/.agents/skills/tri/SKILL.md', $loader->skipped(), 'the spec copy is reported');
        $this->assertArrayHasKey($projectRoot . '/.claude/skills/tri/SKILL.md', $loader->skipped(), 'the claude copy is reported');
    }

    /**
     * Native remains the tie-break winner over all three foreign conventions
     * inside one tier — the order the additions may not disturb.
     */
    public function testNativeStillBeatsAllThreeForeignConventionsInsideOneProjectTier(): void
    {
        $projectRoot = $this->tempDir . '/native-project';
        $this->createSkillFile($projectRoot . '/.agents/skills', 'tri', 'SPEC COPY');
        $this->createSkillFile($projectRoot . '/.claude/skills', 'tri', 'CLAUDE COPY');
        $this->createSkillFile($projectRoot . '/.opencode/skills', 'tri', 'OPENCODE COPY');
        $this->createSkillFile($projectRoot . '/.sugar-crush/skills', 'tri', 'NATIVE COPY');

        $loader = new SkillLoader(reportSkips: false);
        $registry = new SkillRegistry();
        (new SkillManager($loader, $registry))->loadAll($projectRoot);

        $skill = $registry->get('tri');
        $this->assertNotNull($skill);
        $this->assertSame('NATIVE COPY', $skill->description);
        $this->assertSame(SkillSource::Native, $skill->source);
    }
}
