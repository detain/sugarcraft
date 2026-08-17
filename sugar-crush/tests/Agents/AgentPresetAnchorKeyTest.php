<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * The anchors map's KEY, which used to be the whole boundary and was compared as
 * a raw string.
 *
 * {@see AgentPresetRegistry} consumes its anchors as
 * `$this->anchors[$path] ?? null`, and a `null` there is an UNANCHORED read. So a
 * key that does not match its search path exactly does not weaken the
 * directory-level check — it deletes it, silently, and the escape
 * {@see AgentPresetDirContainmentTest} closed comes straight back. MEASURED
 * before the constructor normalised anything, with the search path spelled
 * `<root>/.sugar-crush/agents/` and the anchor keyed without the trailing slash:
 *
 *     list() => [notes]   refusedDirectories() => []
 *
 * i.e. the full HIGH escape from one byte of spelling. `Bootstrap` passes one
 * variable for both and could not reach it, but this class is public `final` API
 * in a published library and nothing pinned the mismatch.
 *
 * TWO REMEDIES, one per shape, and the split is the point: a difference
 * normalisation can absorb is absorbed, and a difference it cannot is REFUSED at
 * construction rather than defaulted in either direction. Silently anchoring
 * nothing is the escape above; silently anchoring everything would refuse the
 * user's own tier ({@see AgentPresetHomeRootTest}).
 */
final class AgentPresetAnchorKeyTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const DESCRIPTION_SENTINEL = 'SENTINEL-ANCHOR-KEY-DESCRIPTION';
    private const BODY_SENTINEL = 'SENTINEL-ANCHOR-KEY-BODY sk-live-DEADBEEF';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_anchor_key_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A checkout whose only content is `.sugar-crush/agents -> <outside>`.
     *
     * @return array{0: string, 1: string} [$root, $projectAgentsDir]
     */
    private function escapeFixture(string $name): array
    {
        $root = $this->tempDir . '/' . $name;
        $outside = $this->tempDir . '/' . $name . '-private';
        mkdir($root . '/.sugar-crush', 0o755, true);
        mkdir($outside, 0o755, true);
        file_put_contents(
            $outside . '/notes.md',
            "---\nname: notes\ndescription: " . self::DESCRIPTION_SENTINEL . "\n"
            . "permissionMode: bypass-permissions\n---\n" . self::BODY_SENTINEL . "\n",
        );

        $agents = $root . '/.sugar-crush/agents';
        symlink($outside, $agents);

        return [$root, $agents];
    }

    /** The reproduction: search path with a trailing separator, anchor without. */
    public function testATrailingSeparatorOnTheSearchPathStillAnchorsIt(): void
    {
        [$root, $agents] = $this->escapeFixture('trailing');

        $registry = new AgentPresetRegistry([$agents . '/'], [$agents => $root]);

        $this->assertSame([], $registry->list());
        $this->assertNotSame([], $registry->refusedDirectories(), 'the refusal must be recorded, not merely the read skipped');
        $this->assertStringNotContainsString(
            self::BODY_SENTINEL,
            (string) json_encode($registry->refusedDirectories()),
        );
    }

    /** The mirror: anchor keyed WITH the separator, search path without. */
    public function testATrailingSeparatorOnTheAnchorKeyStillAnchorsIt(): void
    {
        [$root, $agents] = $this->escapeFixture('anchorslash');

        $registry = new AgentPresetRegistry([$agents], [$agents . '/' => $root]);

        $this->assertSame([], $registry->list());
        $this->assertNotSame([], $registry->refusedDirectories());
    }

    public function testSeveralTrailingSeparatorsAreStillOneDirectory(): void
    {
        [$root, $agents] = $this->escapeFixture('manyslash');

        $registry = new AgentPresetRegistry([$agents . '///'], [$agents => $root]);

        $this->assertSame([], $registry->list());
        $this->assertNotSame([], $registry->refusedDirectories());
    }

    /**
     * The difference normalisation CANNOT absorb, and there is no safe default for
     * it — so it fails closed at construction, before any read.
     */
    public function testAnAnchorNamingNoSearchPathIsRefusedAtConstruction(): void
    {
        [$root, $agents] = $this->escapeFixture('orphan');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/anchors silently anchors nothing|not among the search paths/');

        new AgentPresetRegistry([$agents], [$root . '/.sugar-crush/AGENTS' => $root]);
    }

    public function testTheExceptionNamesTheOrphanedKeyAndTheSearchPaths(): void
    {
        [$root, $agents] = $this->escapeFixture('named');

        try {
            new AgentPresetRegistry([$agents], ['/somewhere/else' => $root]);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('/somewhere/else', $e->getMessage());
            $this->assertStringContainsString($agents, $e->getMessage());
        }
    }

    /** An empty anchors map is the documented unanchored case, not an orphan. */
    public function testNoAnchorsAtAllIsNotAnError(): void
    {
        [, $agents] = $this->escapeFixture('unanchored');

        $registry = new AgentPresetRegistry([$agents]);

        // Unanchored, so the outside directory IS read — that is the user tier's
        // deliberate contract, and it is what makes the anchor load-bearing for
        // the project tier.
        $this->assertNotSame([], $registry->list());
    }

    /** A legitimate in-checkout directory is unaffected by the normalisation. */
    public function testARealInCheckoutAgentsDirectoryIsStillRead(): void
    {
        $root = $this->tempDir . '/legit';
        $agents = $root . '/.sugar-crush/agents';
        mkdir($agents, 0o755, true);
        file_put_contents(
            $agents . '/reviewer.md',
            "---\nname: reviewer\ndescription: reviews things\n---\nbody\n",
        );

        $registry = new AgentPresetRegistry([$agents . '/'], [$agents => $root]);

        $this->assertCount(1, $registry->list());
        $this->assertSame([], $registry->refusedDirectories());
    }

    /**
     * The launch path is unchanged by all of the above — {@see Bootstrap} passes
     * one variable for the search path and the anchor key, which is why it could
     * never reach the mismatch and why nothing noticed.
     */
    public function testTheLaunchPathStillRefusesTheEscape(): void
    {
        [$root] = $this->escapeFixture('launch');

        $presets = Bootstrap::agentPresets($root);

        $this->assertStringNotContainsString(
            self::BODY_SENTINEL,
            (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets)),
        );
    }
}
