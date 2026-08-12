<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * crush_feat.md section 7 E4: `SkillRegistry::getForPaths()` was correct and
 * tested but had no production caller, so touching a file a skill scopes
 * itself to surfaced nothing. Every assertion here fails against the old
 * Read/Edit/Glob, which had no way to reach the registry at all.
 *
 * @see SkillPathNudge
 */
final class SkillPathScopingTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-skill-paths-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function nudge(): SkillPathNudge
    {
        $registry = new SkillRegistry();
        $registry->register([
            'php-audit' => Skill::parse(
                <<<SKILL
                ---
                description: Security audit for PHP code
                paths:
                  - "*.php"
                ---
                body
                SKILL,
                'php-audit'
            ),
        ]);

        return SkillPathNudge::new($registry);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function testReadAppendsThePathScopedSkillReminderAfterTheFileContents(): void
    {
        $path = $this->write('App.php', "<?php\necho 1;\n");
        $tool = new Read(skillNudge: $this->nudge());

        $content = $tool->execute(['id' => 'c1', 'file_path' => $path])->content();

        self::assertStringStartsWith("<?php\necho 1;\n", $content);
        self::assertStringEndsWith(
            "<system-reminder>\n"
            . "These skills are scoped to paths you just touched. Invoke one with the Skill tool if it applies:\n"
            . "- php-audit: Security audit for PHP code\n"
            . '</system-reminder>',
            $content
        );
    }

    public function testReadLeavesAnUnscopedFileUntouched(): void
    {
        $path = $this->write('notes.txt', "plain\n");
        $tool = new Read(skillNudge: $this->nudge());

        self::assertSame("plain\n", $tool->execute(['id' => 'c1', 'file_path' => $path])->content());
    }

    public function testReadWithoutANudgeStaysByteIdentical(): void
    {
        $path = $this->write('App.php', "<?php\n");

        self::assertSame("<?php\n", (new Read())->execute(['id' => 'c1', 'file_path' => $path])->content());
    }

    public function testEditAppendsTheReminderAfterASuccessfulWrite(): void
    {
        $path = $this->write('App.php', "<?php\necho 'old';\n");
        $tool = new Edit(skillNudge: $this->nudge());

        $result = $tool->execute([
            'id' => 'c1',
            'file_path' => $path,
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        self::assertFalse($result->isError());
        self::assertStringContainsString('- php-audit: Security audit for PHP code', $result->content());
        // The reminder is informational only; it must never reach disk.
        self::assertStringNotContainsString('system-reminder', (string) file_get_contents($path));
    }

    public function testAFailedEditDoesNotBurnTheOneShotNudge(): void
    {
        $path = $this->write('App.php', "<?php\n");
        $nudge = $this->nudge();
        $tool = new Edit(skillNudge: $nudge);

        $failed = $tool->execute([
            'id' => 'c1',
            'file_path' => $path,
            'old_string' => 'nowhere',
            'new_string' => 'x',
        ]);

        self::assertTrue($failed->isError());
        self::assertSame([], $nudge->announced());
        self::assertStringContainsString('- php-audit:', (string) $nudge->forPath($path));
    }

    public function testGlobAppendsOneReminderForTheWholeMatchList(): void
    {
        $this->write('A.php', '');
        $this->write('B.php', '');
        $tool = new Glob(skillNudge: $this->nudge());

        $content = $tool->execute([
            'id' => 'c1',
            'pattern' => '*.php',
            'path' => $this->dir,
        ])->content();

        self::assertStringContainsString('/A.php', $content);
        self::assertSame(1, substr_count($content, '<system-reminder>'));
    }

    public function testASharedNudgeAnnouncesASkillOnlyOnceAcrossReadEditAndGlob(): void
    {
        $path = $this->write('App.php', "<?php\necho 'old';\n");
        $nudge = $this->nudge();

        $read = (new Read(skillNudge: $nudge))->execute(['id' => 'c1', 'file_path' => $path]);
        $glob = (new Glob(skillNudge: $nudge))->execute(['id' => 'c2', 'pattern' => '*.php', 'path' => $this->dir]);
        $edit = (new Edit(skillNudge: $nudge))->execute([
            'id' => 'c3',
            'file_path' => $path,
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        self::assertStringContainsString('system-reminder', $read->content());
        self::assertStringNotContainsString('system-reminder', $glob->content());
        self::assertStringNotContainsString('system-reminder', $edit->content());
        self::assertSame(['php-audit'], $nudge->announced());
    }
}
