<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * `paths:` glob semantics are a USER-FACING contract, and the round that
 * changed them shipped the change with no user-facing note.
 *
 * WHAT HAPPENED. {@see SkillRegistry::pathMatches()} used to rewrite `**` with
 * three `str_replace()` passes and fall back to a bare `fnmatch()`. A pattern
 * beginning with `**` matched none of the three rewrites, and `fnmatch()` reads
 * `**\/` as "some characters, then a literal slash" — so `**\/*.php` did not
 * claim `a.php`. The translation-to-PCRE that replaced it does. Three SHIPPED
 * built-in skills declare a leading `**`, so all three went from silent on a
 * tree-root file to nudging on one. That is what their authors meant; a user
 * who noticed the old behaviour and worked around it had no way to learn it had
 * changed.
 *
 * WHAT THIS FILE PINS, and why it is not a duplicate of
 * {@see SkillPathPatternTest}. That file characterises the MATCHER — a 46x54
 * grid and a seeded differential fuzz — and is the right place for any question
 * about what the predicate answers. This file pins the DOCUMENTATION against
 * the matcher: every semantic clause `docs/SKILLS.md` and `README.md` now state
 * is re-derived here from {@see SkillRegistry} itself, and the prose is
 * required to say it. A matcher change that makes a documented clause false
 * reds here even though the grid was updated to match, which is the failure the
 * missing note was an instance of.
 *
 * THE "USED TO BE FALSE" HALF IS MEASURED TOO, not quoted. The old predicate is
 * still in the class as `legacyPathMatch()` — it is the answer for patterns the
 * translation cannot compile, so it is reachable rather than historical — and
 * the before/after pairs the docs cite are taken from it live. If it ever stops
 * disagreeing with `pathMatches()` on those pairs, the docs' "began matching"
 * claim has quietly become a claim about nothing and this test says so.
 *
 * PHP VERSION. Every figure here is re-derived at run time rather than written
 * down, precisely so it cannot carry a version. The prose in `docs/SKILLS.md`
 * cites PHP 8.3.6 because that is the only interpreter this box has; CI also
 * runs 8.4, and if `fnmatch()`'s treatment of `/` ever differs there this test
 * reds on 8.4 rather than letting the doc go on claiming 8.3's answer.
 *
 * @internal
 */
final class PathsGlobDocumentationTest extends TestCase
{
    private function doc(string $relative): string
    {
        $text = file_get_contents(__DIR__ . '/../../' . $relative);
        self::assertIsString($text, $relative . ' is unreadable');

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * The pre-translation predicate, reached by reflection because it is
     * private and because the point is to measure it rather than to describe
     * it.
     */
    private function legacy(string $pattern, string $path): bool
    {
        $m = new ReflectionMethod(SkillRegistry::class, 'legacyPathMatch');
        $m->setAccessible(true);

        return (bool) $m->invoke(null, $pattern, $path);
    }

    /**
     * A single `*` crosses `/` — the clause a reader is most likely to assume
     * the other way round, because most glob dialects they have met set
     * `FNM_PATHNAME`.
     */
    public function testASingleStarCrossesASlashAndBothDocsSaySo(): void
    {
        self::assertTrue(
            SkillRegistry::pathMatches('*.php', 'src/foo.php'),
            'a single `*` no longer crosses `/`; docs/SKILLS.md and README.md both state that it does',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('?', '/'),
            '`?` no longer matches a `/`; docs/SKILLS.md states that it does',
        );

        self::assertStringContainsString(
            'A single `*` **crosses `/`**',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer states that a single `*` crosses `/`',
        );
        self::assertStringContainsString(
            'without `FNM_PATHNAME`',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer names FNM_PATHNAME as the flag that is NOT set',
        );
        self::assertStringContainsString(
            'without `FNM_PATHNAME`',
            $this->doc('README.md'),
            'README.md\'s skills bullet no longer names FNM_PATHNAME as the flag that is NOT set',
        );
    }

    /**
     * `**` is zero-or-more levels at ANY position — the middle case and the
     * leading case, which are the two the docs promise separately.
     */
    public function testAGlobstarSpansZeroLevelsInTheMiddleAndAtTheFront(): void
    {
        self::assertTrue(
            SkillRegistry::pathMatches('src/**/*.php', 'src/foo.php'),
            'a mid-pattern `**` no longer spans zero directory levels',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('src/**/*.php', 'src/a/b/foo.php'),
            'a mid-pattern `**` no longer spans several directory levels',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('**/*.php', 'foo.php'),
            'a LEADING `**` no longer spans zero directory levels — this is the behaviour change '
            . 'docs/SKILLS.md and README.md both announce',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('a/**', 'a'),
            'a trailing `**` no longer spans zero directory levels',
        );

        $skills = $this->doc('docs/SKILLS.md');
        self::assertStringContainsString(
            'zero or more directory levels, at any position — including the first',
            $skills,
            'docs/SKILLS.md no longer states the `**` rule as covering the FIRST position',
        );
        self::assertStringContainsString(
            'at any position, the first included',
            $this->doc('README.md'),
            'README.md\'s skills bullet no longer states the `**` rule as covering the first position',
        );
    }

    /**
     * The before/after pairs both documents cite must still be a real
     * disagreement between the old predicate and the shipped one.
     *
     * This is the assertion that stops the announcement becoming a claim about
     * nothing: if `legacyPathMatch()` ever starts answering these the same way,
     * "began matching tree-root files" no longer describes a change anyone can
     * observe, and the note has to be rewritten rather than left standing.
     */
    public function testTheAnnouncedChangeIsStillARealDisagreementWithTheOldPredicate(): void
    {
        $pairs = [
            ['**/*.php', 'foo.php'],
            ['**/*Test.php', 'FooTest.php'],
        ];

        foreach ($pairs as [$pattern, $path]) {
            self::assertFalse(
                $this->legacy($pattern, $path),
                "legacyPathMatch('{$pattern}', '{$path}') no longer answers false, so the documented "
                . '"a leading ** used not to claim tree-root files" is no longer a statement about a '
                . 'change anyone can observe. Rewrite the note rather than leaving it.',
            );
            self::assertTrue(
                SkillRegistry::pathMatches($pattern, $path),
                "pathMatches('{$pattern}', '{$path}') no longer answers true; the docs announce that it "
                . 'does',
            );
        }

        self::assertStringContainsString(
            'began matching tree-root files',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer announces that a leading `**` began matching tree-root files',
        );
    }

    /**
     * Every shipped built-in skill whose `paths:` starts with `**` must be
     * named in the announcement, and nothing else may be.
     *
     * DERIVED FROM THE SHIPPED FRONTMATTER, both directions. A fourth built-in
     * gaining a leading-`**` pattern reds here, and so does one losing it while
     * the docs keep listing it — a reader checking "does this affect me?"
     * against a stale roster is why the list is here at all rather than left as
     * "some skills".
     */
    public function testTheAffectedBuiltInsAreExactlyTheOnesTheDocsName(): void
    {
        $dir = __DIR__ . '/../../src/Skills/BuiltIn';
        $affected = [];

        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $skill = $dir . '/' . $entry . '/SKILL.md';
            if (!is_file($skill)) {
                continue;
            }
            $text = (string) file_get_contents($skill);
            // The frontmatter list items, e.g. `  - "**/*.php"`.
            if (preg_match_all('/^\s*-\s*"(\*\*[^"]*)"\s*$/m', $text, $m) === 0) {
                continue;
            }
            $affected[] = $entry;
        }
        sort($affected);

        self::assertNotSame(
            [],
            $affected,
            'no shipped built-in declares a leading-`**` `paths:` pattern any more, so the behaviour '
            . 'note in docs/SKILLS.md and README.md names skills that no longer exemplify it',
        );

        foreach ([$this->doc('docs/SKILLS.md'), $this->doc('README.md')] as $where => $text) {
            foreach ($affected as $name) {
                self::assertStringContainsString(
                    '`' . $name . '`',
                    $text,
                    "the leading-`**` built-in '{$name}' is not named in doc #{$where}'s behaviour note",
                );
            }
        }

        // And the reverse: the docs' count must be the roster's count.
        self::assertStringContainsString(
            'Three shipped built-in skills declare a leading `**`',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer states how many built-ins are affected',
        );
        self::assertCount(
            3,
            $affected,
            'the number of built-ins declaring a leading-`**` `paths:` pattern is no longer three; '
            . 'docs/SKILLS.md and README.md both say three. Found: ' . implode(', ', $affected),
        );
    }
}
