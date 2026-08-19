<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * crush_code.md Phase 2 item 6, measured FROM THE ENTRY POINT.
 *
 * {@see ForeignSkillDiscovery} had a full unit suite, a {@see SkillSource} tag and
 * two containment gates while nothing in `src/` or `bin/` called it. The call now
 * exists — in {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()}, reached from
 * {@see Bootstrap::skillRegistry()} — and a test that calls `discoverClaude()`
 * directly proves nothing about that, because that is exactly what the suite was
 * already doing while the feature was unreachable. So every assertion below starts
 * at `Bootstrap::chat()`, the construction chain `bin/sugarcrush` runs, and ends at
 * the ONE registry the engine reasons with: the private `skillRegistry` on the
 * launched {@see EngineBackend}, which {@see EngineBackend::complete()} passes to
 * `withAvailableSkills()` on every turn.
 *
 * WHY NOT `Bootstrap::app()`: its registry is a display copy for the Skills pane,
 * documented as such on that method, so an assertion there can pass while the
 * model sees nothing. Same reasoning {@see FeatWiringReachabilityTest} gives for
 * reaching past it.
 *
 * DOMAIN of the reachability claim proved here: the SkillRegistry the engine holds.
 * It is not a claim about the model's prompt (that depends on matching, which
 * {@see \SugarCraft\Crush\Skills\SkillMatcher}'s own tests cover) and not a claim
 * about the palette's badge (nothing renders {@see SkillSource} yet).
 */
final class ForeignSkillWiringTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $home;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_foreign_skill_wiring_' . uniqid('', true);
        $this->repo = $this->tempDir . '/repo';
        mkdir($this->repo, 0755, true);

        // 0700 and owned by this process, because HomeDirectory::owned() is what
        // ForeignSkillDiscovery::tiers() asks before it offers a user tier at all:
        // a world-writable sandbox home would make every user-tier assertion below
        // fail for a reason that has nothing to do with the wiring.
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A Claude-Code-authored skill in the user's own tree reaches the engine.
     *
     * `~/.claude/skills/<name>/SKILL.md` is the file a Claude Code user already
     * has. Before the wiring this returned null here while
     * `ForeignSkillDiscoveryTest` was green on the same bytes.
     */
    public function testAClaudeAuthoredUserSkillReachesTheEngineRegistry(): void
    {
        $this->writeSkill($this->home . '/.claude/skills', 'claude-user-skill', 'From ~/.claude/skills.');

        $skill = $this->engineSkillRegistry()->get('claude-user-skill');

        $this->assertNotNull($skill, 'a skill under ~/.claude/skills must reach the registry the engine holds');
        $this->assertSame(SkillSource::Claude, $skill->source, 'and must arrive tagged with the tool that wrote it');
    }

    /**
     * The project half of the same convention: a repository that ships
     * `.claude/agents`-style skills for its contributors.
     */
    public function testAClaudeAuthoredProjectSkillReachesTheEngineRegistry(): void
    {
        $this->writeSkill($this->repo . '/.claude/skills', 'claude-project-skill', 'From the checkout.');

        $skill = $this->engineSkillRegistry()->get('claude-project-skill');

        $this->assertNotNull($skill, 'a skill under <root>/.claude/skills must reach the engine registry');
        $this->assertSame(SkillSource::Claude, $skill->source);
    }

    /**
     * opencode's user tree lives under `~/.config`, not `~`, which is the whole
     * reason {@see ForeignSkillDiscovery} takes its two suffixes separately —
     * so it is worth an assertion of its own rather than being assumed to follow
     * from the Claude case.
     */
    public function testAnOpencodeAuthoredUserSkillReachesTheEngineRegistry(): void
    {
        $this->writeSkill($this->home . '/.config/opencode/skills', 'oc-user-skill', 'From ~/.config/opencode.');

        $skill = $this->engineSkillRegistry()->get('oc-user-skill');

        $this->assertNotNull($skill, 'a skill under ~/.config/opencode/skills must reach the engine registry');
        $this->assertSame(SkillSource::Opencode, $skill->source);
    }

    public function testAnOpencodeAuthoredProjectSkillReachesTheEngineRegistry(): void
    {
        $this->writeSkill($this->repo . '/.opencode/skills', 'oc-project-skill', 'From <root>/.opencode/skills.');

        $skill = $this->engineSkillRegistry()->get('oc-project-skill');

        $this->assertNotNull($skill, 'a skill under <root>/.opencode/skills must reach the engine registry');
        $this->assertSame(SkillSource::Opencode, $skill->source);
    }

    /**
     * All four foreign trees in ONE launch, because four passing single-tree
     * assertions do not say that the four are merged rather than the last one
     * winning — and the merge is the part of the wiring that has an ordering.
     */
    public function testAllFourForeignTreesSurviveOneLaunchTogether(): void
    {
        $this->writeSkill($this->home . '/.claude/skills', 'four-a', 'a');
        $this->writeSkill($this->repo . '/.claude/skills', 'four-b', 'b');
        $this->writeSkill($this->home . '/.config/opencode/skills', 'four-c', 'c');
        $this->writeSkill($this->repo . '/.opencode/skills', 'four-d', 'd');

        $registry = $this->engineSkillRegistry();

        foreach (['four-a', 'four-b', 'four-c', 'four-d'] as $name) {
            $this->assertNotNull($registry->get($name), "{$name} must survive the merge");
        }
    }

    /**
     * NATIVE WINS, pinned at the launch boundary.
     *
     * {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} registers the foreign
     * trees FIRST and lays the native manifests over them, so a name that already
     * resolved to something keeps resolving to it after another CLI is installed or
     * a repository carrying `.claude/skills` is cloned. The assertion is on the
     * DESCRIPTION rather than on the source tag alone: a registry that had merged
     * the wrong way round would still answer `SkillSource::Native` for the name if
     * the native entry were the one that arrived second with a different body.
     */
    public function testANativeSkillKeepsItsMeaningWhenAForeignTreeShipsTheSameName(): void
    {
        $this->writeSkill($this->repo . '/.claude/skills', 'collide', 'FOREIGN COPY');
        $this->writeSkill($this->repo . '/.sugar-crush/skills', 'collide', 'NATIVE COPY');

        $skill = $this->engineSkillRegistry()->get('collide');

        $this->assertNotNull($skill);
        $this->assertSame('NATIVE COPY', $skill->description, 'the native tier must win a name collision');
        $this->assertSame(SkillSource::Native, $skill->source);
    }

    /**
     * THE CROSS-TOOL PAIR, measured rather than inferred.
     *
     * {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} states that between
     * the two foreign trees "the fixed call order decides it (opencode over
     * Claude)" and that the pair has no principled winner, so what is being
     * guaranteed is determinism. That sentence was written from the call order and
     * from `SkillRegistry::register()`'s last-write-wins loop; this pins it, which
     * matters because the sibling registry for AGENTS resolves the same pair the
     * OTHER way ({@see ForeignAgentPresetWiringTest}) — so neither direction can be
     * assumed from the other.
     */
    public function testOpencodeWinsACrossToolCollisionAmongForeignSkills(): void
    {
        $this->writeSkill($this->repo . '/.claude/skills', 'dual-tool', 'CLAUDE COPY');
        $this->writeSkill($this->repo . '/.opencode/skills', 'dual-tool', 'OPENCODE COPY');

        $skill = $this->engineSkillRegistry()->get('dual-tool');

        $this->assertNotNull($skill);
        $this->assertSame('OPENCODE COPY', $skill->description);
        $this->assertSame(SkillSource::Opencode, $skill->source);
    }

    /**
     * THE REFUSED USER TIER, and what the user actually gets told.
     *
     * {@see \SugarCraft\Crush\Skills\ForeignSkillDiscovery::tiers()} OMITS the user
     * tier when {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} is null and
     * records no refusal for it — so on its own that is a silently shorter skill
     * list, the exact failure mode this pair of items exists to end. It is not
     * silent through `Bootstrap`, and this test is the measurement of why:
     * {@see Bootstrap::chat()} resolves `trustedConfigDirPath()` on its first line,
     * which throws on exactly `owned() === null`, so the launch is REFUSED with a
     * message naming the home and the reason before any skill scan happens.
     *
     * Both halves are asserted, because either alone is misleading: the discovery
     * really does go quiet, and the launch really does refuse before it can.
     */
    public function testAWorldWritableHomeYieldsNoForeignUserSkillsAndRefusesTheLaunchOutLoud(): void
    {
        $exposed = $this->tempDir . '/exposed-home';
        mkdir($exposed, 0777, true);
        chmod($exposed, 0o777);
        $this->writeSkill($exposed . '/.claude/skills', 'planted-skill', 'PLANTED BY ANOTHER USER');
        $this->useHomeSandbox($exposed, false);

        // Half one: the discovery itself declines the tier. Called directly ON
        // PURPOSE here — the claim being measured is about the class's own
        // behaviour under a refused home, not about its reachability. BOTH
        // conventions, because `tiers()` is shared but the two suffixes are not,
        // and a message saying "no foreign skills" may not rest on one of them.
        $discovery = new ForeignSkillDiscovery();
        $this->assertSame(
            [],
            array_keys($discovery->discoverClaude($this->repo)),
            'an unownable home must contribute no ~/.claude/skills',
        );
        $this->assertSame(
            [],
            array_keys($discovery->discoverOpencode($this->repo)),
            'nor any ~/.config/opencode/skills',
        );

        // Half two: the surface. A launch does not merely come back shorter.
        try {
            Bootstrap::chat($this->repo);
            $this->fail('a launch out of an unownable home must be refused, not quietly reduced');
        } catch (\Throwable $e) {
            $this->assertStringContainsString($exposed, $e->getMessage(), 'the refusal must name the home');
            // WHAT THIS SUBSTRING PROVES, exactly: that the refusal came from the
            // ownership gate rather than from something else that also throws.
            // `trustedConfigDirPath()`'s message enumerates all three causes it
            // covers ("does not exist, or it is world-writable, or it is owned by
            // another account") in one fixed sentence, so it CANNOT distinguish
            // which one fired — the fixture's 0777 mode is what makes this the
            // world-writable case, not the assertion.
            $this->assertStringContainsString(
                'world-writable',
                $e->getMessage(),
                'the refusal must be the ownership gate\'s, which names the modes it refuses',
            );
        }
    }

    /**
     * The registry the engine reasons with, off a real launch.
     */
    private function engineSkillRegistry(): SkillRegistry
    {
        // All THREE backend-selection env vars are cleared for the call --
        // SUGARCRUSH_PROVIDER and both shell-out variables, which is what the
        // code below does and what this comment used to under-count as "both".
        // Either shell-out variable selects a command backend, which holds no
        // registry at all, so a value inherited from the environment would turn a
        // wiring regression into a different assertion. Same dance as
        // FeatWiringReachabilityTest.
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        $streamCommand = getenv('SUGARCRUSH_BACKEND_CMD_STREAM');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM');

        try {
            $backend = Bootstrap::chat($this->repo)->backend();
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
            $streamCommand === false ? putenv('SUGARCRUSH_BACKEND_CMD_STREAM') : putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $streamCommand);
        }

        $this->assertInstanceOf(EngineBackend::class, $backend);

        $property = new \ReflectionProperty(EngineBackend::class, 'skillRegistry');
        $registry = $property->getValue($backend);

        $this->assertInstanceOf(SkillRegistry::class, $registry);

        return $registry;
    }

    private function writeSkill(string $treeDir, string $name, string $description): void
    {
        $dir = $treeDir . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir . '/SKILL.md',
            "---\nname: {$name}\ndescription: {$description}\nuser-invocable: true\n---\n\nBody prose.\n",
        );
    }
}
