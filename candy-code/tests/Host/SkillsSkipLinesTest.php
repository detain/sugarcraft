<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Lang;

/**
 * Finding #25: `/skills` names every skipped file with its reason, not only a
 * count.
 */
final class SkillsSkipLinesTest extends TestCase
{
    public function testEachSkippedFileAndRefusedDirectoryGetsOneLineWithItsReason(): void
    {
        $lines = SkillsHostCommand::skipLines(
            ['/home/u/.candy-code/skills/broken/SKILL.md' => 'frontmatter is not valid YAML'],
            ['/home/u/.claude/skills' => 'directory is not readable'],
        );

        self::assertSame([
            '  ' . Lang::t('host.skills.installed.skip_line', [
                'path' => '/home/u/.candy-code/skills/broken/SKILL.md',
                'reason' => 'frontmatter is not valid YAML',
            ]),
            '  ' . Lang::t('host.skills.installed.skip_line', [
                'path' => '/home/u/.claude/skills',
                'reason' => 'directory is not readable',
            ]),
        ], $lines);
    }

    public function testNoSkipsMeansNoLines(): void
    {
        self::assertSame([], SkillsHostCommand::skipLines([], []));
    }

    public function testAReasonCarryingALineBreakCannotForgeAnotherLine(): void
    {
        $lines = SkillsHostCommand::skipLines(["/s/a/SKILL.md" => "bad\nforged line"], []);

        self::assertCount(1, $lines);
        self::assertStringNotContainsString("\n", $lines[0]);
    }
}
