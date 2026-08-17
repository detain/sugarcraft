<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\SkillDiscovery;
use SugarCraft\Crush\Skills\SkillLoader;

/**
 * The skills half of the directory-level containment boundary the workflow tier
 * already had.
 *
 * {@see SkillLoader::skillFilesIn()} confined every ENTRY to the tree it was
 * reached from, and its boundary was `realpath($dir)` — so when `$dir` itself
 * was a symlink the boundary travelled with it and nothing inside could ever be
 * outside. A repository chooses where that directory is (`.sugar-crush/skills`,
 * `.claude/skills`, `.opencode/skills` are all paths inside the checkout) and
 * git stores a symlink happily, so `git clone` was enough to have `SKILL.md`
 * bodies from outside the checkout enter the model's prompt context — a strictly
 * LARGER payload than the workflow tier's `.yaml` basenames and descriptions,
 * and the README claimed it was closed while
 * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} three lines later said it
 * was not.
 *
 * Every test here plants the SAME fixture — a skills directory committed as a
 * link to a tree outside the checkout, holding one skill whose description and
 * body are both sentinels — and checks a different seam that used to walk it.
 */
final class ProjectSkillsDirContainmentTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const DESCRIPTION_SENTINEL = 'SENTINEL-SKILL-DESCRIPTION';
    private const BODY_SENTINEL = 'SENTINEL-SKILL-BODY';

    private string $tempDir;
    private mixed $originalServerHome;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_skills_containment_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);

        // BOTH spellings, for the reason FeatWiringReachabilityTest documents:
        // HomeDirectory reads $_SERVER['HOME'] and other layers read getenv(),
        // so redirecting one leaves the other scanning the developer's own
        // ~/.claude/skills — which would put real skills in these assertions.
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
     * The skill tree a repository points at but does not contain.
     *
     * @return array{0: string, 1: string} [checkout root, the linked-at directory]
     */
    private function escapingCheckout(string $relativeSkillsPath, string $linkTarget = ''): array
    {
        $root = $this->tempDir . '/repo_' . md5($relativeSkillsPath . $linkTarget);
        mkdir($root . '/' . \dirname($relativeSkillsPath), 0755, true);

        $victim = $linkTarget === '' ? $this->tempDir . '/victim' : $linkTarget;
        if (!is_dir($victim . '/leak')) {
            mkdir($victim . '/leak', 0755, true);
            file_put_contents(
                $victim . '/leak/SKILL.md',
                "---\nname: leak\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n" . self::BODY_SENTINEL . "\n",
            );
        }

        $this->assertTrue(
            symlink($victim, $root . '/' . $relativeSkillsPath),
            'test needs a real symlinked skills directory',
        );

        return [$root, $victim];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectSkillsPaths(): array
    {
        // Every path a repository can commit that this package reads skills
        // from. Enumerated rather than sampled: the escape is per-DIRECTORY, so
        // one unanchored call site is the whole hole back.
        return [
            'native' => ['.sugar-crush/skills'],
            'claude' => ['.claude/skills'],
            'opencode' => ['.opencode/skills'],
        ];
    }

    /**
     * @dataProvider projectSkillsPaths
     */
    public function testASymlinkedProjectSkillsDirectoryOutsideTheCheckoutIsNotWalked(string $relative): void
    {
        [$root] = $this->escapingCheckout($relative);
        $dir = $root . '/' . $relative;

        $loader = new SkillLoader(reportSkips: false);
        $this->assertSame([], $loader->loadFromDirectory($dir, null, $root), 'the eager walk must refuse the tree');

        $manifests = new SkillLoader(reportSkips: false);
        $this->assertSame(
            [],
            $manifests->loadManifestsFromDirectory($dir, null, $root),
            'the manifest walk a real launch uses must refuse it too',
        );

        $dirs = new SkillLoader(reportSkips: false);
        $this->assertSame(
            [],
            $dirs->skillDirectoriesIn($dir, null, $root),
            'and the directory-listing seam SkillDiscovery reaches through',
        );
    }

    /**
     * The three production entry points, driven the way production drives them:
     * by project root, with the path built inside the loader rather than handed
     * in. A fix applied only to `skillFilesIn()`'s new argument and not to its
     * callers would leave every one of these green.
     */
    public function testTheProductionEntryPointsPassTheCheckoutAsTheAnchor(): void
    {
        [$nativeRoot] = $this->escapingCheckout('.sugar-crush/skills');

        $eager = new SkillLoader(reportSkips: false);
        $this->assertSame([], $eager->loadProjectSkills($nativeRoot));

        $all = new SkillLoader(reportSkips: false);
        foreach ($all->loadAllManifests($nativeRoot) as $name => $manifest) {
            $this->assertNotSame('leak', $name);
            $this->assertStringNotContainsString(self::DESCRIPTION_SENTINEL, $manifest['description']);
        }

        [$claudeRoot] = $this->escapingCheckout('.claude/skills');
        $foreign = new ForeignSkillDiscovery(new SkillLoader(reportSkips: false));
        $this->assertArrayNotHasKey('leak', $foreign->discoverClaude($claudeRoot));

        [$opencodeRoot] = $this->escapingCheckout('.opencode/skills');
        $this->assertArrayNotHasKey('leak', $foreign->discoverOpencode($opencodeRoot));

        // The dormant lookup seam too — its own doc-block warns that a future
        // caller inherits whatever it does.
        [$discoveryRoot] = $this->escapingCheckout('.sugar-crush/skills', $this->tempDir . '/victim2');
        $discovery = new SkillDiscovery(new SkillLoader(reportSkips: false));
        $this->assertSame([], $discovery->discoverProjectSkills($discoveryRoot));
        $this->assertSame([], $discovery->discoverLibSkills($discoveryRoot));
    }

    /**
     * Neither sentinel reaches anything a caller can read — the description that
     * goes in the system prompt, nor the body the model is handed.
     *
     * Asserted on the CONTENT rather than only on the key, because a walk that
     * refused the name but still read the file would satisfy the tests above.
     */
    public function testNeitherTheDescriptionNorTheBodyOfAnEscapedSkillIsReadable(): void
    {
        [$root] = $this->escapingCheckout('.sugar-crush/skills');

        $loader = new SkillLoader(reportSkips: false);
        $serialised = json_encode([
            $loader->loadProjectSkills($root),
            $loader->loadAllManifests($root),
            $loader->skillDirectoriesIn($root . '/.sugar-crush/skills', null, $root),
        ]);

        $this->assertIsString($serialised);
        $this->assertStringNotContainsString(self::DESCRIPTION_SENTINEL, $serialised);
        $this->assertStringNotContainsString(self::BODY_SENTINEL, $serialised);
    }

    /**
     * A skills directory resolving ONTO the checkout root, which is the same
     * one-committed-line escape the workflow tier's equality arm used to accept
     * (`.sugar-crush/skills -> ../..`).
     *
     * Separate from the outside-the-checkout tests because it is the arm where
     * "contained" and "trusted" give opposite right answers, and a build using
     * the entry-level predicate here would pass every other test in this file.
     */
    public function testASkillsDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        $root = $this->tempDir . '/onto-root';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($root . '/leak', 0755, true);
        file_put_contents(
            $root . '/leak/SKILL.md',
            "---\nname: leak\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n" . self::BODY_SENTINEL . "\n",
        );
        // `..` from inside `<root>/.sugar-crush` resolves to `<root>` EXACTLY,
        // which is the arm under test. `../..` would leave the checkout and be
        // caught by the outside-the-checkout arm instead, testing nothing new.
        $this->assertTrue(symlink('..', $root . '/.sugar-crush/skills'));

        $loader = new SkillLoader(reportSkips: false);
        $this->assertSame([], $loader->loadProjectSkills($root));
    }

    /**
     * The control every refusal test above needs: a link that stays INSIDE the
     * checkout is still followed.
     *
     * Without it the whole boundary could be "implemented" by refusing every
     * symlinked skills directory, and refusing one is wrong — `.claude/skills ->
     * shared/skills` is repository content pointing at repository content.
     */
    public function testASymlinkedSkillsDirectoryInsideTheCheckoutIsStillWalked(): void
    {
        $root = $this->tempDir . '/in-checkout';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($root . '/shared/skills/kept', 0755, true);
        file_put_contents(
            $root . '/shared/skills/kept/SKILL.md',
            "---\nname: kept\ndescription: IN-CHECKOUT-SKILL\n---\nbody\n",
        );
        $this->assertTrue(symlink($root . '/shared/skills', $root . '/.sugar-crush/skills'));

        $loader = new SkillLoader(reportSkips: false);
        $skills = $loader->loadProjectSkills($root);

        $this->assertArrayHasKey('kept', $skills);
        $this->assertSame('IN-CHECKOUT-SKILL', $skills['kept']->description);
        $this->assertSame([], $loader->refusedDirectories(), 'an in-checkout link is not a refusal');
    }

    /**
     * The other control: the USER'S OWN `~/.claude/skills` is anchored to
     * nothing, so a link out of it is still followed.
     *
     * That is the distinction the whole boundary rests on — who wrote the link —
     * and it is the fix that made eight of fourteen real `~/.config/opencode/
     * skills` entries visible in the first place. Anchoring the user tree too
     * would revert it, and no other test in this file would notice.
     */
    public function testTheUsersOwnForeignSkillsTreeIsNotAnchoredToAnything(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.claude', 0755, true);
        mkdir($home . '/skillshare/mine', 0755, true);
        file_put_contents(
            $home . '/skillshare/mine/SKILL.md',
            "---\nname: mine\ndescription: USER-OWN-SKILL\n---\nbody\n",
        );
        $this->assertTrue(symlink($home . '/skillshare', $home . '/.claude/skills'));

        $foreign = new ForeignSkillDiscovery(new SkillLoader(reportSkips: false));
        $discovered = $foreign->discoverClaude($this->tempDir . '/empty-repo');

        $this->assertArrayHasKey('mine', $discovered, "a link inside the user's own tree is the user's own");
        $this->assertSame('USER-OWN-SKILL', $discovered['mine']->description);
    }

    /**
     * The refusal DROPS THAT TREE and leaves the others alone — the same
     * tier-drop semantics the workflow registry uses.
     *
     * A refused project tree must not take the built-in and user tiers with it:
     * a repository that ships a bad link would otherwise disable every skill the
     * user has.
     */
    public function testARefusedProjectTreeLeavesTheBuiltInAndUserTiersIntact(): void
    {
        [$root] = $this->escapingCheckout('.sugar-crush/skills');

        $home = $this->tempDir . '/home';
        mkdir($home . '/.sugar-crush/skills/user-skill', 0755, true);
        file_put_contents(
            $home . '/.sugar-crush/skills/user-skill/SKILL.md',
            "---\nname: user-skill\ndescription: USER-TIER-SURVIVES\n---\nbody\n",
        );

        $loader = new SkillLoader(reportSkips: false);
        $manifests = $loader->loadAllManifests($root);

        $this->assertArrayHasKey('user-skill', $manifests);
        $this->assertArrayNotHasKey('leak', $manifests);
        $this->assertNotSame([], array_diff(array_keys($manifests), ['user-skill']), 'built-ins still load');
    }

    /**
     * The diagnostic is kept, and kept APART from the unreadable-file skips.
     *
     * Both halves are asserted because both are wrong on their own: recording
     * nothing leaves the user with a silently missing skills tree, and recording
     * it into `skipped()` makes the launch's "N skill files could not be read"
     * line count a directory as a file — a true message turned false.
     */
    public function testTheRefusalIsRecordedSeparatelyFromTheUnreadableFileSkips(): void
    {
        [$root, $victim] = $this->escapingCheckout('.sugar-crush/skills');
        $dir = $root . '/.sugar-crush/skills';

        $loader = new SkillLoader(reportSkips: false);
        $loader->loadProjectSkills($root);

        $this->assertSame([], $loader->skipped(), 'a refused directory is not an unreadable file');

        $refused = $loader->refusedDirectories();
        $this->assertArrayHasKey($dir, $refused, 'keyed by the path as spelled, which is what a user recognises');
        $this->assertStringContainsString($victim, $refused[$dir], 'and it says where the link actually went');
    }
}
