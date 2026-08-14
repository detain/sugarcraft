<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\SkillDiscovery;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Tests for SkillDiscovery - discovers skill directories across search paths.
 */
final class SkillDiscoveryTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-discovery-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        // discoverUserSkills()/discoverAll() read ~/.sugar-crush/skills, so the
        // class-wide default is an empty sandbox HOME rather than the
        // developer's own -- see HomeSandboxTrait for why BOTH spellings.
        $this->useHomeSandbox($this->tempDir . '/default-empty-home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * F11's second site. `discoverUserSkills()` reads `getenv('HOME')`, not
     * `$_SERVER['HOME']`: while both halves of this codebase read the
     * superglobal they were wrong TOGETHER, and the moment one moved a
     * `putenv('HOME')` pointed two subsystems at two different homes.
     */
    public function testUserSkillsFollowTheEnvHomeNotTheServerSuperglobal(): void
    {
        // WITH a SKILL.md in each: discovery walks through
        // {@see \SugarCraft\Crush\Skills\SkillLoader::skillDirectoriesIn()},
        // which reports the directories that actually hold one rather than
        // every subdirectory. Both fixtures get the file so the assertion below
        // is still only about WHICH HOME was read.
        $envHome = $this->tempDir . '/env-home';
        mkdir($envHome . '/.sugar-crush/skills/from-env', 0777, true);
        file_put_contents($envHome . '/.sugar-crush/skills/from-env/SKILL.md', "---\ndescription: Env\n---\n");

        $decoyHome = $this->tempDir . '/server-home';
        mkdir($decoyHome . '/.sugar-crush/skills/from-server', 0777, true);
        file_put_contents($decoyHome . '/.sugar-crush/skills/from-server/SKILL.md', "---\ndescription: Server\n---\n");

        $this->useHomeSandbox($envHome);
        $_SERVER['HOME'] = $decoyHome;

        $found = array_map('basename', (new SkillDiscovery())->discoverUserSkills());

        $this->assertContains('from-env', $found);
        $this->assertNotContains('from-server', $found);
    }

    /**
     * A SECOND, UNCONTAINED WALKER IS THE SAME HOLE TWICE.
     *
     * `discoverSkillsAt()` used its own `DirectoryIterator` and returned every
     * `isDir()` entry — and `isDir()` stats THROUGH a symlink — so a cloned
     * repository carrying `.sugar-crush/skills/escape -> $HOME` had this class
     * report the user's home directory as a skill directory. Git stores
     * symlinks, so that arrives with `git clone`. It is the identical escape
     * {@see \SugarCraft\Crush\Skills\SkillLoader} closed one class over, which
     * is why the walk now goes through that loader instead of beside it.
     */
    public function testAProjectSkillsSymlinkCannotEscapeTheSkillsTree(): void
    {
        $projectRoot = $this->tempDir . '/escape-project';
        mkdir($projectRoot . '/.sugar-crush/skills', 0777, true);

        $outside = $this->tempDir . '/outside';
        mkdir($outside . '/secret', 0777, true);
        file_put_contents($outside . '/secret/SKILL.md', "---\ndescription: Not yours\n---\n");

        if (!@symlink($outside, $projectRoot . '/.sugar-crush/skills/escape')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $discovery = new SkillDiscovery();

        $this->assertSame([], $discovery->discoverProjectSkills($projectRoot));
        $this->assertNotSame([], $discovery->skipped(), 'the refusal is recorded, not silent');
    }

    /**
     * The other half of the same rule: a link in the USER's own tree may reach
     * the rest of the user's home, which is the real layout the loader's
     * $ownedBy widening exists for (skills linked in from a shared checkout).
     */
    public function testAUserSkillsSymlinkMayStillReachTheRestOfTheUsersHome(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/owned-home');
        mkdir($home . '/.sugar-crush/skills', 0777, true);
        mkdir($home . '/elsewhere/shared-skill', 0777, true);
        file_put_contents($home . '/elsewhere/shared-skill/SKILL.md', "---\ndescription: Shared\n---\n");

        if (!@symlink($home . '/elsewhere/shared-skill', $home . '/.sugar-crush/skills/shared-skill')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        try {
            $found = array_map('basename', (new SkillDiscovery())->discoverUserSkills());

            $this->assertContains('shared-skill', $found);
        } finally {
            $this->useHomeSandbox($this->tempDir . '/default-empty-home');
        }
    }

    private function createSkillDir(string $path): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        mkdir($fullPath, 0777, true);
        file_put_contents($fullPath . '/SKILL.md', "---\ndescription: Test skill\n---\nContent");
    }

    // -------------------------------------------------------------------------
    // discoverProjectSkills()
    // -------------------------------------------------------------------------

    public function testDiscoverProjectSkillsReturnsEmptyWhenNoProjectSkillsDir(): void
    {
        $discovery = new SkillDiscovery();

        $result = $discovery->discoverProjectSkills($this->tempDir . '/nonexistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverProjectSkillsFindsSkillDirectories(): void
    {
        $discovery = new SkillDiscovery();
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot . '/.sugar-crush/skills', 0777, true);
        $this->createSkillDir('project/.sugar-crush/skills/my-project-skill');
        $this->createSkillDir('project/.sugar-crush/skills/another-skill');

        $result = $discovery->discoverProjectSkills($projectRoot);

        $this->assertCount(2, $result);
        $this->assertContains($projectRoot . '/.sugar-crush/skills/my-project-skill', $result);
        $this->assertContains($projectRoot . '/.sugar-crush/skills/another-skill', $result);
    }

    public function testDiscoverProjectSkillsReturnsAbsolutePaths(): void
    {
        $discovery = new SkillDiscovery();
        $projectRoot = $this->tempDir . '/abs-project';
        mkdir($projectRoot . '/.sugar-crush/skills/test-skill', 0777, true);
        file_put_contents($projectRoot . '/.sugar-crush/skills/test-skill/SKILL.md', "---\ndescription: Test\n---\n");

        $result = $discovery->discoverProjectSkills($projectRoot);

        $this->assertCount(1, $result);
        $realPath = realpath($projectRoot . '/.sugar-crush/skills/test-skill');
        $this->assertSame($realPath, $result[0]);
    }

    // -------------------------------------------------------------------------
    // discoverUserSkills()
    // -------------------------------------------------------------------------

    public function testDiscoverUserSkillsReturnsEmptyWhenNoUserSkillsDir(): void
    {
        $discovery = new SkillDiscovery();

        // Use a temp home that definitely has no skills
        $home = $this->useHomeSandbox($this->tempDir . '/empty-home');
        mkdir($home . '/.sugar-crush/skills', 0777, true);

        try {
            $result = $discovery->discoverUserSkills();

            $this->assertIsArray($result);
            // The directory exists but is empty
            $this->assertEmpty($result);
        } finally {
            $this->useHomeSandbox($this->tempDir . '/default-empty-home');
        }
    }

    public function testDiscoverUserSkillsFindsSkillDirectories(): void
    {
        $discovery = new SkillDiscovery();
        $fakeHome = $this->tempDir . '/fake-home';
        $this->useHomeSandbox($fakeHome);
        mkdir($fakeHome . '/.sugar-crush/skills/user-skill', 0777, true);
        file_put_contents($fakeHome . '/.sugar-crush/skills/user-skill/SKILL.md', "---\ndescription: User skill\n---\n");

        try {
            $result = $discovery->discoverUserSkills();

            $this->assertCount(1, $result);
            $this->assertStringContainsString('user-skill', $result[0]);
        } finally {
            $this->useHomeSandbox($this->tempDir . '/default-empty-home');
        }
    }

    // -------------------------------------------------------------------------
    // discoverLibSkills()
    // -------------------------------------------------------------------------

    public function testDiscoverLibSkillsReturnsEmptyWhenNoLibSkillsDir(): void
    {
        $discovery = new SkillDiscovery();
        $libPath = $this->tempDir . '/some-lib';

        $result = $discovery->discoverLibSkills($libPath);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverLibSkillsFindsNestedSkillDirectories(): void
    {
        $discovery = new SkillDiscovery();
        $libPath = $this->tempDir . '/my-lib';
        mkdir($libPath . '/.sugar-crush/skills/lib-nested-skill', 0777, true);
        file_put_contents($libPath . '/.sugar-crush/skills/lib-nested-skill/SKILL.md', "---\ndescription: Lib nested\n---\n");

        $result = $discovery->discoverLibSkills($libPath);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('lib-nested-skill', $result[0]);
    }

    public function testDiscoverLibSkillsHandlesMultipleLibSkills(): void
    {
        $discovery = new SkillDiscovery();
        $libPath = $this->tempDir . '/multi-lib';
        mkdir($libPath . '/.sugar-crush/skills/skill-a', 0777, true);
        mkdir($libPath . '/.sugar-crush/skills/skill-b', 0777, true);
        file_put_contents($libPath . '/.sugar-crush/skills/skill-a/SKILL.md', "---\ndescription: A\n---\n");
        file_put_contents($libPath . '/.sugar-crush/skills/skill-b/SKILL.md', "---\ndescription: B\n---\n");

        $result = $discovery->discoverLibSkills($libPath);

        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // discoverAll() - combined discovery with deduplication
    // -------------------------------------------------------------------------

    public function testDiscoverAllReturnsEmptyWhenNoSkillsExist(): void
    {
        $discovery = new SkillDiscovery();

        $result = $discovery->discoverAll([]);

        $this->assertIsArray($result);
        // Only built-in skills that exist in the real project would be returned
    }

    public function testDiscoverAllCombinesAllThreeSources(): void
    {
        $discovery = new SkillDiscovery();

        // Set up project skills
        $projectRoot = $this->tempDir . '/all-project';
        mkdir($projectRoot . '/.sugar-crush/skills/project-skill', 0777, true);
        file_put_contents($projectRoot . '/.sugar-crush/skills/project-skill/SKILL.md', "---\ndescription: Project\n---\n");

        // Set up user skills (using fake home)
        $fakeHome = $this->tempDir . '/all-user';
        $this->useHomeSandbox($fakeHome);
        mkdir($fakeHome . '/.sugar-crush/skills/user-skill', 0777, true);
        file_put_contents($fakeHome . '/.sugar-crush/skills/user-skill/SKILL.md', "---\ndescription: User\n---\n");

        // Set up lib skills
        $libPath = $this->tempDir . '/all-lib';
        mkdir($libPath . '/.sugar-crush/skills/lib-skill', 0777, true);
        file_put_contents($libPath . '/.sugar-crush/skills/lib-skill/SKILL.md', "---\ndescription: Lib\n---\n");

        try {
            // Pass the temp project root so discoverAll finds our project skills
            $result = $discovery->discoverAll([$libPath], $projectRoot);

            // Should have all three skills from different sources
            $this->assertCount(3, $result);
            $this->assertArrayHasKey('project-skill', $result);
            $this->assertArrayHasKey('user-skill', $result);
            $this->assertArrayHasKey('lib-skill', $result);
        } finally {
            $this->useHomeSandbox($this->tempDir . '/default-empty-home');
        }
    }

    public function testDiscoverAllLaterPathsOverrideEarlierOnConflict(): void
    {
        $discovery = new SkillDiscovery();

        // Set up user skills with a conflicting skill name
        $fakeHome = $this->tempDir . '/override-user';
        $this->useHomeSandbox($fakeHome);
        mkdir($fakeHome . '/.sugar-crush/skills/shared-skill', 0777, true);
        file_put_contents($fakeHome . '/.sugar-crush/skills/shared-skill/SKILL.md', "---\ndescription: User version\n---\n");

        // Set up lib skills with same name - should win (highest priority)
        $libPath = $this->tempDir . '/override-lib';
        mkdir($libPath . '/.sugar-crush/skills/shared-skill', 0777, true);
        file_put_contents($libPath . '/.sugar-crush/skills/shared-skill/SKILL.md', "---\ndescription: Lib version\n---\n");

        try {
            $result = $discovery->discoverAll([$libPath]);

            // Should only have one 'shared-skill' entry, from lib (highest priority)
            $this->assertCount(1, $result);
            $this->assertArrayHasKey('shared-skill', $result);
            $this->assertStringContainsString('override-lib', $result['shared-skill']);
        } finally {
            $this->useHomeSandbox($this->tempDir . '/default-empty-home');
        }
    }

    public function testDiscoverAllWithEmptyLibPaths(): void
    {
        $discovery = new SkillDiscovery();

        $result = $discovery->discoverAll([]);

        $this->assertIsArray($result);
        // Should return project + user skills, no lib skills since none provided
    }

    // -------------------------------------------------------------------------
    // resolveSkillPath()
    // -------------------------------------------------------------------------

    public function testResolveSkillPathReturnsPathWhenSkillExists(): void
    {
        $discovery = new SkillDiscovery();
        $skillPath = $this->tempDir . '/resolve-test/my-skill';
        mkdir($skillPath, 0777, true);
        file_put_contents($skillPath . '/SKILL.md', "---\ndescription: Test\n---\n");

        $result = $discovery->resolveSkillPath('my-skill', [$this->tempDir . '/resolve-test']);

        $this->assertNotNull($result);
        $this->assertSame($skillPath, $result);
    }

    public function testResolveSkillPathReturnsNullWhenNotFound(): void
    {
        $discovery = new SkillDiscovery();

        $result = $discovery->resolveSkillPath('nonexistent', [$this->tempDir]);

        $this->assertNull($result);
    }

    public function testResolveSkillPathSearchesInOrder(): void
    {
        $discovery = new SkillDiscovery();

        // Create skill in first location
        $path1 = $this->tempDir . '/search/path1/exists-skill';
        mkdir($path1, 0777, true);
        file_put_contents($path1 . '/SKILL.md', "---\ndescription: First\n---\n");

        // Create same-named skill in second location (should NOT be found)
        $path2 = $this->tempDir . '/search/path2/exists-skill';
        mkdir($path2, 0777, true);
        file_put_contents($path2 . '/SKILL.md', "---\ndescription: Second\n---\n");

        $result = $discovery->resolveSkillPath('exists-skill', [
            $this->tempDir . '/search/path1',
            $this->tempDir . '/search/path2',
        ]);

        $this->assertNotNull($result);
        $this->assertSame($path1, $result);
    }

    /**
     * A NAME IS NOT A PATH. `resolveSkillPath()` used to concatenate the name
     * onto the base and ask `is_dir()`, so `'../../..'` named a directory
     * outside the search path and was resolved as a skill — the escape this
     * class's own doc-comment says a future caller would inherit. Matching
     * against the contained walk cannot leave the tree: a basename holds no
     * separator.
     */
    public function testResolveSkillPathRefusesANameThatWalksOutOfTheSearchPath(): void
    {
        $discovery = new SkillDiscovery();
        $base = $this->tempDir . '/escape/skills';
        mkdir($base . '/real', 0777, true);
        file_put_contents($base . '/real/SKILL.md', "---\ndescription: Real\n---\n");

        $this->assertNull($discovery->resolveSkillPath('..', [$base]));
        $this->assertNull($discovery->resolveSkillPath('../../..', [$base]));
        $this->assertNull($discovery->resolveSkillPath('real/../../..', [$base]));
        // ...and the ordinary lookup still works, so containment is not
        // "resolves nothing".
        $this->assertSame($base . '/real', $discovery->resolveSkillPath('real', [$base]));
    }

    /**
     * The other half of the same escape: `is_dir()` stats THROUGH a symlink, so
     * a link out of the skills directory answered true and its target came back
     * as the skill's path. The loader's walk refuses it.
     */
    public function testResolveSkillPathRefusesASkillDirectoryThatIsALinkOutOfTheTree(): void
    {
        $discovery = new SkillDiscovery();
        $base = $this->tempDir . '/linked/skills';
        $outside = $this->tempDir . '/outside/elsewhere';
        mkdir($base, 0777, true);
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/SKILL.md', "---\ndescription: Outside\n---\n");

        if (!@symlink($outside, $base . '/escape')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertNull($discovery->resolveSkillPath('escape', [$base]));
    }
}
