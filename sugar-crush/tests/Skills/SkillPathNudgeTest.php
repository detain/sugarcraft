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

    /**
     * The announce-once mark has to survive the round trip a forked tool child
     * makes it take: {@see SkillPathNudge::announced()} out, `serialize()`
     * across the fork, {@see SkillPathNudge::markAnnounced()} back in.
     *
     * A skill named `123` is where that used to break. PHP coerces a
     * decimal-integer string ARRAY KEY to `int` on insertion, so `array_keys()`
     * handed back `int(123)`, and a `is_string()` filter on the way in dropped
     * it — leaving the skill unmarked, so it re-announced on every forked
     * Read/Glob for the rest of the session.
     */
    public function testASkillWithANumericNameSurvivesTheAnnounceOnceRoundTrip(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            '123' => Skill::parse(
                <<<SKILL
                ---
                description: Numerically named skill
                paths:
                  - /src/**/*.php
                ---
                body
                SKILL,
                '123'
            ),
        ]);

        // The child announces it and exports what it marked.
        $child = SkillPathNudge::new($registry);
        self::assertNotNull($child->forPath('/src/App.php'));
        $exported = $child->announced();
        self::assertSame(['123'], $exported);

        // The parent unions that export back in.
        $parent = SkillPathNudge::new($registry);
        $parent->markAnnounced($exported);

        self::assertSame(['123'], $parent->announced());
        self::assertNull(
            $parent->forPath('/src/Other.php'),
            'the mark must have landed, or this skill re-announces on every later touch',
        );
    }

    /**
     * And the merge tolerates a payload from an older build that really did
     * hand over an `int`, because the export crosses a process boundary and
     * the two sides can be different versions of this code.
     */
    public function testMarkAnnouncedAcceptsAnIntegerNameFromAnOlderPayload(): void
    {
        $nudge = SkillPathNudge::new($this->registry());
        $nudge->markAnnounced([123, 'php-audit']);

        self::assertSame(['123', 'php-audit'], $nudge->announced());
    }

    // =========================================================================
    // E66 — the nudge is bounded, in count and in bytes
    // =========================================================================

    /**
     * A registry of $count `paths:`-scoped auto-invocable skills, each with a
     * $descLen-byte description, all matching `/src/**\/*.php`.
     */
    private function fatRegistry(int $count, int $descLen): SkillRegistry
    {
        $registry = new SkillRegistry();
        $skills = [];
        for ($i = 0; $i < $count; $i++) {
            $name = "fat-$i";
            $skills[$name] = Skill::parse(
                "---\ndescription: " . str_repeat('d', $descLen) . "\npaths:\n  - /src/**/*.php\n---\nbody\n",
                $name,
            );
        }
        $registry->register($skills);

        return $registry;
    }

    /**
     * The whole of E66: the nudge grew linearly in (matching skills x
     * description length) with no clip anywhere.
     *
     * MEASURED at 8add627b on exactly this fixture — 1 x 200 gave 345 bytes,
     * 10 x 2,000 gave 20,253, 50 x 20,000 gave 1,000,773 and 200 x 50,000 gave
     * 10,002,823 — and appended OUTSIDE the byte cap of every tool that
     * carries it. The three larger rows are all above the ceiling now.
     */
    public function testTheNudgeIsBoundedHoweverManySkillsMatchAndHoweverLongTheirDescriptions(): void
    {
        foreach ([[1, 200], [10, 2000], [50, 20000], [200, 50000]] as [$count, $descLen]) {
            $text = SkillPathNudge::new($this->fatRegistry($count, $descLen))->forPath('/src/App.php');

            self::assertNotNull($text, "$count skills x $descLen must still say something");
            self::assertLessThanOrEqual(
                SkillPathNudge::maxBytes(),
                strlen($text),
                "$count skills x $descLen-byte descriptions overran the ceiling",
            );
        }
    }

    /**
     * The COUNT bound and the BYTE bound are two bounds because neither works
     * alone: 200 short skills overrun on count where one long skill overruns
     * on bytes. Both fixtures are checked against the same ceiling.
     */
    public function testManyShortSkillsAreBoundedByCountAndOneLongSkillByBytes(): void
    {
        $many = SkillPathNudge::new($this->fatRegistry(200, 20))->forPath('/src/App.php');
        $one = SkillPathNudge::new($this->fatRegistry(1, 50000))->forPath('/src/App.php');

        self::assertNotNull($many);
        self::assertNotNull($one);
        self::assertLessThanOrEqual(SkillPathNudge::maxBytes(), strlen($many));
        self::assertLessThanOrEqual(SkillPathNudge::maxBytes(), strlen($one));
        // Not a presence check on the clip marker: a clipped entry that keeps
        // the WHOLE description would carry the marker too.
        self::assertStringNotContainsString(str_repeat('d', 400), $one, 'the description was not clipped');
    }

    /**
     * A clipped entry says so. A description cut to half a sentence with no
     * sign of it reads as the skill's whole trigger phrase, and the model then
     * decides the skill does not apply on evidence that was withheld.
     */
    public function testAClippedEntrySaysItWasClipped(): void
    {
        $text = SkillPathNudge::new($this->fatRegistry(1, 50000))->forPath('/src/App.php');

        self::assertNotNull($text);
        self::assertStringContainsString('... [clipped]', $text);
    }

    /**
     * A description is arbitrary repository text, so the clip has to cut on a
     * character boundary. The em dash is placed so a naive byte cut lands
     * INSIDE its three-byte sequence: the entry prefix is `- fat-0: `, so the
     * dash straddles the 300-byte line cut at exactly these paddings.
     */
    public function testAClippedDescriptionStaysValidUtf8(): void
    {
        foreach (range(275, 290) as $pad) {
            $registry = new SkillRegistry();
            $registry->register(['fat-0' => Skill::parse(
                "---\ndescription: " . str_repeat('d', $pad) . "\u{2014}" . str_repeat('e', 200)
                . "\npaths:\n  - /src/**/*.php\n---\nbody\n",
                'fat-0',
            )]);

            $text = SkillPathNudge::new($registry)->forPath('/src/App.php');

            self::assertNotNull($text);
            self::assertTrue(mb_check_encoding($text, 'UTF-8'), "clip at padding $pad emitted invalid UTF-8");
        }
    }

    /**
     * The overflow is DEFERRED, not dropped — the property that makes the
     * count bound safe. Marking a skill announced without naming it would
     * retire it for the whole session, which is the failure the instruction
     * section bounds its own count before loading to avoid.
     */
    public function testASkillHeldBackByTheCountBoundIsAnnouncedByTheNextCall(): void
    {
        $nudge = SkillPathNudge::new($this->fatRegistry(11, 20));

        $first = $nudge->forPath('/src/App.php');
        self::assertNotNull($first);
        $firstNames = self::namesIn($first);

        $second = $nudge->forPath('/src/Other.php');
        self::assertNotNull($second, 'the held-back skills must still be pending');
        $secondNames = self::namesIn($second);

        self::assertSame([], array_intersect($firstNames, $secondNames), 'no skill may be announced twice');
        self::assertCount(11, array_unique([...$firstNames, ...$secondNames]), 'every skill must reach the model');
        self::assertStringContainsString('further path-scoped skill(s) matched', $first);
    }

    /**
     * A caller's budget is honoured, and honoured EXACTLY — this is what lets
     * Grep, Glob and Read spend the nudge inside their own cap instead of
     * beside it.
     */
    public function testTheNudgeNeverExceedsTheBudgetItIsGiven(): void
    {
        $emitted = 0;
        foreach ([200, 300, 500, 800, 1200, 2000, 4000] as $budget) {
            $text = SkillPathNudge::new($this->fatRegistry(20, 5000))->forPaths(['/src/App.php'], $budget);

            if ($text === null) {
                continue;
            }
            ++$emitted;
            self::assertLessThanOrEqual($budget, strlen($text), "budget $budget was overrun");
        }

        // Without this the sweep proves nothing: a forPaths() that returned
        // null at every budget would pass every assertion above.
        // MEASURED on this fixture: 200/300/500 return null (a 300-byte entry
        // plus the chrome and the deferred-note reserve does not fit), and
        // 800/1200/2000/4000 return 512/1114/1716/2619 bytes.
        self::assertSame(4, $emitted, 'the sweep must actually produce nudges');
    }

    /**
     * A budget too small for one entry surfaces nothing and SPENDS nothing, so
     * the next call with room still announces the skill. Returning a nudge no
     * one can afford, or burning the mark on one, are the two wrong answers.
     */
    public function testABudgetTooSmallForOneEntrySurfacesNothingAndSpendsNothing(): void
    {
        $nudge = SkillPathNudge::new($this->registry());

        self::assertNull($nudge->forPath('/src/App.php', 10));
        self::assertSame([], $nudge->announced(), 'nothing may be spent on a nudge that was never shown');
        self::assertNotNull($nudge->forPath('/src/App.php'), 'the skill must still be pending');
    }

    /** The skill names an emitted nudge carries, in order. */
    private static function namesIn(string $text): array
    {
        preg_match_all('/^- ([^:]+):/m', $text, $m);

        return $m[1];
    }
}
