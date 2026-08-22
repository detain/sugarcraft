<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Skills\Skill;
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
     * The behaviour-note paragraph of one document, and nothing else.
     *
     * SCOPED DELIBERATELY. Every skill this note names is also named elsewhere
     * in the same file — `phpunit-master` sits in `docs/SKILLS.md`'s built-in
     * roster table too — so a whole-document `assertStringContainsString()` is
     * satisfied by a mention that has nothing to do with `paths:`. MEASURED:
     * with the wide window, deleting `phpunit-master` from this very note left
     * this test green. Failing loudly when the delimiters move is the point;
     * a note that has been reworded needs its assertion re-aimed, not widened.
     */
    private function behaviourNote(string $relative, string $from, string $to): string
    {
        $text = $this->doc($relative);
        $start = strpos($text, $from);
        self::assertNotFalse(
            $start,
            "{$relative} no longer opens its leading-`**` behaviour note with \"{$from}\"",
        );
        $end = strpos($text, $to, $start);
        self::assertNotFalse(
            $end,
            "{$relative}'s leading-`**` behaviour note no longer ends with \"{$to}\"",
        );

        return substr($text, $start, $end - $start + strlen($to));
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
     * `src/*` requires at least one directory level and bounds none.
     *
     * WHY THIS IS ITS OWN CASE. The first draft of the `docs/SKILLS.md`
     * paragraph said `src/*\/foo.php` "does not restrict anything by itself",
     * which is wrong in the direction a skill author would feel: it DOES
     * refuse `src/foo.php`. Both halves are asserted here because a reader
     * given only "a `*` crosses `/`" reasonably concludes the pattern is
     * equivalent to `src/**\/foo.php`, and it is not.
     */
    public function testASingleStarRequiresAtLeastOneLevelAndBoundsNone(): void
    {
        self::assertFalse(
            SkillRegistry::pathMatches('src/*/foo.php', 'src/foo.php'),
            '`src/*/foo.php` now claims a file with no directory between; docs/SKILLS.md states that '
            . 'it requires at least one level',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('src/*/foo.php', 'src/a/foo.php'),
            '`src/*/foo.php` no longer claims a file exactly one level down',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('src/*/foo.php', 'src/a/b/foo.php'),
            '`src/*/foo.php` no longer claims a file several levels down; docs/SKILLS.md states that '
            . 'the pattern puts no ceiling on the depth',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('src/**/foo.php', 'src/foo.php'),
            '`src/**/foo.php` no longer spans zero levels, which is the contrast the paragraph draws',
        );

        self::assertStringContainsString(
            'is not "exactly one level down"',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer warns that a single-`*` segment is not "exactly one level down". '
            . 'An earlier draft said it "does not restrict anything by itself", which is false — it '
            . 'refuses a file with no directory between.',
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
     *
     * THE FIRST VERSION CLAIMED BOTH DIRECTIONS AND ASSERTED ONE. It checked
     * that every derived name appeared in the note, and then checked the note's
     * LENGTH by counting the roster — so adding a built-in the note has no
     * business naming (`laravel-best-practices`, which declares no `paths:` at
     * all) survived, because the count it compared against was the roster's own.
     * The note's names are now extracted and compared as a SET, which is the
     * only form in which "exactly" means anything.
     *
     * AND THE CENSUS GOES THROUGH {@see Skill::fromFile()} — the production
     * parser — rather than a regex written from the shapes the four shipped
     * skills happen to use. The regex was `/^\s*-\s*"(\*\*[^"]*)"\s*$/m`: a
     * double-quoted block-list item and nothing else. A fourth built-in
     * declaring `- '**\/*.probe'` or `paths: ["**\/*.probe"]` — both of which
     * `fromFile()` accepts and both of which are ordinary YAML — was invisible
     * to it, so the census reported three and the mutation survived. It also
     * scanned the whole file, so a `- "**\/…"` line in a skill's BODY counted
     * as a `paths:` declaration. A census whose alphabet is written from the
     * cases already shipped cannot report a case that is written differently,
     * and that is the entire failure mode.
     */
    public function testTheAffectedBuiltInsAreExactlyTheOnesTheDocsName(): void
    {
        $roster = $this->builtInPaths();
        $affected = [];
        foreach ($roster as $name => $paths) {
            foreach ($paths as $pattern) {
                if (str_starts_with($pattern, '**')) {
                    $affected[] = $name;
                    break;
                }
            }
        }
        sort($affected);

        self::assertNotSame(
            [],
            $affected,
            'no shipped built-in declares a leading-`**` `paths:` pattern any more, so the behaviour '
            . 'note in docs/SKILLS.md and README.md names skills that no longer exemplify it',
        );

        $notes = [
            'docs/SKILLS.md' => $this->behaviourNote(
                'docs/SKILLS.md',
                'shipped built-in skills declare a leading',
                'this is the note saying it is gone.',
            ),
            'README.md' => $this->behaviourNote(
                'README.md',
                'shipped skills scoped',
                'has the measured table.',
            ),
        ];

        foreach ($notes as $file => $note) {
            self::assertSame(
                $affected,
                $this->builtInsNamedIn($note, array_keys($roster)),
                $file . "'s leading-`**` behaviour note and the shipped frontmatter name different "
                . 'sets of built-ins. BOTH directions are the defect: a skill the note omits is a '
                . 'user who never learns their nudges moved, and a skill the note adds is a user who '
                . 'goes looking for a change that did not happen to them. Note that this is scoped '
                . 'to the NOTE, not to the whole file — an earlier version searched the document, '
                . 'and the mutation that dropped `phpunit-master` from the note survived on its '
                . 'unrelated mention in the built-in roster table.',
            );
        }

        // And the count each document spells out, derived rather than restated.
        $word = self::COUNT_WORDS[count($affected)] ?? (string) count($affected);
        self::assertStringContainsString(
            $word . ' shipped built-in skills declare a leading `**`',
            $this->doc('docs/SKILLS.md'),
            'docs/SKILLS.md no longer states how many built-ins are affected, or states a number '
            . 'other than the ' . count($affected) . ' the frontmatter declares',
        );
        self::assertStringContainsString(
            'the ' . strtolower($word) . ' shipped skills scoped',
            $this->doc('README.md'),
            'README.md no longer states how many built-ins are affected, or states a number other '
            . 'than the ' . count($affected) . ' the frontmatter declares',
        );
    }

    /**
     * Number words this file can spell, so a document's count is derived from
     * the roster rather than restated next to it.
     */
    private const COUNT_WORDS = [
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
    ];

    /**
     * Every shipped built-in's `paths:` list, read by the production parser.
     *
     * @return array<string, list<string>> keyed by skill directory name, sorted
     */
    private function builtInPaths(): array
    {
        $dir = __DIR__ . '/../../src/Skills/BuiltIn';
        $roster = [];

        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }
            $file = $dir . '/' . $entry . '/SKILL.md';
            if (!is_file($file)) {
                continue;
            }
            $roster[$entry] = array_values(Skill::fromFile($file)->paths);
        }
        ksort($roster);

        self::assertNotSame([], $roster, 'no built-in skills found; the roster derivation is broken');

        return $roster;
    }

    /**
     * The built-in skill names a note mentions, in backticks.
     *
     * Intersected with the shipped roster rather than pattern-matched, because
     * a note legitimately backticks things that are not skill names —
     * `paths: ["**\/*.php"]` among them — and a rule shaped like "a backticked
     * hyphenated word is a skill" would either swallow those or need an
     * exception list written from the cases already there.
     *
     * @param list<string> $allBuiltIns
     *
     * @return list<string>
     */
    private function builtInsNamedIn(string $note, array $allBuiltIns): array
    {
        preg_match_all('/`([^`]+)`/u', $note, $m);
        $named = array_values(array_unique(array_intersect($m[1], $allBuiltIns)));
        sort($named);

        return $named;
    }
}
