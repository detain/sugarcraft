<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * crush_feat.md section 7 E4: `paths:` frontmatter must become a live
 * auto-scoping signal on the tool-touch path, not static metadata.
 */
final class SkillPathNudgeTest extends TestCase
{
    private function registry(): SkillRegistry
    {
        $registry = new SkillRegistry();
        $registry->register([
            'php-audit' => Skill::parse(
                <<<SKILL
                ---
                description: Security audit for PHP code
                paths:
                  - /src/**/*.php
                ---
                body
                SKILL,
                'php-audit'
            ),
            'py-lint' => Skill::parse(
                <<<SKILL
                ---
                description: Python lint helper
                paths:
                  - /src/**/*.py
                ---
                body
                SKILL,
                'py-lint'
            ),
            'unscoped' => Skill::parse(
                <<<SKILL
                ---
                description: No paths at all
                ---
                body
                SKILL,
                'unscoped'
            ),
        ]);

        return $registry;
    }

    public function testForPathNudgesTheSkillScopedToTheTouchedFile(): void
    {
        $nudge = SkillPathNudge::new($this->registry());

        $out = $nudge->forPath('/src/App.php');

        self::assertSame(
            "<system-reminder>\n"
            . "These skills are scoped to paths you just touched. Invoke one with the Skill tool if it applies:\n"
            . "- php-audit: Security audit for PHP code\n"
            . '</system-reminder>',
            $out
        );
    }

    public function testUnrelatedPathProducesNoNudge(): void
    {
        $nudge = SkillPathNudge::new($this->registry());

        self::assertNull($nudge->forPath('/var/log/system.log'));
    }

    public function testNudgeFiresOnceThenStaysSilentForTheSameSkill(): void
    {
        $nudge = SkillPathNudge::new($this->registry());

        self::assertNotNull($nudge->forPath('/src/App.php'));
        self::assertNull($nudge->forPath('/src/Other.php'));
        self::assertSame(['php-audit'], $nudge->announced());
    }

    public function testASecondSkillStillNudgesAfterTheFirstWasAnnounced(): void
    {
        $nudge = SkillPathNudge::new($this->registry());
        $nudge->forPath('/src/App.php');

        $out = $nudge->forPath('/src/script.py');

        self::assertNotNull($out);
        self::assertStringContainsString('- py-lint: Python lint helper', $out);
        self::assertSame(['php-audit', 'py-lint'], $nudge->announced());
    }

    public function testForPathsBatchesEveryMatchIntoOneReminder(): void
    {
        $nudge = SkillPathNudge::new($this->registry());

        $out = $nudge->forPaths(['/src/App.php', '/src/script.py']);

        self::assertNotNull($out);
        self::assertStringContainsString('- php-audit:', $out);
        self::assertStringContainsString('- py-lint:', $out);
        self::assertSame(1, substr_count($out, '<system-reminder>'));
    }

    public function testEmptyPathListProducesNoNudge(): void
    {
        self::assertNull(SkillPathNudge::new($this->registry())->forPaths([]));
    }

    public function testDisabledSkillIsNeverNudged(): void
    {
        $registry = $this->registry();
        $registry->disable('php-audit');

        self::assertNull(SkillPathNudge::new($registry)->forPath('/src/App.php'));
    }

    public function testModelInvocationDisabledSkillIsNeverNudged(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'manual-only' => Skill::parse(
                <<<SKILL
                ---
                description: Human picker only
                disable-model-invocation: true
                paths:
                  - /src/**/*.php
                ---
                body
                SKILL,
                'manual-only'
            ),
        ]);

        $nudge = SkillPathNudge::new($registry);

        self::assertNull($nudge->forPath('/src/App.php'));
        self::assertSame([], $nudge->announced());
    }

    public function testRegistryWithNoPathScopedSkillsNeverNudges(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'unscoped' => Skill::parse(
                <<<SKILL
                ---
                description: No paths at all
                ---
                body
                SKILL,
                'unscoped'
            ),
        ]);

        self::assertNull(SkillPathNudge::new($registry)->forPath('/src/App.php'));
    }
}
