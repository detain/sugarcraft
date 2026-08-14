<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * crush_feat.md section 7 E4: `paths:` auto-scoping has to reach the live
 * tool-touch path, not sit as static frontmatter. Against the old code
 * `Bootstrap::tools()` built Read/Edit/Glob with no route to the registry at
 * all, so a project skill scoped to `**\/*.php` never announced itself when
 * the agent opened a PHP file.
 *
 * Drives Bootstrap directly rather than shelling out to bin/sugarcrush, which
 * ends in a blocking Program::run() — same convention as
 * {@see BinSugarcrushWiringTest}.
 */
final class SkillPathScopingWiringTest extends TestCase
{
    private string $tempDir = '';
    private string $originalHome = '';
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_skill_paths_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0o700, true);
        mkdir($this->tempDir . '/repo', 0o755, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');

        // BOTH: Bootstrap reads getenv('HOME'), ForeignSkillDiscovery — now
        // reached from SkillManager::loadAll() — reads $_SERVER['HOME'], so
        // redirecting one leaves the other scanning the developer's own
        // ~/.claude/skills.
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $dir = $this->tempDir . '/repo/.sugar-crush/skills/path-scoped-audit';
        mkdir($dir, 0o755, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\ndescription: PATH SCOPED MARKER\nuser-invocable: true\n"
            . "disable-model-invocation: false\npaths:\n  - \"*.php\"\n---\n# body\n",
        );
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

    public function testBootstrapGivesReadEditAndGlobTheSameNudgeTracker(): void
    {
        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        $read = $this->nudgeOf($byClass[Read::class]);
        $edit = $this->nudgeOf($byClass[Edit::class]);
        $glob = $this->nudgeOf($byClass[Glob::class]);

        $this->assertInstanceOf(SkillPathNudge::class, $read);
        $this->assertSame($read, $edit);
        $this->assertSame($read, $glob);
    }

    public function testReadingAProjectPhpFileSurfacesThePathScopedProjectSkill(): void
    {
        $path = $this->tempDir . '/repo/App.php';
        file_put_contents($path, "<?php\n");

        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        $content = $byClass[Read::class]->execute(['id' => 'c1', 'file_path' => $path])->content();

        $this->assertStringContainsString('path-scoped-audit: PATH SCOPED MARKER', $content);
        $this->assertStringContainsString('<system-reminder>', $content);
    }

    private function nudgeOf(object $tool): ?SkillPathNudge
    {
        $property = new \ReflectionProperty($tool, 'skillNudge');
        $property->setAccessible(true);

        return $property->getValue($tool);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
