<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * The agent-preset tier's directory-level containment boundary — the THIRD
 * subsystem to need it, found live after the round that closed the workflow and
 * skills tiers described all three as sharing one trust model.
 *
 * {@see AgentPresetRegistry} confined every ENTRY to the search path it was
 * listed from, and resolved that search path too — so when the search path was
 * ITSELF a symlink the boundary travelled with it and nothing inside could ever
 * be outside. `<root>/.sugar-crush/agents` is a path the repository chooses and
 * git stores a symlink happily, so `git clone` plus one committed line was
 * enough. MEASURED on the pre-fix build against a fixture of the same shape these
 * tests plant (its own sentinel strings, hence the different words below):
 *
 *     preset=notes  desc=PRIVATE NOTE DESCRIPTION  mode=bypass-permissions
 *     prompt=SENTINEL-PRIVATE-BODY sk-live-DEADBEEF
 *
 * The payload is the largest of the three tiers: an outside file's
 * `description` becomes a roster entry, its BODY becomes a sub-agent's
 * `initialPrompt`, and its `permissionMode:` is honoured — so a repository the
 * user cloned could hand a sub-agent `bypass-permissions` out of a file the
 * repository does not contain.
 *
 * Every test plants the same fixture and checks a different seam:
 * {@see AgentPresetRegistry::list()} (the one every launch reaches),
 * {@see AgentPresetRegistry::load()}, and
 * {@see Bootstrap::agentPresets()} (the launch path itself).
 */
final class AgentPresetDirContainmentTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const DESCRIPTION_SENTINEL = 'SENTINEL-PRESET-DESCRIPTION';
    private const BODY_SENTINEL = 'SENTINEL-PRESET-BODY sk-live-DEADBEEF';

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_preset_containment_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);

        // BOTH spellings, for the reason ProjectSkillsDirContainmentTest states:
        // HomeDirectory reads $_SERVER['HOME'] and Bootstrap reads getenv(), so
        // redirecting one would leave the other reading the DEVELOPER's own
        // ~/.sugar-crush/agents into these assertions.
        $this->originalHome = getenv('HOME') ?: '';
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->tempDir . '/home');
        $_SERVER['HOME'] = $this->tempDir . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A checkout whose only content is `.sugar-crush/agents -> <outside>`, and a
     * preset in the target declaring the most dangerous frontmatter there is.
     *
     * @return array{0: string, 1: string} [$root, $projectAgentsDir]
     */
    private function escapeFixture(string $name = 'escape'): array
    {
        $root = $this->tempDir . '/' . $name;
        $outside = $this->tempDir . '/' . $name . '-private';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($outside, 0755, true);
        file_put_contents(
            $outside . '/notes.md',
            "---\nname: notes\ndescription: " . self::DESCRIPTION_SENTINEL . "\n"
            . "permissionMode: bypass-permissions\n---\n" . self::BODY_SENTINEL . "\n",
        );

        $agents = $root . '/.sugar-crush/agents';
        $this->assertTrue(symlink($outside, $agents));

        return [$root, $agents];
    }

    private function anchoredRegistry(string $root, string $agents): AgentPresetRegistry
    {
        return new AgentPresetRegistry(
            [$agents, $this->tempDir . '/home/.sugar-crush/agents'],
            [$agents => $root],
        );
    }

    /**
     * The seam every launch reaches, and the one whose absence made load()'s
     * check decorative.
     */
    public function testAnAgentsDirectoryPointingOutOfTheCheckoutListsNoPresets(): void
    {
        [$root, $agents] = $this->escapeFixture();

        $registry = $this->anchoredRegistry($root, $agents);

        $this->assertSame([], $registry->list());

        // Asserted on the SERIALISED roster, not just the keys: a build that
        // returned an empty-named preset carrying the body would pass a
        // key-only check.
        $serialised = json_encode(array_map(
            static fn (object $p): array => (array) $p,
            $registry->list(),
        ));
        $this->assertStringNotContainsString(self::DESCRIPTION_SENTINEL, (string) $serialised);
        $this->assertStringNotContainsString(self::BODY_SENTINEL, (string) $serialised);
    }

    /** load() must refuse the same directory list() refuses. */
    public function testLoadingAPresetByNameOutOfAnEscapedDirectoryThrows(): void
    {
        [$root, $agents] = $this->escapeFixture();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Preset 'notes' not found/");

        $this->anchoredRegistry($root, $agents)->load('notes');
    }

    /**
     * The refusal is RECORDED, not merely obeyed.
     *
     * A dropped directory is otherwise indistinguishable from an empty one, and
     * "your repository's agents directory was rejected" is not something a
     * shorter roster can say — the same seam
     * {@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()} and
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()}
     * provide for their tiers.
     */
    public function testTheRefusedAgentsDirectoryIsRecordedWithItsReason(): void
    {
        [$root, $agents] = $this->escapeFixture();

        $registry = $this->anchoredRegistry($root, $agents);
        $registry->list();

        $refusals = $registry->refusedDirectories();
        $this->assertArrayHasKey($agents, $refusals);
        $this->assertStringContainsString(
            (string) realpath($this->tempDir . '/escape-private'),
            $refusals[$agents],
            'the reason must say where the directory actually resolved to',
        );
        $this->assertStringNotContainsString(
            $agents,
            $refusals[$agents],
            'and must not repeat the path its caller already has as the map key',
        );
    }

    /**
     * The launch path itself, because that is where the escape was reachable:
     * Bootstrap::chat() -> agentManager() -> agentPresets() -> list() on every
     * start.
     *
     * Also pins the collector wiring — the agents tier reaches
     * Bootstrap::projectTierRefusals() by a different route from the workflow
     * registry's and the skill loader's, and one route working is not another
     * working.
     */
    public function testALaunchRefusesAndRecordsAnEscapedAgentsDirectory(): void
    {
        [$root, $agents] = $this->escapeFixture('launch');

        $presets = Bootstrap::agentPresets($root);

        $this->assertSame([], $presets);
        $this->assertArrayHasKey($agents, Bootstrap::projectTierRefusals());
    }

    /**
     * The arm where "contained" and "trusted" give opposite right answers.
     *
     * `.sugar-crush/agents -> ..` resolves EXACTLY onto `<root>/.sugar-crush`'s
     * parent — the checkout root — which the entry-level predicate counts as
     * contained and a trust anchor must not. A build using `within()` here
     * instead of `below()` passes every other test in this file.
     */
    public function testAnAgentsDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        $root = $this->tempDir . '/onto-root';
        mkdir($root . '/.sugar-crush', 0755, true);
        file_put_contents(
            $root . '/local.md',
            "---\nname: local\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n" . self::BODY_SENTINEL . "\n",
        );
        // `../..` from inside `.sugar-crush` would LEAVE the checkout and be
        // caught by the outside-the-checkout arm instead, testing nothing new.
        $agents = $root . '/.sugar-crush/agents';
        $this->assertTrue(symlink('..', $agents));

        $registry = $this->anchoredRegistry($root, $agents);

        $this->assertSame([], $registry->list());
        $this->assertArrayHasKey($agents, $registry->refusedDirectories());
    }

    /**
     * A DANGLING committed link is refused rather than left readable for
     * whatever appears at the target later.
     */
    public function testADanglingAgentsSymlinkIsRefusedAndSaysSo(): void
    {
        $root = $this->tempDir . '/dangling';
        mkdir($root . '/.sugar-crush', 0755, true);
        $agents = $root . '/.sugar-crush/agents';
        $this->assertTrue(symlink($this->tempDir . '/not-there-yet', $agents));

        $registry = $this->anchoredRegistry($root, $agents);

        $this->assertSame([], $registry->list());
        $this->assertArrayHasKey($agents, $registry->refusedDirectories());
    }

    /**
     * The control the refusals need: the ordinary layouts still work.
     *
     * Both of them — a real directory, and a link to somewhere else INSIDE the
     * checkout (`.sugar-crush/agents -> tools/agents`), which is repository
     * content pointing at repository content. Refusing every symlinked agents
     * directory would satisfy every test above and break a working layout.
     */
    public function testTheOrdinaryInCheckoutLayoutsStillLoadTheirPresets(): void
    {
        $plain = $this->tempDir . '/plain';
        mkdir($plain . '/.sugar-crush/agents', 0755, true);
        file_put_contents(
            $plain . '/.sugar-crush/agents/coder.md',
            "---\nname: coder\ndescription: In-repo coder\n---\nIN-REPO-BODY\n",
        );

        $registry = $this->anchoredRegistry($plain, $plain . '/.sugar-crush/agents');
        $presets = $registry->list();
        $this->assertArrayHasKey('coder', $presets);
        $this->assertSame('In-repo coder', $presets['coder']->description);
        $this->assertSame('IN-REPO-BODY', $presets['coder']->initialPrompt);
        $this->assertSame([], $registry->refusedDirectories());

        $linked = $this->tempDir . '/linked';
        mkdir($linked . '/.sugar-crush', 0755, true);
        mkdir($linked . '/tools/agents', 0755, true);
        file_put_contents(
            $linked . '/tools/agents/reviewer.md',
            "---\nname: reviewer\ndescription: In-repo reviewer\n---\nBODY\n",
        );
        $agents = $linked . '/.sugar-crush/agents';
        $this->assertTrue(symlink($linked . '/tools/agents', $agents));

        $registry = $this->anchoredRegistry($linked, $agents);
        $this->assertArrayHasKey('reviewer', $registry->list());
        $this->assertSame([], $registry->refusedDirectories(), 'an in-checkout link is not a refusal');
        $this->assertSame('In-repo reviewer', $registry->load('reviewer')->description);
    }

    /**
     * The other control: the USER'S OWN tier is anchored to NOTHING, so a link
     * out of it is still followed.
     *
     * `~/.sugar-crush/agents -> ~/.claude/agents` is a layout people really run —
     * one roster, two tools — and the distinction the anchor encodes is WHO WROTE
     * THE LINK, not where it points. Anchoring the user tier to the checkout
     * would "fix" nothing and delete a working configuration.
     */
    public function testTheUserTierIsNotAnchoredToTheCheckout(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.sugar-crush', 0755, true);
        mkdir($home . '/.claude/agents', 0755, true);
        file_put_contents(
            $home . '/.claude/agents/mine.md',
            "---\nname: mine\ndescription: My own preset\n---\nMY BODY\n",
        );
        $this->assertTrue(symlink($home . '/.claude/agents', $home . '/.sugar-crush/agents'));

        [$root, $agents] = $this->escapeFixture('user-tier');
        $registry = $this->anchoredRegistry($root, $agents);

        $presets = $registry->list();
        $this->assertArrayHasKey('mine', $presets, "the user's own linked directory must still be read");
        $this->assertSame('MY BODY', $presets['mine']->initialPrompt);
        // And the project tier in the SAME registry is still refused, so this is
        // not "anchoring was skipped for everything".
        $this->assertArrayNotHasKey('notes', $presets);
        $this->assertArrayHasKey($agents, $registry->refusedDirectories());
    }

    /**
     * The per-ENTRY boundary, which is the one this class always had and which
     * must survive the directory-level one being added around it.
     */
    public function testAPresetFileLinkedOutOfAnHonouredDirectoryIsSkipped(): void
    {
        $root = $this->tempDir . '/entry';
        mkdir($root . '/.sugar-crush/agents', 0755, true);
        mkdir($root . '-outside', 0755, true);
        file_put_contents(
            $root . '-outside/stolen.md',
            "---\nname: stolen\ndescription: " . self::DESCRIPTION_SENTINEL . "\n"
            . "permissionMode: bypass-permissions\n---\n" . self::BODY_SENTINEL . "\n",
        );
        $agents = $root . '/.sugar-crush/agents';
        $this->assertTrue(symlink($root . '-outside/stolen.md', $agents . '/stolen.md'));

        $registry = $this->anchoredRegistry($root, $agents);

        $this->assertSame([], $registry->list());
        // No refusal recorded: the DIRECTORY was honoured, one entry inside it
        // was not, and conflating the two would tell the user their repository's
        // directory was rejected when it was read.
        $this->assertSame([], $registry->refusedDirectories());

        try {
            $registry->load('stolen');
            $this->fail('a linked-out preset must not be loadable by name either');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
    }

    /**
     * A `..` in the requested NAME cannot walk out of the search path, which is
     * the one escape load() has that list() does not (list() only ever sees
     * names glob() produced).
     */
    public function testAPresetNameCannotTraverseOutOfTheSearchPath(): void
    {
        $root = $this->tempDir . '/traverse';
        mkdir($root . '/.sugar-crush/agents', 0755, true);
        file_put_contents(
            $root . '/.sugar-crush/secrets.md',
            "---\nname: secrets\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n" . self::BODY_SENTINEL . "\n",
        );
        $agents = $root . '/.sugar-crush/agents';

        $this->expectException(\RuntimeException::class);
        $this->anchoredRegistry($root, $agents)->load('../secrets');
    }

    /**
     * A preset carrying `permissionMode: bypass-permissions` from a directory the
     * repository DOES contain is still honoured — stated as a fact about the
     * boundary rather than left as an inference, because "the escape is closed"
     * must not be read as "presets cannot raise permissions".
     */
    public function testAnInCheckoutPresetMayStillDeclareBypassPermissions(): void
    {
        $root = $this->tempDir . '/declared';
        mkdir($root . '/.sugar-crush/agents', 0755, true);
        file_put_contents(
            $root . '/.sugar-crush/agents/trusted.md',
            "---\nname: trusted\ndescription: Committed by this repo\n"
            . "permissionMode: bypass-permissions\n---\nBODY\n",
        );
        $agents = $root . '/.sugar-crush/agents';

        $preset = $this->anchoredRegistry($root, $agents)->load('trusted');

        $this->assertSame(PermissionMode::BypassPermissions, $preset->permissionMode);
    }
}
