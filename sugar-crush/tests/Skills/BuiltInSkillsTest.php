<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillMatcher;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Tests for built-in skills loading and path matching.
 *
 * R17: restored from git history (deleted by 753b0a2d under a misleading
 * "stale test removal" message) and extended to cover all 12 skills the
 * batch-1/2 additions put under src/Skills/BuiltIn/.
 *
 * Audit 15d-21: four of those twelve (explore-codebase, matchups-sync,
 * mcp-authoring, worktree-workflow) are procedures for THIS monorepo, and as
 * built-ins they were listed in every project's system prompt — one told the
 * model to `git checkout -- . && git clean -fd` a dirty tree. They now live in
 * the monorepo's own project tier, `<repo root>/.sugar-crush/skills/`, and the
 * eight generic skills stay built in. The four keep their metadata pins here,
 * retargeted at the new location, so moving them did not also unpin them.
 */
final class BuiltInSkillsTest extends TestCase
{
    use HomeSandboxTrait;

    /** The four monorepo-only skills, which must never be built-ins again. */
    private const MONOREPO_SKILLS = ['explore-codebase', 'matchups-sync', 'mcp-authoring', 'worktree-workflow'];

    private string $builtInSkillsPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Get the BuiltIn skills directory path
        $reflection = new \ReflectionClass(SkillLoader::class);
        $this->builtInSkillsPath = dirname($reflection->getFileName()) . '/BuiltIn';
    }

    /**
     * Expected definitions for the eight built-in skills.
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
        ];
    }

    /**
     * Expected definitions for the four SugarCraft-monorepo skills, which ship
     * in the repository root's project tier rather than as built-ins.
     *
     * @return array<string, array{name: string, description: string, userInvocable: bool, effort: string, paths: array<string>}>
     */
    private function getExpectedMonorepoSkills(): array
    {
        return [
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

    /** `<repo root>/.sugar-crush/skills` — the monorepo's own project tier. */
    private static function monorepoSkillsPath(): string
    {
        return dirname(__DIR__, 3) . '/.sugar-crush/skills';
    }

    /**
     * Built-in plus monorepo expectations, for the per-file metadata tests.
     *
     * @return array<string, array{name: string, description: string, userInvocable: bool, effort: string, paths: array<string>}>
     */
    private function getAllExpectedSkills(): array
    {
        return [...$this->getExpectedSkills(), ...$this->getExpectedMonorepoSkills()];
    }

    // -------------------------------------------------------------------------
    // Skill loading via Skill::fromFile()
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allSkillFilePathsProvider
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
     * The eight built-in skill files.
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
        ];
    }

    /**
     * The four monorepo skills, at their project-tier location.
     *
     * @return array<string, array{string, string}>
     */
    public static function monorepoSkillFilePathsProvider(): array
    {
        $basePath = self::monorepoSkillsPath();
        $cases = [];
        foreach (self::MONOREPO_SKILLS as $name) {
            $cases["monorepo {$name}"] = ["$basePath/{$name}/SKILL.md", $name];
        }

        return $cases;
    }

    /**
     * Every shipped skill file, built-in and monorepo project tier alike.
     *
     * @return array<string, array{string, string}>
     */
    public static function allSkillFilePathsProvider(): array
    {
        return [...self::skillFilePathsProvider(), ...self::monorepoSkillFilePathsProvider()];
    }

    // -------------------------------------------------------------------------
    // Skill metadata verification
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillHasCorrectName(string $skillPath, string $expectedName): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expectedName, $skill->name);
    }

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillHasCorrectDescription(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getAllExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['description'], $skill->description);
    }

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillUserInvocableMatchesExpected(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getAllExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['userInvocable'], $skill->userInvocable);
    }

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillHasCorrectEffort(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getAllExpectedSkills()[$expectedName];

        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertSame($expected['effort'], $skill->effort);
    }

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillHasCorrectPaths(string $skillPath, string $expectedName): void
    {
        // Arrange
        $expected = $this->getAllExpectedSkills()[$expectedName];

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

    public function testLoadBuiltInSkillsReturnsAllEightSkills(): void
    {
        // Arrange
        $loader = new SkillLoader();

        // Act
        $skills = $loader->loadBuiltInSkills();

        // Assert
        $this->assertCount(8, $skills, 'Should load exactly 8 built-in skills');
        $this->assertSame([], array_values(array_intersect(self::MONOREPO_SKILLS, array_keys($skills))));
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
     * R17 repro: the relocated generic skills (laravel-best-practices,
     * symfony-best-practices, testing-strategies, api-design) must be
     * discoverable through SkillLoader's existing BuiltIn scan path with NO
     * loader code changes -- i.e. loadAll() from the sugar-crush project root
     * surfaces all eight built-ins.
     */
    public function testLoadAllFromSugarCrushRootReturnsAllEightBuiltIns(): void
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
    // Audit 15d-21: monorepo-only skills live in the monorepo's project tier
    // -------------------------------------------------------------------------

    /**
     * The four monorepo skills still load — as PROJECT skills, from the
     * repository root's `.sugar-crush/skills`, when that root is the project.
     * Both loaders a launch can take are asked, with HOME pointed at an empty
     * sandbox so a developer's own `~/.sugar-crush/skills` cannot answer for
     * them.
     */
    public function testLoadAllFromTheMonorepoRootSurfacesTheMonorepoSkillsAsProjectSkills(): void
    {
        $home = $this->useHomeSandbox(sys_get_temp_dir() . '/sugar-crush-builtin-home-' . uniqid('', true));
        $root = dirname(__DIR__, 3);
        $tier = realpath(self::monorepoSkillsPath());
        $this->assertIsString($tier, 'the monorepo project tier is missing from the repository root');

        try {
            $loader = new SkillLoader(false);
            $skills = $loader->loadAll($root);
            $manifests = $loader->loadAllManifests($root);

            foreach (self::MONOREPO_SKILLS as $name) {
                $this->assertArrayHasKey($name, $skills, "loadAll(<monorepo root>) must surface {$name}");
                $this->assertSame(SkillOrigin::Project, $skills[$name]->origin, "{$name} must load from the project tier");
                $this->assertStringStartsWith($tier . '/', (string) realpath($skills[$name]->sourcePath));

                $this->assertArrayHasKey($name, $manifests, "loadAllManifests(<monorepo root>) must surface {$name}");
                $this->assertSame(SkillOrigin::Project, $manifests[$name]['origin']);
                $this->assertStringStartsWith($tier . '/', $manifests[$name]['sourcePath']);
            }
        } finally {
            $this->restoreHomeSandbox();
            @rmdir($home);
        }
    }

    /**
     * No built-in may carry this monorepo's paths or conventions, or a command
     * that discards work or self-merges: a built-in is listed in EVERY
     * project's prompt, so its body runs wherever sugar-crush does. The whole
     * file is read — frontmatter included, since the description is the part
     * that reaches the prompt listing.
     */
    public function testNoBuiltInSkillNamesASugarCraftPathOrRunsADestructiveGitCommand(): void
    {
        $files = glob($this->builtInSkillsPath . '/*/SKILL.md');
        $this->assertIsArray($files);
        $this->assertCount(8, $files, 'the scan must read every built-in, or it proves nothing');

        $forbidden = [
            'the monorepo port map' => '/MATCHUPS/i',
            'the monorepo naming rulebook' => '/PROJECT_NAMES/i',
            'a candy-* lib' => '/\bcandy-/i',
            'a honey-* lib' => '/\bhoney-/i',
            'a sugar-* lib or sugar-crush itself' => '/\bsugar-[a-z]/i',
            'the SugarCraft monorepo' => '/sugarcraft/i',
            'git clean' => '/\bgit\s+clean\b/i',
            'git checkout -- .' => '/\bgit\s+checkout\s+--\s+\./i',
            'git reset --hard' => '/\bgit\s+reset\s+--hard\b/i',
            'gh pr merge' => '/\bgh\s+pr\s+merge\b/i',
            'gh pr create' => '/\bgh\s+pr\s+create\b/i',
        ];

        foreach ($files as $file) {
            $body = (string) file_get_contents($file);
            $this->assertNotSame('', $body, "{$file} could not be read");
            foreach ($forbidden as $what => $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $body,
                    sprintf('built-in %s names %s — a built-in is listed in every project', basename(dirname($file)), $what),
                );
            }
        }
    }

    /**
     * No shipped skill, built-in OR the monorepo's project-tier copies, may
     * tell the model to throw away uncommitted or untracked work. A tree that
     * is not clean means stop and report — it may be the user's unsaved work,
     * and since a shell `cd` does not persist between tool calls, "the
     * worktree" is often the main checkout.
     */
    public function testNoShippedSkillTellsTheModelToDiscardWork(): void
    {
        $files = [
            ...(array) glob($this->builtInSkillsPath . '/*/SKILL.md'),
            ...(array) glob(self::monorepoSkillsPath() . '/*/SKILL.md'),
        ];
        $this->assertGreaterThanOrEqual(12, count($files), 'the scan must cover both trees');

        foreach ($files as $file) {
            $body = (string) file_get_contents($file);
            foreach ([
                'git clean' => '/\bgit\s+clean\b/i',
                'git checkout -- .' => '/\bgit\s+checkout\s+--\s+\./i',
                'git checkout .' => '/\bgit\s+checkout\s+\.(\s|$)/im',
                'git restore .' => '/\bgit\s+restore\s+(--\S+\s+)*\.(\s|$)/im',
                'git reset --hard' => '/\bgit\s+reset\s+--hard\b/i',
            ] as $what => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $body, "{$file} tells the model to run {$what}");
            }
        }
    }

    /**
     * THE LISTING AN UNRELATED PROJECT SEES — the reported failure, end to
     * end: an empty project directory and an empty HOME, loaded through the
     * real SkillManager and rendered by the listing the system prompt is built
     * from. None of the monorepo skills may appear; the generic built-ins must,
     * or the absence would be vacuous.
     */
    public function testAnUnrelatedProjectsSkillListingNamesNoMonorepoSkill(): void
    {
        $tmp = sys_get_temp_dir() . '/sugar-crush-builtin-listing-' . uniqid('', true);
        $home = $this->useHomeSandbox($tmp . '/home');
        $project = $tmp . '/project';
        mkdir($project, 0700, true);

        try {
            $registry = new SkillRegistry();
            (new SkillManager(new SkillLoader(false), $registry))->loadAll($project);
            $listing = (new SkillMatcher())->listForPrompt($registry);
            $manifests = (new SkillLoader(false))->loadAllManifests($project);

            $this->assertStringContainsString('php-best-practices', $listing, 'the listing must still carry the built-ins');
            $this->assertArrayHasKey('php-best-practices', $manifests);

            foreach (self::MONOREPO_SKILLS as $name) {
                $this->assertNull($registry->get($name), "{$name} must not be registered for an unrelated project");
                $this->assertStringNotContainsString($name, $listing, "{$name} must not be listed to an unrelated project");
                $this->assertArrayNotHasKey($name, $manifests, "{$name} must not be a manifest for an unrelated project");
            }
            $this->assertDoesNotMatchRegularExpression('/MATCHUPS|SugarCraft|candy-|honey-/i', $listing);
        } finally {
            $this->restoreHomeSandbox();
            @rmdir($project);
            @rmdir($home);
            @rmdir($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Skill source path verification
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillSourcePathEndsWithSkillMd(string $skillPath): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertStringEndsWith('/SKILL.md', $skill->sourcePath);
    }

    /**
     * @dataProvider allSkillFilePathsProvider
     */
    public function testSkillContentIsNotEmpty(string $skillPath): void
    {
        // Act
        $skill = Skill::fromFile($skillPath);

        // Assert
        $this->assertNotEmpty($skill->content);
    }
}
