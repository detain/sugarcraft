<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Tests for SkillLoader - loads skills from directories.
 *
 * HOME IS SANDBOXED FOR THE WHOLE CLASS. Several tests here drive
 * {@see SkillLoader::loadAll()} / {@see SkillLoader::loadAllManifests()},
 * which walk `~/.sugar-crush/skills` — the DEVELOPER'S, without this. That is
 * green today only because those assertions are shape-only, and it is the
 * tracker #52/#53 pattern: a suite may not depend on what the person running
 * it happens to have installed. It got sharper once the walk began following
 * symlinks, since a link in a real skills tree would drag the unit suite into
 * whatever it points at.
 */
final class SkillLoaderTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private array $errorLogCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-test-' . uniqid((string) getmypid(), true);
        $this->errorLogCalls = [];
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
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
        $nonExistentDir = '/non/existent/directory/path/' . uniqid((string) getmypid(), true);

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
        $result = $loader->loadProjectSkills('/non/existent/project/' . uniqid((string) getmypid(), true));

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
        $emptyProject = $this->tempDir . '/empty-project-' . uniqid((string) getmypid(), true);
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
        $this->assertSame([], $manifest['paths']);
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

    // -------------------------------------------------------------------------
    // Flag wiring acceptance criteria (P7.S13 → P7.S14)
    // -------------------------------------------------------------------------

    /**
     * Validates that startup loading (loadSkillManifest) only materialises
     * name, description, and the three flag fields — no body content is
     * loaded at this stage.  This is the contract that P7.S14 consumers
     * (auto-trigger logic, command-surface filtering, context-fork dispatch)
     * depend on.
     *
     * Mirrors charmbracelet/<repo>.loadSkillManifest
     */
    public function testOnlyNameAndDescriptionLoadedAtStartup(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/startup-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Full skill with many fields
disable-model-invocation: true
user-invocable: false
context: fork
allowed-tools: ["tool-a"]
disallowed-tools: ["tool-b"]
model: gpt-4
effort: high
paths:
  - extra/path
---

This is the body content that must NOT be in the manifest.
SKILL
        );

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert – name, description and three flag fields are present
        $this->assertSame('startup-skill', $manifest['name']);
        $this->assertSame('Full skill with many fields', $manifest['description']);
        $this->assertTrue($manifest['disableModelInvocation']);
        $this->assertFalse($manifest['userInvocable']);
        $this->assertSame('fork', $manifest['context']);

        // Assert – sourcePath is populated
        $this->assertStringEndsWith('startup-skill/SKILL.md', $manifest['sourcePath']);

        // Assert – body content is NOT part of the manifest (staged loading contract).
        // `paths` IS present -- it's frontmatter, not body, so surfacing it costs
        // nothing extra and is required for path-based auto-scoping (getForPaths())
        // to work when a skill is registered via the lazy manifest path.
        $this->assertArrayNotHasKey('content', $manifest);
        $this->assertSame(['extra/path'], $manifest['paths']);
        $this->assertArrayNotHasKey('allowedTools', $manifest);
        $this->assertArrayNotHasKey('disallowedTools', $manifest);
        $this->assertArrayNotHasKey('model', $manifest);
        $this->assertArrayNotHasKey('effort', $manifest);
    }

    /**
     * When disable-model-invocation is true the manifest carries
     * disableModelInvocation:true so that P7.S14 auto-trigger logic can
     * skip firing this skill without an explicit user invocation.
     *
     * Mirrors charmbracelet/<repo>.loadSkillManifest
     */
    public function testDisableModelInvocationSkipsAutoTrigger(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/noauto-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Do not auto-trigger me
disable-model-invocation: true
---

Body content.
SKILL
        );

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert – flag is correctly propagated so downstream can honour it
        $this->assertTrue($manifest['disableModelInvocation']);
    }

    /**
     * When user-invocable is false the manifest carries userInvocable:false so
     * that P7.S14 command-surface filtering can hide the skill from users.
     *
     * Mirrors charmbracelet/<repo>.loadSkillManifest
     */
    public function testUserInvocableFalseHidesFromCommandSurface(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/hidden-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Internal skill, not for direct user invocation
user-invocable: false
---

Body content.
SKILL
        );

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert – flag is correctly propagated so downstream can honour it
        $this->assertFalse($manifest['userInvocable']);
    }

    /**
     * When context is set to 'fork' the manifest carries context:fork so that
     * P7.S14 context-fork dispatch can spawn an isolated sub-agent with no
     * access to the calling conversation.
     *
     * Mirrors charmbracelet/<repo>.loadSkillManifest
     */
    public function testContextForkRunsInIsolatedSubAgent(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $skillDir = $this->tempDir . '/fork-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', <<<SKILL
---
description: Runs in isolated context
context: fork
---

Body content.
SKILL
        );

        // Act
        $manifest = $loader->loadSkillManifest($skillDir);

        // Assert – flag is correctly propagated so downstream can honour it
        $this->assertSame('fork', $manifest['context']);
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

    /**
     * The CONTAINMENT arm of loadSkillAsset(), which was unpinned.
     *
     * The two traversal tests above are both settled by the earlier
     * `scripts|references|assets` first-component gate — `/etc/passwd` and
     * `otherdir/file.txt` never reach the containment check, and both assert on
     * its "must be within" message rather than the containment one. MEASURED:
     * deleting the containment check entirely left `tests/Skills` at
     * `OK (311 tests, 793 assertions)`. So the guard on a `file_get_contents()`
     * of a user-controlled path had no test at all, in the method that turned out
     * to be a SECOND hand-spelled copy of the shared predicate.
     *
     * `scripts/../..` keeps `scripts` as the first component, so it passes the
     * subdirectory gate and is decided by containment — which is the only way to
     * reach that branch.
     */
    public function testLoadSkillAssetRejectsAnAssetResolvingOutOfTheSkillDirectory(): void
    {
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/escape-skill/SKILL.md';
        mkdir($this->tempDir . '/escape-skill/scripts', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Escape test\n---\nBody");
        file_put_contents($this->tempDir . '/outside-secret.txt', 'SENTINEL-ASSET-SECRET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes skill directory');

        $loader->loadSkillAsset($skillPath, 'scripts/../../outside-secret.txt');
    }

    /**
     * And the same refusal for the spelling a repository can actually commit: a
     * SYMLINK inside `scripts/` whose target is outside the skill.
     *
     * Distinct from the `..` case because there is no `..` in the relative path
     * at all — every string check passes, and only resolving both sides catches
     * it.
     */
    public function testLoadSkillAssetRejectsASymlinkedAssetPointingOutOfTheSkill(): void
    {
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/linked-skill/SKILL.md';
        mkdir($this->tempDir . '/linked-skill/scripts', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Link test\n---\nBody");
        file_put_contents($this->tempDir . '/outside-linked.sh', 'SENTINEL-ASSET-SECRET');
        $this->assertTrue(symlink(
            $this->tempDir . '/outside-linked.sh',
            $this->tempDir . '/linked-skill/scripts/run.sh',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes skill directory');

        $loader->loadSkillAsset($skillPath, 'scripts/run.sh');
    }

    /**
     * The control: an asset reached through a link that stays INSIDE the skill is
     * still read, so the refusals above are not "refuse every symlink".
     */
    public function testLoadSkillAssetFollowsALinkThatStaysInsideTheSkill(): void
    {
        $loader = new SkillLoader();
        $skillPath = $this->tempDir . '/inside-skill/SKILL.md';
        mkdir($this->tempDir . '/inside-skill/scripts', 0777, true);
        mkdir($this->tempDir . '/inside-skill/shared', 0777, true);
        file_put_contents($skillPath, "---\ndescription: Inside test\n---\nBody");
        file_put_contents($this->tempDir . '/inside-skill/shared/real.sh', 'INSIDE-ASSET');
        $this->assertTrue(symlink(
            $this->tempDir . '/inside-skill/shared/real.sh',
            $this->tempDir . '/inside-skill/scripts/run.sh',
        ));

        $this->assertSame('INSIDE-ASSET', $loader->loadSkillAsset($skillPath, 'scripts/run.sh'));
    }

    // -------------------------------------------------------------------------
    // loadManifestsFromDirectory() / loadAllManifests() -- lazy progressive
    // loading (crush_feat.md section 7.E3)
    // -------------------------------------------------------------------------

    public function testLoadManifestsFromDirectoryNonExistent(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $nonExistentDir = '/non/existent/directory/path/' . uniqid((string) getmypid(), true);

        // Act
        $result = $loader->loadManifestsFromDirectory($nonExistentDir);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLoadManifestsFromDirectoryReturnsManifestsNotFullBody(): void
    {
        // Arrange -- a skill with a large, distinctive body. The old
        // eager path (loadFromDirectory()/Skill::fromFile()) reads this
        // whole body into memory at startup; the manifest-only path must
        // not, so the marker string must never surface in the manifest.
        $loader = new SkillLoader();
        $bodyMarker = 'BODY_SHOULD_NOT_BE_READ_AT_STARTUP_' . uniqid((string) getmypid(), true);
        $this->createSkillFile('lazy-skill', 'A lazily-loaded skill', $bodyMarker);

        // Act
        $result = $loader->loadManifestsFromDirectory($this->tempDir);

        // Assert -- manifest shape only, no body/content field at all
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('lazy-skill', $result);
        $manifest = $result['lazy-skill'];
        $this->assertSame('lazy-skill', $manifest['name']);
        $this->assertSame('A lazily-loaded skill', $manifest['description']);
        $this->assertArrayNotHasKey('content', $manifest);
        $this->assertStringNotContainsString($bodyMarker, json_encode($manifest));
    }

    public function testLoadManifestsFromDirectoryUsesNestedRelativeNaming(): void
    {
        // Arrange -- mirrors loadFromDirectory()'s own nested-naming test:
        // a skill more than one level deep is keyed by its path relative
        // to the base dir, not just its own directory's basename.
        $loader = new SkillLoader();
        $this->createSkillFile('top-level-skill', 'Top level');
        $subDir = $this->tempDir . '/nested/skill';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/SKILL.md', "---\ndescription: Nested skill\n---\nNested content");

        // Act
        $result = $loader->loadManifestsFromDirectory($this->tempDir);

        // Assert
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('top-level-skill', $result);
        $this->assertArrayHasKey('nested/skill', $result);
        $this->assertSame('nested/skill', $result['nested/skill']['name']);
    }

    public function testLoadManifestsFromDirectoryHandlesMissingFrontmatterGracefully(): void
    {
        // Arrange -- unlike Skill::fromFile() (which requires frontmatter
        // and throws), loadSkillManifest() tolerates a SKILL.md with no
        // frontmatter block and falls back to defaults, so the directory
        // walker's try/catch must not swallow this as if it were invalid.
        $loader = new SkillLoader();
        $this->createSkillFile('valid-skill', 'A valid skill');
        $noFrontmatterDir = $this->tempDir . '/no-frontmatter-skill';
        mkdir($noFrontmatterDir, 0777, true);
        file_put_contents($noFrontmatterDir . '/SKILL.md', 'Just plain content, no frontmatter at all.');

        // Act
        $result = $loader->loadManifestsFromDirectory($this->tempDir);

        // Assert -- both skills surface; the frontmatter-less one gets defaults
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('valid-skill', $result);
        $this->assertArrayHasKey('no-frontmatter-skill', $result);
        $this->assertSame('Skill: no-frontmatter-skill', $result['no-frontmatter-skill']['description']);
    }

    public function testLoadAllManifestsReturnsBuiltInManifestsWithoutBody(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $result = $loader->loadAllManifests('.');

        // Assert -- built-in skills exist and are manifest-shaped
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        foreach ($result as $manifest) {
            $this->assertArrayHasKey('name', $manifest);
            $this->assertArrayHasKey('description', $manifest);
            $this->assertArrayHasKey('sourcePath', $manifest);
            $this->assertArrayNotHasKey('content', $manifest);
        }
    }

    public function testLoadAllManifestsWithProjectOverride(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $projectRoot = $this->tempDir . '/manifest-override-project';
        $bodyMarker = 'PROJECT_MANIFEST_BODY_MARKER_' . uniqid((string) getmypid(), true);
        mkdir($projectRoot . '/.sugar-crush/skills/override-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/override-skill/SKILL.md',
            "---\ndescription: Project override manifest skill\n---\n\n{$bodyMarker}"
        );

        // Act
        $result = $loader->loadAllManifests($projectRoot);

        // Assert
        $this->assertArrayHasKey('override-skill', $result);
        $this->assertSame('Project override manifest skill', $result['override-skill']['description']);
        $this->assertStringNotContainsString($bodyMarker, json_encode($result['override-skill']));
    }

    public function testLoadAllManifestsEmptyProject(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $emptyProject = $this->tempDir . '/empty-manifest-project-' . uniqid((string) getmypid(), true);
        mkdir($emptyProject, 0777, true);

        // Act
        $result = $loader->loadAllManifests($emptyProject);

        // Assert
        $this->assertIsArray($result);
    }

    // =========================================================================
    // Symlinked skill directories, and the diagnostics for the ones that fail
    // =========================================================================

    /**
     * A SKILL DIRECTORY THAT IS A SYMLINK IS STILL A SKILL. The walk used to
     * be a RecursiveDirectoryIterator WITHOUT FOLLOW_SYMLINKS, which skips
     * them silently -- and linking skills in from a shared checkout is how the
     * trees this loader now imports from are commonly laid out.
     */
    public function testLoadFromDirectoryFollowsASymlinkedSkillDirectory(): void
    {
        $loader = new SkillLoader();
        $real = $this->tempDir . '/real/linked-skill';
        mkdir($real, 0777, true);
        file_put_contents($real . '/SKILL.md', "---\ndescription: Behind a symlink\n---\n\nBody.");

        $skills = $this->tempDir . '/skills';
        mkdir($skills, 0777, true);
        if (!@symlink($real, $skills . '/linked-skill')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        // $ownedBy is what makes a link OUT of the skills tree legitimate:
        // this is the real `~/.config/opencode/skills/x -> ~/.config/skillshare/x`
        // layout, where both ends belong to the user. Without it the SAME link
        // is an escape -- see the containment tests below.
        $result = $loader->loadFromDirectory($skills, $this->tempDir);

        $this->assertArrayHasKey('linked-skill', $result);
        $this->assertSame('Behind a symlink', $result['linked-skill']->description);
    }

    /** The manifest-only walker shares the walk, so it shares the fix. */
    public function testLoadManifestsFromDirectoryFollowsASymlinkedSkillDirectory(): void
    {
        $loader = new SkillLoader();
        $real = $this->tempDir . '/real-manifest/linked-skill';
        mkdir($real, 0777, true);
        file_put_contents($real . '/SKILL.md', "---\ndescription: Behind a symlink\n---\n\nBody.");

        $skills = $this->tempDir . '/manifest-skills';
        mkdir($skills, 0777, true);
        if (!@symlink($real, $skills . '/linked-skill')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $result = $loader->loadManifestsFromDirectory($skills, $this->tempDir);

        $this->assertArrayHasKey('linked-skill', $result);
        $this->assertSame('Behind a symlink', $result['linked-skill']['description']);
    }

    /**
     * Following symlinks reintroduces cycles a plain walk could not have:
     * `skills/loop -> ..` is a tree with no bottom. The walk must terminate
     * and still return the skills it found -- this test HANGS rather than
     * fails if the realpath visited-set is dropped.
     */
    public function testLoadFromDirectorySurvivesASymlinkLoop(): void
    {
        $loader = new SkillLoader();
        $skills = $this->tempDir . '/looping';
        mkdir($skills . '/real-skill', 0777, true);
        file_put_contents($skills . '/real-skill/SKILL.md', "---\ndescription: Real\n---\n\nBody.");

        if (!@symlink($skills, $skills . '/real-skill/loop')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $result = $loader->loadFromDirectory($skills);

        // EXACTLY ONE KEY. `assertArrayHasKey` alone passes with the visited
        // set dropped -- the loop then also finds
        // `real-skill/loop/real-skill/SKILL.md`, whose relative-path key is
        // `real-skill/loop/real-skill`, so the extra registration is what the
        // guard is actually preventing and what this asserts. The depth bound
        // is what stops the same mutation HANGING instead of failing.
        $this->assertSame(['real-skill'], array_keys($result));
    }

    /**
     * The visited set is keyed by REAL path, not by the path walked. Two
     * spellings of one directory are different strings, so keying by the
     * walked path would neither terminate a loop nor collapse an alias.
     */
    public function testADirectoryReachableTwoWaysIsWalkedOnce(): void
    {
        $loader = new SkillLoader();
        $base = $this->tempDir . '/aliased';
        mkdir($base . '/real/deep-skill', 0777, true);
        file_put_contents($base . '/real/deep-skill/SKILL.md', "---\ndescription: Once\n---\n\nBody.");

        $skills = $base . '/skills';
        mkdir($skills, 0777, true);
        // BOTH names are links, so neither is the spelling that happens to
        // equal its own realpath -- keying the visited set by the WALKED path
        // then registers the skill twice no matter which one readdir yields
        // first, which is what makes this test independent of scan order.
        if (!@symlink($base . '/real', $skills . '/a') || !@symlink($base . '/real', $skills . '/b')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $result = $loader->loadFromDirectory($skills, $base);

        $this->assertCount(1, $result);
    }

    /**
     * THE ESCAPE. A cloned repository's `.claude/skills/escape -> $HOME` made
     * the walk register a skill whose BODY was a file from the user's home
     * directory -- prompt context the model reads, from a file nowhere near a
     * skills tree, with no opt-in anywhere. Git stores symlinks, so this is
     * attacker-controlled content arriving with `git clone`.
     */
    public function testASymlinkOutOfAProjectSkillTreeIsRefused(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $secrets = $this->tempDir . '/elsewhere/private';
        mkdir($secrets, 0777, true);
        file_put_contents($secrets . '/SKILL.md', "---\ndescription: leaked\n---\n\nAPI_KEY=hunter2");

        $skills = $this->tempDir . '/project/.claude/skills';
        mkdir($skills, 0777, true);
        if (!@symlink($this->tempDir . '/elsewhere', $skills . '/escape')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $result = $loader->loadFromDirectory($skills);

        $this->assertSame([], $result);
        $this->assertStringNotContainsString('hunter2', json_encode($result) ?: '');
        $this->assertArrayHasKey($skills . '/escape', $loader->skipped());
    }

    /** A SKILL.md that is itself a link out of the tree is refused too. */
    public function testASymlinkedSkillFileOutOfTheTreeIsRefused(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/secret.md', "---\ndescription: leaked\n---\n\nAPI_KEY=hunter2");

        $skills = $this->tempDir . '/filelink/.claude/skills/pretend';
        mkdir($skills, 0777, true);
        if (!@symlink($outside . '/secret.md', $skills . '/SKILL.md')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], $loader->loadFromDirectory($this->tempDir . '/filelink/.claude/skills'));
    }

    /**
     * The containment boundary is an EXACT prefix with a separator, never a
     * bare string prefix: `/a/b` may not be read as containing `/a/bevil`.
     */
    public function testASiblingDirectorySharingAPrefixIsNotContained(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        // The sibling's path is the boundary's path plus more CHARACTERS but
        // not plus a PATH COMPONENT -- `/w/skills-root-evil` against a
        // boundary of `/w/skills-root`. A bare `str_starts_with` reads that as
        // contained, which is the mutation this fixture exists to catch.
        $sibling = $this->tempDir . '/skills-root-evil/leak';
        mkdir($sibling, 0777, true);
        file_put_contents($sibling . '/SKILL.md', "---\ndescription: leaked\n---\n\nBody.");

        $skills = $this->tempDir . '/skills-root';
        mkdir($skills, 0777, true);
        if (!@symlink($this->tempDir . '/skills-root-evil', $skills . '/sneak')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], $loader->loadFromDirectory($skills));
    }

    /**
     * $ownedBy is what keeps the real user layout working -- the link leaves
     * the skills tree but stays inside the directory the same person owns.
     */
    public function testALinkInsideTheOwningTreeIsStillFollowed(): void
    {
        $loader = new SkillLoader();
        $shared = $this->tempDir . '/owner/shared/db-query';
        mkdir($shared, 0777, true);
        file_put_contents($shared . '/SKILL.md', "---\ndescription: Shared\n---\n\nBody.");

        $skills = $this->tempDir . '/owner/skills';
        mkdir($skills, 0777, true);
        if (!@symlink($shared, $skills . '/db-query')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], $loader->loadFromDirectory($skills));
        $this->assertArrayHasKey(
            'db-query',
            $loader->loadFromDirectory($skills, $this->tempDir . '/owner'),
        );
    }

    /**
     * DEPTH IS BOUNDED. A link can graft a tree of any depth on, and the walk
     * used to descend all of it -- `-> /usr/share` cost 8.29s on one measured
     * launch. A real skills tree is two or three levels deep.
     */
    public function testTheWalkStopsDescendingPastTheDepthBound(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $skills = $this->tempDir . '/deep';
        $deep = $skills . '/a/b/c/d/e/f/g/too-deep';
        mkdir($deep, 0777, true);
        file_put_contents($deep . '/SKILL.md', "---\ndescription: Too deep\n---\n\nBody.");

        $shallow = $skills . '/reachable';
        mkdir($shallow, 0777, true);
        file_put_contents($shallow . '/SKILL.md', "---\ndescription: Fine\n---\n\nBody.");

        $result = $loader->loadFromDirectory($skills);

        $this->assertArrayHasKey('reachable', $result);
        $this->assertArrayNotHasKey('a/b/c/d/e/f/g/too-deep', $result);
        $this->assertNotSame([], $loader->skipped());
    }

    /**
     * BREADTH IS BOUNDED TOO. A `skills/x -> /usr/share` link cost 8.29s on
     * one measured launch and `-> /` is unbounded; a real skills tree is tens
     * of directories, so the cap is only ever reached by something that is not
     * one.
     */
    public function testTheWalkStopsAfterTheDirectoryBound(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $skills = $this->tempDir . '/wide';
        $bound = (new \ReflectionClassConstant(SkillLoader::class, 'MAX_DIRECTORIES'))->getValue();
        $this->assertIsInt($bound);

        mkdir($skills, 0777, true);
        for ($i = 0; $i <= $bound + 1; $i++) {
            mkdir($skills . '/d' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 0777);
        }

        $walk = new \ReflectionMethod(SkillLoader::class, 'skillFilesIn');
        $walk->invoke($loader, $skills, null);

        $this->assertNotSame([], $loader->skipped(), 'the walk must stop and say why');
        $this->assertStringContainsString(
            'directories',
            implode(' ', $loader->skipped()),
        );
    }

    /**
     * DISCOVERY ORDER IS A CONTRACT. Two SKILL.md files competing for one
     * registry key must resolve the same way on every machine, and readdir
     * order is not sorted.
     */
    public function testTheWalkReturnsSkillFilesInAscendingOrder(): void
    {
        $loader = new SkillLoader();
        $skills = $this->tempDir . '/ordered';
        foreach (['zulu', 'alpha', 'mike'] as $name) {
            mkdir($skills . '/' . $name, 0777, true);
            file_put_contents($skills . '/' . $name . '/SKILL.md', "---\ndescription: {$name}\n---\n\nBody.");
        }

        $walk = new \ReflectionMethod(SkillLoader::class, 'skillFilesIn');

        $this->assertSame(
            [
                $skills . '/alpha/SKILL.md',
                $skills . '/mike/SKILL.md',
                $skills . '/zulu/SKILL.md',
            ],
            $walk->invoke($loader, $skills, null),
        );
    }

    /** A path that exists but is not a directory is nothing to walk. */
    public function testAFilePassedAsASkillDirectoryYieldsNothing(): void
    {
        $loader = new SkillLoader();
        $file = $this->tempDir . '/not-a-directory';
        file_put_contents($file, 'x');

        $this->assertSame([], $loader->loadFromDirectory($file));
        // ...and it is NOTHING TO WALK rather than a failure to report: without
        // the guard the iterator is constructed on a regular file, throws, and
        // the skip log fills with entries about paths that were never a skill
        // tree in the first place.
        $this->assertSame([], $loader->skipped());
    }

    /**
     * F11: the skill trees read `getenv('HOME')`, NOT `$_SERVER['HOME']`.
     * While both halves of this codebase read `$_SERVER` they were wrong
     * together; the moment one moved, a `putenv('HOME')` pointed
     * `~/.claude/skills` and `~/.claude/agents` at two different homes.
     */
    public function testUserSkillsFollowTheEnvHomeNotTheServerSuperglobal(): void
    {
        $envHome = $this->tempDir . '/env-home';
        mkdir($envHome . '/.sugar-crush/skills/from-env', 0777, true);
        file_put_contents(
            $envHome . '/.sugar-crush/skills/from-env/SKILL.md',
            "---\ndescription: env\n---\n\nBody.",
        );

        $decoyHome = $this->tempDir . '/server-home';
        mkdir($decoyHome . '/.sugar-crush/skills/from-server', 0777, true);
        file_put_contents(
            $decoyHome . '/.sugar-crush/skills/from-server/SKILL.md',
            "---\ndescription: server\n---\n\nBody.",
        );

        $this->useHomeSandbox($envHome);
        $_SERVER['HOME'] = $decoyHome;

        $result = (new SkillLoader())->loadUserSkills();

        $this->assertArrayHasKey('from-env', $result);
        $this->assertArrayNotHasKey('from-server', $result);
    }

    /**
     * AN UNPARSEABLE SKILL IS SKIPPED QUIETLY AND REMEMBERED. These files
     * belong to other tools -- ~/.claude/skills is walked on every launch now
     * -- so "fix your SKILL.md" is not advice this CLI's user can act on, and
     * an error_log() line lands in the middle of a TUI frame. The diagnostic
     * still has to exist, so it moves to skipped().
     */
    public function testAnUnparseableSkillIsRecordedRatherThanLogged(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $skills = $this->tempDir . '/broken-skills';
        mkdir($skills . '/broken', 0777, true);
        file_put_contents($skills . '/broken/SKILL.md', "---\n: : not: yaml: [\n---\n\nBody.");

        $result = $loader->loadFromDirectory($skills);

        $this->assertSame([], $result);
        $this->assertArrayHasKey($skills . '/broken/SKILL.md', $loader->skipped());
    }

    /** A good skill beside a broken one is still loaded. */
    public function testABrokenSkillDoesNotStopTheOnesBesideIt(): void
    {
        $loader = new SkillLoader(reportSkips: false);
        $skills = $this->tempDir . '/mixed-skills';
        mkdir($skills . '/broken', 0777, true);
        file_put_contents($skills . '/broken/SKILL.md', "---\n: : not: yaml: [\n---\n\nBody.");
        mkdir($skills . '/good', 0777, true);
        file_put_contents($skills . '/good/SKILL.md', "---\ndescription: Fine\n---\n\nBody.");

        $result = $loader->loadFromDirectory($skills);

        $this->assertArrayHasKey('good', $result);
        $this->assertCount(1, $loader->skipped());
    }

    /**
     * The stderr half, asserted rather than assumed: `error_log()` in CLI goes
     * to stderr, so a launch that walks another tool's skills used to print
     * one line per unparseable file INTO THE TUI'S FRAME, every time. The
     * default loader must write nothing at all.
     */
    public function testTheDefaultLoaderWritesNothingAboutASkillItSkipped(): void
    {
        $log = $this->tempDir . '/error.log';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $skills = $this->brokenSkillTree('quiet');

        $previousLog = ini_get('error_log');
        $previousEnv = getenv(SkillLoader::DEBUG_SKIPS_ENV);
        ini_set('error_log', $log);
        putenv(SkillLoader::DEBUG_SKIPS_ENV);

        try {
            (new SkillLoader())->loadFromDirectory($skills);

            $this->assertSame('', is_file($log) ? (string) file_get_contents($log) : '');
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            $previousEnv === false
                ? putenv(SkillLoader::DEBUG_SKIPS_ENV)
                : putenv(SkillLoader::DEBUG_SKIPS_ENV . '=' . $previousEnv);
        }
    }

    /** ...and the diagnostic comes back for whoever is actually debugging. */
    public function testTheDebugEnvVarPutsTheSkipsBackOnTheLog(): void
    {
        $log = $this->tempDir . '/error-debug.log';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $skills = $this->brokenSkillTree('loud');

        $previousLog = ini_get('error_log');
        $previousEnv = getenv(SkillLoader::DEBUG_SKIPS_ENV);
        ini_set('error_log', $log);
        putenv(SkillLoader::DEBUG_SKIPS_ENV . '=1');

        try {
            (new SkillLoader())->loadFromDirectory($skills);

            $this->assertStringContainsString('Failed to load skill', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            $previousEnv === false
                ? putenv(SkillLoader::DEBUG_SKIPS_ENV)
                : putenv(SkillLoader::DEBUG_SKIPS_ENV . '=' . $previousEnv);
        }
    }

    /** A skills directory holding one SKILL.md that cannot be parsed. */
    private function brokenSkillTree(string $name): string
    {
        $skills = $this->tempDir . '/' . $name . '-skills';
        mkdir($skills . '/broken', 0777, true);
        file_put_contents($skills . '/broken/SKILL.md', "---\n: : not: yaml: [\n---\n\nBody.");

        return $skills;
    }
}
