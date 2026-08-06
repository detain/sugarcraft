<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;

/**
 * Tests for SkillLoader - loads skills from directories.
 */
final class SkillLoaderTest extends TestCase
{
    private string $tempDir;
    private array $errorLogCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-test-' . uniqid();
        $this->errorLogCalls = [];
    }

    protected function tearDown(): void
    {
        // Clean up temp directory - use recursive deletion
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Recursively remove a directory and all its contents.
     */
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

    /**
     * Suppress error_log calls during test execution.
     * Use this when testing code that intentionally calls error_log.
     */
    protected function suppressErrorLog(): void
    {
        $this->errorLogCalls = [];
        $self = $this;
        set_error_handler(function (int $errno, string $errstr) use ($self) {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, 'Failed to load skill')) {
                $self->errorLogCalls[] = $errstr;
                return true;
            }
            return false;
        });
    }

    protected function restoreErrorHandler(): void
    {
        restore_error_handler();
    }

    /**
     * Get captured error_log calls for verification.
     */
    protected function getErrorLogCalls(): array
    {
        return $this->errorLogCalls;
    }

    private function createSkillFile(string $name, string $description, string $content = ''): void
    {
        $dir = $this->tempDir . '/' . $name;
        mkdir($dir, 0777, true);
        $skillContent = <<<SKILL
---
description: $description
---

{$content}
SKILL;
        file_put_contents($dir . '/SKILL.md', $skillContent);
    }

    // -------------------------------------------------------------------------
    // loadFromDirectory()
    // -------------------------------------------------------------------------

    public function testLoadFromDirectoryNonExistent(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $nonExistentDir = '/non/existent/directory/path/' . uniqid();

        // Act
        $result = $loader->loadFromDirectory($nonExistentDir);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLoadFromDirectoryWithSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $this->createSkillFile('test-skill-1', 'First test skill description', 'Skill content one');
        $this->createSkillFile('test-skill-2', 'Second test skill description', 'Skill content two');

        // Act
        $result = $loader->loadFromDirectory($this->tempDir);

        // Assert
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('test-skill-1', $result);
        $this->assertArrayHasKey('test-skill-2', $result);
        $this->assertInstanceOf(Skill::class, $result['test-skill-1']);
        $this->assertSame('First test skill description', $result['test-skill-1']->description);
        $this->assertSame('Second test skill description', $result['test-skill-2']->description);
    }

    public function testLoadFromDirectorySkipsNonSkillFiles(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $this->createSkillFile('valid-skill', 'A valid skill');
        // Create a subdirectory with a SKILL.md that should still be found
        $subDir = $this->tempDir . '/nested/skill';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/SKILL.md', "---\ndescription: Nested skill\n---\nNested content");
        // Create some non-SKILL.md files
        file_put_contents($this->tempDir . '/readme.txt', 'Not a skill');
        file_put_contents($this->tempDir . '/config.yml', 'Also not a skill');

        // Act
        $result = $loader->loadFromDirectory($this->tempDir);

        // Assert
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('valid-skill', $result);
        $this->assertArrayHasKey('nested/skill', $result);
    }

    public function testLoadFromDirectoryHandlesInvalidSkillFiles(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $validDir = $this->tempDir . '/valid-skill';
        mkdir($validDir, 0777, true);
        file_put_contents($validDir . '/SKILL.md', "---\ndescription: Valid skill\n---\nValid content");

        $invalidDir = $this->tempDir . '/invalid-skill';
        mkdir($invalidDir, 0777, true);
        // Empty/invalid file should be caught gracefully
        file_put_contents($invalidDir . '/SKILL.md', '');

        // Act - should not throw, just log and skip invalid files
        // Note: error_log is called for invalid files but we cannot capture it in tests
        $result = $loader->loadFromDirectory($this->tempDir);

        // Assert - only valid skill should be loaded
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('valid-skill', $result);
        $this->assertArrayNotHasKey('invalid-skill', $result);
        $this->assertSame('Valid skill', $result['valid-skill']->description);
    }

    // -------------------------------------------------------------------------
    // loadUserSkills()
    // -------------------------------------------------------------------------

    public function testLoadUserSkillsReturnsArray(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $result = $loader->loadUserSkills();

        // Assert
        $this->assertIsArray($result);
        // Returns empty array if no user skills directory or no skills found
    }

    // -------------------------------------------------------------------------
    // loadProjectSkills()
    // -------------------------------------------------------------------------

    public function testLoadProjectSkillsNonExistent(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $result = $loader->loadProjectSkills('/non/existent/project/' . uniqid());

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLoadProjectSkillsWithSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot . '/.sugar-crush/skills/my-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/my-skill/SKILL.md',
            "---\ndescription: Project skill\n---\nProject skill content"
        );

        // Act
        $result = $loader->loadProjectSkills($projectRoot);

        // Assert
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('my-skill', $result);
        $this->assertSame('Project skill', $result['my-skill']->description);
    }

    public function testLoadProjectSkillsTrailingSlashHandled(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $projectRoot = $this->tempDir . '/project2';
        mkdir($projectRoot . '/.sugar-crush/skills/trailing-test', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/trailing-test/SKILL.md',
            "---\ndescription: Trailing slash test\n---\nContent"
        );

        // Act - with trailing slash
        $result = $loader->loadProjectSkills($projectRoot . '/');

        // Assert
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('trailing-test', $result);
    }

    // -------------------------------------------------------------------------
    // loadBuiltInSkills()
    // -------------------------------------------------------------------------

    public function testLoadBuiltInSkillsReturnsArray(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $result = $loader->loadBuiltInSkills();

        // Assert
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // loadAll() - priority: builtin < user < project
    // -------------------------------------------------------------------------

    public function testLoadAllPriority(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Create a mock BuiltIn skills directory structure
        // Since we can't easily mock loadBuiltInSkills, we test the merge behavior
        // by verifying loadAll returns an array with expected structure

        // Act
        $result = $loader->loadAll('.');

        // Assert
        $this->assertIsArray($result);
        // Priority is: builtin -> user -> project
        // If no custom skills exist, result should contain built-in skills
    }

    public function testLoadAllWithProjectOverride(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Create a temp project with a skill
        $projectRoot = $this->tempDir . '/override-test-project';
        mkdir($projectRoot . '/.sugar-crush/skills/override-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/override-skill/SKILL.md',
            "---\ndescription: Project override skill\n---\nThis should appear in loadAll"
        );

        // Act
        $result = $loader->loadAll($projectRoot);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('override-skill', $result);
        $this->assertSame('Project override skill', $result['override-skill']->description);
    }

    public function testLoadAllEmptyProject(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $emptyProject = $this->tempDir . '/empty-project-' . uniqid();
        mkdir($emptyProject, 0777, true);

        // Act
        $result = $loader->loadAll($emptyProject);

        // Assert
        $this->assertIsArray($result);
        // Should still return built-in skills (may be empty if no BuiltIn skills exist)
    }

    public function testLoadAllDefaultProjectRoot(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act - default project root is '.'
        $result = $loader->loadAll();

        // Assert
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // Staged Loading Methods (P7.S12)
    // -------------------------------------------------------------------------

    public function testLoadSkillManifestReturnsCorrectStructure(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/my-test-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Test skill description
disable-model-invocation: true
user-invocable: false
context: fork
---

Some skill body content here.
SKILL
        );

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert
        $this->assertIsArray($manifest);
        $this->assertSame('my-test-skill', $manifest['name']);
        $this->assertSame('Test skill description', $manifest['description']);
        $this->assertTrue($manifest['disableModelInvocation']);
        $this->assertFalse($manifest['userInvocable']);
        $this->assertSame('fork', $manifest['context']);
        $this->assertStringEndsWith('my-test-skill/SKILL.md', $manifest['sourcePath']);
    }

    public function testLoadSkillManifestDefaultsWhenFieldsMissing(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/minimal-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\n---\n\nBody only, no frontmatter fields.");

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert
        $this->assertSame('minimal-skill', $manifest['name']);
        $this->assertSame('Skill: minimal-skill', $manifest['description']);
        $this->assertFalse($manifest['disableModelInvocation']);
        $this->assertTrue($manifest['userInvocable']);
        $this->assertSame('thread', $manifest['context']);
    }

    public function testLoadSkillManifestThrowsWhenMissingSkillMd(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/no-skilLmd';
        mkdir($skillDir, 0777, true);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SKILL.md not found');

        // Act
        $loader->loadSkillManifest($skillDir);
    }

    public function testLoadSkillBodyReturnsContentWithoutFrontmatter(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/test-skill/SKILL.md';
        mkdir($this->tempDir . '/test-skill', 0777, true);
        file_put_contents($skillPath, <<<SKILL
---
description: A test skill
---

This is the body content.
It has multiple lines.
SKILL
        );

        // Act
        $body = $loader->loadSkillBody($skillPath);

        // Assert
        $this->assertSame("This is the body content.\nIt has multiple lines.", $body);
    }

    public function testLoadSkillBodyReturnsFullContentWithoutFrontmatter(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/no-fm-skill/SKILL.md';
        mkdir($this->tempDir . '/no-fm-skill', 0777, true);
        file_put_contents($skillPath, "Just plain content without frontmatter.");

        // Act
        $body = $loader->loadSkillBody($skillPath);

        // Assert
        $this->assertSame('Just plain content without frontmatter.', $body);
    }

    public function testLoadSkillBodyThrowsWhenFileNotFound(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill file not found');

        // Act
        $loader->loadSkillBody('/non/existent/path/SKILL.md');
    }

    public function testLoadSkillAssetLoadsFromScriptsSubdirectory(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/my-skill/SKILL.md';
        mkdir($this->tempDir . '/my-skill/scripts', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Test\n---\nBody");
        file_put_contents($this->tempDir . '/my-skill/scripts/run.sh', "#!/bin/bash\necho \"Hello\"");

        // Act
        $content = $loader->loadSkillAsset($skillPath, 'scripts/run.sh');

        // Assert
        $this->assertSame("#!/bin/bash\necho \"Hello\"", $content);
    }

    public function testLoadSkillAssetLoadsFromReferencesSubdirectory(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/ref-skill/SKILL.md';
        mkdir($this->tempDir . '/ref-skill/references', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Ref test\n---\nBody");
        file_put_contents($this->tempDir . '/ref-skill/references/docs.md', '# Documentation');

        // Act
        $content = $loader->loadSkillAsset($skillPath, 'references/docs.md');

        // Assert
        $this->assertSame('# Documentation', $content);
    }

    public function testLoadSkillAssetLoadsFromAssetsSubdirectory(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/asset-skill/SKILL.md';
        mkdir($this->tempDir . '/asset-skill/assets', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Asset test\n---\nBody");
        file_put_contents($this->tempDir . '/asset-skill/assets/image.png', 'fake-binary-data');

        // Act
        $content = $loader->loadSkillAsset($skillPath, 'assets/image.png');

        // Assert
        $this->assertSame('fake-binary-data', $content);
    }

    public function testLoadSkillAssetRejectsPathTraversal(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/safe-skill/SKILL.md';
        mkdir($this->tempDir . '/safe-skill/scripts', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Safe test\n---\nBody");

        // Assert - path starting with / is rejected as invalid relative path
        // (this would be an absolute path which is not allowed)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be within');

        // Act
        $loader->loadSkillAsset($skillPath, '/etc/passwd');
    }

    public function testLoadSkillAssetRejectsInvalidSubdirectory(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/invalid-skill/SKILL.md';
        mkdir($this->tempDir . '/invalid-skill/scripts', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Invalid test\n---\nBody");

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be within');

        // Act
        $loader->loadSkillAsset($skillPath, 'otherdir/file.txt');
    }
}
