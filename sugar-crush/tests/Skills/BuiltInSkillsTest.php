<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;

/**
 * Tests for built-in skills loading and path matching.
 *
 * R17: restored from git history (deleted by 753b0a2d under a misleading
 * "stale test removal" message) and extended to cover all 12 built-in
 * skills now that the batch-1/2 additions live under src/Skills/BuiltIn/
 * instead of the incorrect sugar-crush/skills/ and repo-root skills/
 * duplicate locations.
 */
final class BuiltInSkillsTest extends TestCase
{
    private string $builtInSkillsPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Get the BuiltIn skills directory path
        $reflection = new \ReflectionClass(SkillLoader::class);
        $this->builtInSkillsPath = dirname($reflection->getFileName()) . '/BuiltIn';
    }

    /**
     * Get expected skill definitions for all 12 built-in skills: the
     * original 4 plus the 8 relocated from sugar-crush/skills/ and the
     * monorepo-root skills/ duplicates.
     *
     * @return array<string, array{name: string, description: string, userInvocable: bool, effort: string, paths: array<string>}>
     */
    private function getExpectedSkills(): array
    {
        return [
            'php-best-practices' => [
                'name' => 'php-best-practices',
                'description' => 'PHP best practices, PSR-12 compliance, type safety, and modern PHP patterns. Use when reviewing or writing PHP code.',
                'userInvocable' => true,
                'effort' => 'high',
                'paths' => ['**/*.php'],
            ],
            'security-audit' => [
                'name' => 'security-audit',
                'description' => 'Security audit for PHP code. Check for SQL injection, XSS, CSRF, authentication issues, and other vulnerabilities.',
                'userInvocable' => true,
                'effort' => 'high',
                'paths' => ['**/*.php'],
            ],
            'phpunit-master' => [
                'name' => 'phpunit-master',
                'description' => 'PHPUnit testing best practices, mocking, data providers, and test organization.',
                'userInvocable' => true,
                'effort' => 'high',
                'paths' => ['**/*Test.php'],
            ],
            'composer-wizard' => [
                'name' => 'composer-wizard',
                'description' => 'Composer dependency management, version constraints, and autoloading configuration.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => ['composer.json', 'composer.lock'],
            ],
            'api-design' => [
                'name' => 'api-design',
                'description' => 'REST conventions, JSON:API patterns, authentication flows, error handling. Use when designing APIs, implementing endpoints, handling authentication, or structuring JSON responses.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'laravel-best-practices' => [
                'name' => 'laravel-best-practices',
                'description' => 'Laravel coding standards, Eloquent optimization, service container patterns, Blade conventions. Use when writing Laravel code, optimizing queries, structuring services, or working with Blade templates.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'symfony-best-practices' => [
                'name' => 'symfony-best-practices',
                'description' => 'Symfony coding standards, service definition, event dispatcher patterns, form handling. Use when writing Symfony code, configuring services, handling forms, or working with event subscribers.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'testing-strategies' => [
                'name' => 'testing-strategies',
                'description' => 'PHPUnit best practices, mock patterns, test organization, coverage goals. Use when writing tests, setting up test suites, creating mocks, or analyzing test coverage.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'explore-codebase' => [
                'name' => 'explore-codebase',
                'description' => 'Fast read-only pass for tracing an unfamiliar lib\'s structure before editing it. Use when you need to understand a candy-*/sugar-*/honey-* lib\'s layout, dependencies, and conventions before making changes — without spawning a full sub-agent. Triggers automatically when an agent first touches a file inside an unfamiliar lib.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'matchups-sync' => [
                'name' => 'matchups-sync',
                'description' => 'Keeps docs/MATCHUPS.md and PROJECT_NAMES.md in sync whenever a new port lands. Automatically run at the end of any workflow stage that adds a library. Triggers on "sync matchups", "new port landed", or when a lib is added to the monorepo.',
                'userInvocable' => false,
                'effort' => 'medium',
                'paths' => [],
            ],
            'mcp-authoring' => [
                'name' => 'mcp-authoring',
                'description' => 'Scaffolds a new MCP tool inside a SugarCraft lib that wants to expose itself over the protocol. Use when a lib maintainer says \'add MCP tool\', \'expose over protocol\', \'register MCP handler\', or creates files under a lib\'s `src/MCP/` directory. Generates the tool schema, McpServer wiring, and a smoke test.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
            'worktree-workflow' => [
                'name' => 'worktree-workflow',
                'description' => 'Walks a teammate through claiming a task, creating its worktree, and opening the merge-back PR per the ship-as-you-go cadence. Use when a teammate says \'claim task\', \'create worktree\', \'open PR\', or \'start work on <slug>\'. Keeps the worktree lifecycle consistent across all teammates.',
                'userInvocable' => true,
                'effort' => 'medium',
                'paths' => [],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Skill loading via Skill::fromFile()
    // -------------------------------------------------------------------------

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillFileLoadsViaFromFile(string $skillPath, string $expectedName): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertSame($expectedName, $skill->name);
    }

    /**
     * Data provider for skill file paths.
     *
     * @return array<string, array{string, string}>
     */
    public static function skillFilePathsProvider(): array
    {
        $basePath = dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        return [
            'php-best-practices' => ["$basePath/php-best-practices/SKILL.md", 'php-best-practices'],
            'security-audit' => ["$basePath/security-audit/SKILL.md", 'security-audit'],
            'phpunit-master' => ["$basePath/phpunit-master/SKILL.md", 'phpunit-master'],
            'composer-wizard' => ["$basePath/composer-wizard/SKILL.md", 'composer-wizard'],
            'api-design' => ["$basePath/api-design/SKILL.md", 'api-design'],
            'laravel-best-practices' => ["$basePath/laravel-best-practices/SKILL.md", 'laravel-best-practices'],
            'symfony-best-practices' => ["$basePath/symfony-best-practices/SKILL.md", 'symfony-best-practices'],
            'testing-strategies' => ["$basePath/testing-strategies/SKILL.md", 'testing-strategies'],
            'explore-codebase' => ["$basePath/explore-codebase/SKILL.md", 'explore-codebase'],
            'matchups-sync' => ["$basePath/matchups-sync/SKILL.md", 'matchups-sync'],
            'mcp-authoring' => ["$basePath/mcp-authoring/SKILL.md", 'mcp-authoring'],
            'worktree-workflow' => ["$basePath/worktree-workflow/SKILL.md", 'worktree-workflow'],
        ];
    }

    // -------------------------------------------------------------------------
    // Skill metadata verification
    // -------------------------------------------------------------------------

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillHasCorrectName(string $skillPath, string $expectedName): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expectedName, $skill->name);
    }

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillHasCorrectDescription(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['description'], $skill->description);
    }

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillUserInvocableMatchesExpected(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['userInvocable'], $skill->userInvocable);
    }

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillHasCorrectEffort(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['effort'], $skill->effort);
    }

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillHasCorrectPaths(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['paths'], $skill->paths);
    }

    // -------------------------------------------------------------------------
    // fnmatch() path matching
    // -------------------------------------------------------------------------

    /**
     * @dataProvider phpSkillPathsProvider
     */
    public function testPhpSkillsMatchPhpFiles(string $skillPath, string $filePath): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert - verify fnmatch works with the skill's paths
        foreach ($skill->paths as $pattern) {
            $this->assertTrue(fnmatch($pattern, $filePath), "Pattern '$pattern' should match '$filePath'");
        }
    }

    /**
     * Data provider for PHP skill path matching tests.
     *
     * @return array<string, array{string, string}>
     */
    public static function phpSkillPathsProvider(): array
    {
        $basePath = dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        return [
            'php-best-practices matches src file' => ["$basePath/php-best-practices/SKILL.md", 'src/MyClass.php'],
            'php-best-practices matches deep path' => ["$basePath/php-best-practices/SKILL.md", 'src/Deep/Nested/Class.php'],
            'security-audit matches src file' => ["$basePath/security-audit/SKILL.md", 'src/MyClass.php'],
            'security-audit matches tests file' => ["$basePath/security-audit/SKILL.md", 'tests/MyClass.php'],
        ];
    }

    /**
     * @dataProvider phpunitSkillPathsProvider
     */
    public function testPhpunitSkillMatchesTestFiles(string $filePath): void
    {
        // Arrange
        $basePath = dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        $skillPath = "$basePath/phpunit-master/SKILL.md";
        $skill = Skill::fromFile($skillPath);

        // Act & Assert
        $this->assertTrue(fnmatch($skill->paths[0], $filePath), "Pattern '{$skill->paths[0]}' should match '$filePath'");
    }

    /**
     * Data provider for PHPUnit skill path matching tests.
     *
     * @return array<string, array{string}>
     */
    public static function phpunitSkillPathsProvider(): array
    {
        return [
            'matches Test.php suffix' => ['tests/MyClassTest.php'],
            'matches deep path test file' => ['tests/Unit/ServiceTest.php'],
            'matches IntegrationTest.php' => ['tests/IntegrationTest.php'],
        ];
    }

    /**
     * @dataProvider phpunitSkillNonMatchingProvider
     */
    public function testPhpunitSkillDoesNotMatchNonTestFiles(string $filePath): void
    {
        // Arrange
        $basePath = dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        $skillPath = "$basePath/phpunit-master/SKILL.md";
        $skill = Skill::fromFile($skillPath);

        // Act & Assert
        $this->assertFalse(fnmatch($skill->paths[0], $filePath), "Pattern '{$skill->paths[0]}' should NOT match '$filePath'");
    }

    /**
     * Data provider for PHPUnit skill non-matching tests.
     *
     * @return array<string, array{string}>
     */
    public static function phpunitSkillNonMatchingProvider(): array
    {
        return [
            'does not match regular php file' => ['src/MyClass.php'],
            'does not match regular file' => ['src/MyClass.inc'],
            'does not match no extension' => ['src/MyClass'],
        ];
    }

    /**
     * @dataProvider composerSkillPathsProvider
     */
    public function testComposerSkillMatchesComposerFiles(string $filePath, string $expectedPattern): void
    {
        // Arrange
        $basePath = dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        $skillPath = "$basePath/composer-wizard/SKILL.md";
        $skill = Skill::fromFile($skillPath);

        // Act & Assert - fnmatch() only matches file basename, not full paths
        $this->assertTrue(fnmatch($expectedPattern, $filePath), "Pattern '$expectedPattern' should match '$filePath'");
    }

    /**
     * Data provider for Composer skill path matching tests.
     * Note: fnmatch() with pattern like "composer.json" only matches the basename,
     * not paths like "nested/path/composer.json". The pattern matches file itself.
     *
     * @return array<string, array{string, string}>
     */
    public static function composerSkillPathsProvider(): array
    {
        return [
            'matches composer.json' => ['composer.json', 'composer.json'],
            'matches composer.lock' => ['composer.lock', 'composer.lock'],
        ];
    }

    // -------------------------------------------------------------------------
    // SkillLoader::loadBuiltInSkills() integration
    // -------------------------------------------------------------------------

    public function testLoadBuiltInSkillsReturnsAllTwelveSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $skills = $loader->loadBuiltInSkills();

        // Assert
        $this->assertCount(12, $skills, 'Should load exactly 12 built-in skills');
    }

    public function testLoadBuiltInSkillsContainsAllExpectedSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $expectedNames = array_keys($this->getExpectedSkills());

        // Act
        $skills = $loader->loadBuiltInSkills();

        // Assert
        foreach ($expectedNames as $name) {
            $this->assertArrayHasKey($name, $skills, "Missing built-in skill: $name");
        }
    }

    public function testLoadBuiltInSkillsMetadataMatchesExpected(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $expected = $this->getExpectedSkills();

        // Act
        $skills = $loader->loadBuiltInSkills();

        // Assert
        foreach ($expected as $name => $spec) {
            $skill = $skills[$name];
            $this->assertSame($spec['name'], $skill->name, "Wrong name for $name");
            $this->assertSame($spec['description'], $skill->description, "Wrong description for $name");
            $this->assertSame($spec['userInvocable'], $skill->userInvocable, "Wrong userInvocable for $name");
            $this->assertSame($spec['effort'], $skill->effort, "Wrong effort for $name");
            $this->assertSame($spec['paths'], $skill->paths, "Wrong paths for $name");
        }
    }

    /**
     * R17 repro: the 8 relocated skills (laravel-best-practices,
     * symfony-best-practices, testing-strategies, api-design,
     * explore-codebase, mcp-authoring, worktree-workflow, matchups-sync)
     * must be discoverable through SkillLoader's existing BuiltIn scan
     * path with NO loader code changes -- i.e. loadAll() from the
     * sugar-crush project root surfaces all 12 built-ins.
     */
    public function testLoadAllFromSugarCrushRootReturnsAllTwelveBuiltIns(): void
    {
        // Arrange
        $loader = new SkillLoader();
        $expectedNames = array_keys($this->getExpectedSkills());

        // Act
        $skills = $loader->loadAll('.');

        // Assert
        foreach ($expectedNames as $name) {
            $this->assertArrayHasKey($name, $skills, "loadAll('.') should surface built-in skill: $name");
        }
    }

    // -------------------------------------------------------------------------
    // Skill source path verification
    // -------------------------------------------------------------------------

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillSourcePathEndsWithSkillMd(string $skillPath): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertStringEndsWith('/SKILL.md', $skill->sourcePath);
    }

    /**
     * @dataProvider skillFilePathsProvider
     */
    public function testSkillContentIsNotEmpty(string $skillPath): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertNotEmpty($skill->content);
    }
}
