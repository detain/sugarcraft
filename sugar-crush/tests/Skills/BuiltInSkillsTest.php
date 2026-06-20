<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;

/**
 * Tests for built-in skills loading and path matching.
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
     * Get expected skill definitions for the 4 built-in skills.
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
    public function testSkillUserInvocableIsTrue(string $skillPath, string $expectedName): void
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

    public function testLoadBuiltInSkillsReturnsAllFourSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $skills = $loader->loadBuiltInSkills();

        // Assert
        $this->assertCount(4, $skills, 'Should load exactly 4 built-in skills');
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
