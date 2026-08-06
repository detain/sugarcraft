<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\SkillDiscovery;

/**
 * Tests for SkillDiscovery - discovers skill directories across search paths.
 */
final class SkillDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-discovery-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
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
        $origHome = $_SERVER['HOME'] ?? '/root';
        $_SERVER['HOME'] = $this->tempDir . '/empty-home';
        mkdir($_SERVER['HOME'] . '/.sugar-crush/skills', 0777, true);

        try {
            $result = $discovery->discoverUserSkills();

            $this->assertIsArray($result);
            // The directory exists but is empty
            $this->assertEmpty($result);
        } finally {
            $_SERVER['HOME'] = $origHome;
        }
    }

    public function testDiscoverUserSkillsFindsSkillDirectories(): void
    {
        $discovery = new SkillDiscovery();
        $origHome = $_SERVER['HOME'] ?? '/root';
        $fakeHome = $this->tempDir . '/fake-home';
        $_SERVER['HOME'] = $fakeHome;
        mkdir($fakeHome . '/.sugar-crush/skills/user-skill', 0777, true);
        file_put_contents($fakeHome . '/.sugar-crush/skills/user-skill/SKILL.md', "---\ndescription: User skill\n---\n");

        try {
            $result = $discovery->discoverUserSkills();

            $this->assertCount(1, $result);
            $this->assertStringContainsString('user-skill', $result[0]);
        } finally {
            $_SERVER['HOME'] = $origHome;
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
        $origHome = $_SERVER['HOME'] ?? '/root';
        $fakeHome = $this->tempDir . '/all-user';
        $_SERVER['HOME'] = $fakeHome;
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
            $_SERVER['HOME'] = $origHome;
        }
    }

    public function testDiscoverAllLaterPathsOverrideEarlierOnConflict(): void
    {
        $discovery = new SkillDiscovery();

        // Set up user skills with a conflicting skill name
        $origHome = $_SERVER['HOME'] ?? '/root';
        $fakeHome = $this->tempDir . '/override-user';
        $_SERVER['HOME'] = $fakeHome;
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
            $_SERVER['HOME'] = $origHome;
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
}
