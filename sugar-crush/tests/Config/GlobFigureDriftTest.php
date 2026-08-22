<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;

/**
 * THE CHARACTER COUNTS BESIDE `[!B]*` ARE FIGURES, AND A FIGURE WITHOUT A
 * GENERATOR IS NOT A MEASUREMENT.
 *
 * `docs/SETTINGS.md` and {@see LayeredSettings}' `PROJECT_TIER_KEYS` doc-block
 * both make the same argument: a project-tier `disabledTools` value short
 * enough to fit in a clause removes ten of the eleven tools and names none of
 * them. Both spell the length out loud, both spelled it WRONG ("eight") for
 * two rounds, and both were corrected in round 43 — after which each claimed,
 * in its own words, that the number now had a live generator and would red
 * instead of rotting.
 *
 * IT DID NOT. The test both pages named,
 * {@see ReadmeSettingsTierClaimTest::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem()},
 * derives the TOOL SET the glob leaves. It holds the glob as a class constant
 * derives the TOOL SET the glob leaves. It holds the glob as a class constant
 * and never measures its length. VERIFIED at `8416d98e`, and stated with the
 * command that produces it, because the loose version of it is wrong and the
 * precise version was itself a figure without a generator: `grep -rl 'strlen('
 * tests --include='*.php'` counts 66 files at round 44 and `grep -rl 'strlen'`
 * counts 68, and in none of them is `strlen()` applied to
 * `COUNTEREXAMPLE_GLOB` or to any spelling of `[!B]*`. (This sentence said "66
 * files" with no command beside it; neither reading was 66 on the day it was
 * written, and one of them only became 66 because this lane added a file. A
 * count of files in a paragraph about counts without generators is the joke
 * writing itself, so the generator is now written down and the number is
 * allowed to move.)
 *
 * MEASURED on PHP 8.3.6, 2026-08-22: putting "eight" back into the
 * `LayeredSettings` paragraph left that test and its sibling doc-drift suites
 * entirely green. The FIGURE that observation used to carry — `OK (80 tests,
 * 297 assertions)` — is retracted rather than deleted: no command in the tree
 * produces that pair, and the nearest reproducible selection,
 * `vendor/bin/phpunit --filter 'Drift|ReadmeSettingsTierClaim|ThemePersistenceFraming'`,
 * reports 231 tests at round 44. Its assertion count is deliberately not
 * quoted, because this file's own scope change moved it by tens of thousands
 * inside one commit. What survives is the observation, which reproduces: the
 * exact stale figure the round had just retracted could be restored with zero
 * test movement, inside the sentence claiming it could not.
 *
 * THIS FILE IS THAT GENERATOR, and it is deliberately narrow: it does not
 * re-check the behaviour (that is the other test's job and duplicating it would
 * drift), only the arithmetic. Every number either page spells about the glob
 * is derived here from `strlen()` of the glob the page itself quotes, so
 * changing the glob and changing the words have to happen together.
 *
 * AND THE SECOND HALF: a retracted figure is only retracted where someone
 * rewrote it. WHAT THIS PARAGRAPH SAID: that `docs/SETTINGS.md` "keeps a list
 * of the `src/` sites still carrying the old count", which is the only thing
 * that makes the next one findable — and that the list had itself gone stale
 * within the same commit that fixed one of the two sites it named. WHAT IS
 * TRUE NOW: the page keeps no list. Round 44 replaced it with a CARDINALITY —
 * "the census finds zero remaining sites" — precisely because a list of
 * filenames is the thing that went stale, and a number derived from the census
 * cannot. WHY THE PARAGRAPH STILL EARNS ITS PLACE: the property it was
 * defending is the one that matters, and it is now defended by a stronger
 * statement rather than a weaker one.
 * {@see testNothingInScopeStillCarriesTheStaleFigureAndTheSettingsPageAgrees()}
 * censuses `src/` AND `docs/` — `docs/` because that is where round 43's
 * defect actually shipped — so the page reds in BOTH directions: when a new
 * stale copy appears anywhere in scope, and when the last one is fixed and the
 * page's number is not.
 *
 * MEASURED ON PHP 8.3.6 ONLY. This box has no 8.4 while CI runs 8.3 and 8.4;
 * nothing here is version-sensitive — it is `strlen()` over ASCII and a
 * `preg_match()` over prose — and the stamp is provenance.
 *
 * @internal
 */
final class GlobFigureDriftTest extends TestCase
{
    private const SETTINGS_DOC = __DIR__ . '/../../docs/SETTINGS.md';

    /**
     * The counterexample glob both pages argue from.
     *
     * ANCHORED, not asserted about in the abstract:
     * {@see testBothPagesArgueFromTheGlobThisFileMeasures()} requires each page
     * to quote exactly this string, so a constant that drifted away from the
     * documents would red rather than quietly measure a glob nobody writes.
     */
    private const GLOB = '[!B]*';

    /**
     * Number words this file knows how to read, low enough to cover any
     * plausible glob — and `zero`, which is not decoration: the `src/` census
     * below is now empty, and the page's cardinality is spelled from
     * `word(count($census))` in exactly the same way it was spelled when the
     * count was one.
     */
    private const WORDS = [
        0 => 'zero',
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
        7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve',
    ];

    private function word(int $n): string
    {
        $this->assertArrayHasKey($n, self::WORDS, 'the glob left the range this file can spell; extend WORDS');

        return self::WORDS[$n];
    }

    private function settingsPage(): string
    {
        return (string) file_get_contents(self::SETTINGS_DOC);
    }

    private function layeredSettingsSource(): string
    {
        return $this->sourceOf(LayeredSettings::class);
    }

    private function bootstrapSource(): string
    {
        return $this->sourceOf(Bootstrap::class);
    }

    private function sourceOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        self::assertIsString($file, $class . ' has no file on disk, so nothing can be asserted about its prose');
        $text = file_get_contents($file);
        self::assertIsString($text, $file . ' is unreadable, so nothing can be asserted about its prose');

        return $text;
    }

    private function pageText(string $which): string
    {
        return match ($which) {
            'source' => $this->layeredSettingsSource(),
            'bootstrap' => $this->bootstrapSource(),
            'settings' => $this->settingsPage(),
        };
    }

    /**
     * Paragraphs of a doc-block or a markdown page, whitespace-normalised — the
     * same shape {@see ConfigWriteProducerDocumentationDriftTest} and
     * {@see ThemePersistenceFramingTest} use, and for the same reason: the
     * claims here are line-wrapped, so a raw `str_contains()` reports a line
     * break as a defect.
     *
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        $out = [];
        foreach (preg_split('/\n\s*\n/', implode("\n", $lines)) ?: [] as $para) {
            $normalised = trim((string) preg_replace('/\s+/', ' ', $para));
            if ($normalised !== '') {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    /**
     * The ONE paragraph of `$text` containing `$locator`.
     *
     * `assertCount(1, …)` and not "the first hit": a locator that stops being
     * unique is the failure mode round 42 measured and this lane hit again in
     * round 43 — the window silently slides onto a different paragraph and the
     * assertion goes on passing about the wrong prose.
     */
    private function soleParagraphContaining(string $text, string $locator, string $what): string
    {
        $hits = [];
        foreach ($this->paragraphs($text) as $para) {
            if (stripos($para, $locator) !== false) {
                $hits[] = $para;
            }
        }

        $this->assertCount(
            1,
            $hits,
            $what . ': "' . $locator . '" no longer identifies exactly one paragraph, '
            . 'so nothing asserted about it can be trusted to be about the right one',
        );

        return $hits[0];
    }

    // ── the arithmetic ───────────────────────────────────────────────────

    /**
     * The constant above is the glob the documents actually argue from.
     *
     * Without this, every assertion below could be measuring a string that
     * appears nowhere, and would keep passing while the pages talked about
     * something else.
     */
    public function testBothPagesArgueFromTheGlobThisFileMeasures(): void
    {
        $example = '{"disabledTools": ["' . self::GLOB . '"]}';

        $this->assertStringContainsString(
            $example,
            (string) preg_replace('/\s+/', ' ', $this->layeredSettingsSource()),
            'LayeredSettings no longer quotes the counterexample this file measures',
        );
        $this->assertStringContainsString(
            '{ "disabledTools": ["' . self::GLOB . '"] }',
            $this->settingsPage(),
            'docs/SETTINGS.md no longer quotes the counterexample this file measures',
        );
    }

    /**
     * "FIVE characters of glob" — spelled from `strlen()`, on both pages.
     *
     * The word immediately before "characters of glob" is read out of the page
     * and compared to the length of the glob the page quotes. Lengthen the glob
     * without re-counting, or re-count without changing the glob, and this reds.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function pagesThatSpellTheLength(): iterable
    {
        yield 'LayeredSettings PROJECT_TIER_KEYS doc-block' => ['source'];
        yield 'docs/SETTINGS.md' => ['settings'];
    }

    /** @dataProvider pagesThatSpellTheLength */
    public function testTheSpelledGlobLengthIsTheLengthOfTheGlob(string $which): void
    {
        $text = $this->pageText($which);
        $para = $this->soleParagraphContaining($text, 'characters of glob', $which);

        $matched = preg_match_all('/([A-Za-z]+) characters of glob/', $para, $m);
        $this->assertSame(1, $matched, $which . ': the length is spelled more than once in one paragraph');

        $this->assertSame(
            $this->word(\strlen(self::GLOB)),
            strtolower($m[1][0]),
            $which . ': the spelled length and strlen(' . self::GLOB . ') disagree — '
            . 'this is the figure that read "eight" for two rounds',
        );
    }

    /**
     * Every page carrying the retraction, and the phrase that locates it there.
     *
     * THREE PAGES, NOT TWO. `Bootstrap::reportProjectTierToolRemovals()` joined
     * the list in round 44, when its clause "closes the eight-character version
     * and nothing else" was rewritten to name the glob and to carry the same
     * three counts. Its retraction was UNPINNED for the length of one commit —
     * the arithmetic that justified the rewrite lived only in the doc-block it
     * was rewriting — which is the state this whole file exists to prevent.
     *
     * Each row carries its own LOCATOR because the three paragraphs do not open
     * alike, and a locator is deliberately never made of the figure under test:
     * one that was would move with the defect and find nothing to complain
     * about.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pagesThatCarryTheRetraction(): iterable
    {
        yield 'LayeredSettings PROJECT_TIER_KEYS doc-block' => ['source', '"eight characters" until'];
        yield 'docs/SETTINGS.md' => ['settings', '"eight characters" until'];
        yield 'Bootstrap::reportProjectTierToolRemovals() doc-block' => [
            'bootstrap',
            'THAT CLAUSE COUNTED THE GLOB INSTEAD OF NAMING IT',
        ];
    }

    /**
     * And the RETRACTION's arithmetic, which is the part that made the
     * correction believable: `[!B]*` five, `"[!B]*"` seven, `["[!B]*"]` nine.
     *
     * Pinned because a retraction carrying its own wrong numbers is worse than
     * no retraction — it is the sentence a reader stops re-deriving.
     *
     * @dataProvider pagesThatCarryTheRetraction
     */
    public function testTheRetractionsThreeCountsAreStillArithmetic(string $which, string $locator): void
    {
        $text = $this->pageText($which);
        $para = $this->soleParagraphContaining($text, $locator, $which);

        $bare = self::GLOB;
        $quoted = '"' . self::GLOB . '"';
        $listed = '["' . self::GLOB . '"]';

        foreach ([$bare, $quoted, $listed] as $form) {
            $this->assertStringContainsString(
                '`' . $form . '` ' . ($form === $bare ? 'is ' : '') . $this->word(\strlen($form)),
                $para,
                $which . ': the retraction no longer counts `' . $form . '` as '
                . $this->word(\strlen($form)) . ' characters',
            );
        }

        // And the number it retracts must not be one of the three it asserts,
        // or the retraction contradicts itself while reading as a correction.
        $this->assertNotContains(
            8,
            [\strlen($bare), \strlen($quoted), \strlen($listed)],
            $which . ': "eight" is now a correct count for one of these forms, so the retraction is wrong',
        );
    }

    // ── the census of what is still stale ────────────────────────────────

    /**
     * Unicode separators folded onto their ASCII equivalents.
     *
     * WHY THIS EXISTS: the census's number WORD is derived from `word(8)`, so
     * it moves with the glob — but its CONNECTOR was hand-written as `[- ]`,
     * one ASCII hyphen or one ASCII space, and a class that narrow is a list of
     * the spellings whoever wrote it happened to think of. Everything in
     * {@see connectorSpellings()} slipped past it. A census cannot find what
     * its alphabet cannot spell, and this is that alphabet.
     *
     * The dashes fold to `-`, the spaces fold to ` `, and the two INVISIBLE
     * characters (zero-width space, soft hyphen) fold to nothing at all —
     * a soft hyphen is what a word processor leaves behind when it breaks a
     * word, and it renders as either a hyphen or nothing depending on where the
     * line lands, so treating it as an absent separator is the reading that
     * matches what a human sees.
     */
    private function normaliseSeparators(string $text): string
    {
        return strtr($text, [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
            "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-', "\u{FE58}" => '-',
            "\u{FE63}" => '-', "\u{FF0D}" => '-',
            "\u{00A0}" => ' ', "\u{1680}" => ' ', "\u{2000}" => ' ', "\u{2001}" => ' ',
            "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2004}" => ' ', "\u{2005}" => ' ',
            "\u{2006}" => ' ', "\u{2007}" => ' ', "\u{2008}" => ' ', "\u{2009}" => ' ',
            "\u{200A}" => ' ', "\u{202F}" => ' ', "\u{205F}" => ' ', "\u{3000}" => ' ',
            "\u{200B}" => '', "\u{FEFF}" => '', "\u{00AD}" => '',
        ]);
    }

    /**
     * Does this ONE paragraph spell the retracted count without retracting it?
     *
     * TWO RULES, and both are load-bearing.
     *
     * THE MATCH is `\beight`, any run of spaces and hyphens (including none),
     * then `character`. The word is derived from `word(8)`; the separator run
     * is permissive because every narrower spelling of it has already been
     * evaded once — see {@see connectorSpellings()} for the fixture table, in
     * which the OLD `[- ]` class misses ten of twelve true positives. The `\b`
     * is not cosmetic either: without it the old pattern matched
     * `weight-character`, so the census over-reported as well as under-reported.
     *
     * THE RETRACTION EXEMPTION is a paragraph that also spells the CURRENT
     * count. It is semantic rather than keyed to a filename on purpose: both
     * surviving mentions of "eight" in `src/` live inside sentences retracting
     * it, and an exemption list naming those files would have to be edited
     * every time one of them was fixed — which is precisely the maintenance
     * step round 43 skipped and shipped stale.
     */
    private function carriesTheStaleFigure(string $paragraph): bool
    {
        $normalised = (string) preg_replace('/\s+/', ' ', $this->normaliseSeparators($paragraph));

        $stale = $this->matchOrFail('/\b' . $this->word(8) . '[\s\-]*character/i', $normalised, 'stale-figure probe');
        if (!$stale) {
            return false;
        }

        return !$this->matchOrFail('/\b' . $this->word(\strlen(self::GLOB)) . '\b/i', $normalised, 'retraction probe');
    }

    /**
     * `preg_match()` that FAILS on a compile or backtrack error rather than
     * reading `false` as "no".
     *
     * A guard must go red on what it cannot parse. The previous census wrote
     * `if (preg_match(…) !== 1) { continue; }`, which treats `false` — a PCRE
     * error, a backtrack-limit blowout on a long paragraph — as a clean miss,
     * and would have reported an empty census for a reason that has nothing to
     * do with the tree being clean.
     */
    private function matchOrFail(string $pattern, string $subject, string $what): bool
    {
        $result = preg_match($pattern, $subject);
        if ($result === false) {
            // `fail()` rather than `assertIsInt()`: this runs once per paragraph
            // of every file in scope, and an assertion per call added ~34,000
            // to the suite's assertion count while pinning nothing that the
            // failure path does not already pin.
            $this->fail($what . ': preg_match() errored (' . preg_last_error_msg() . '), so its answer means nothing');
        }

        return $result === 1;
    }

    /**
     * The census, over any set of labelled texts.
     *
     * Parameterised rather than hard-wired to `src/` so the SAME scanner can be
     * run over fixtures whose answer is known. That is the whole defence for an
     * assertion that the real census is empty: `assertSame([], …)` also passes
     * in a tree where the scanner has silently stopped matching anything.
     *
     * @param iterable<string, string> $texts label => text
     *
     * @return array<string, int> label => count of carrying paragraphs
     */
    private function census(iterable $texts): array
    {
        $hits = [];
        foreach ($texts as $label => $text) {
            foreach ($this->paragraphs($text) as $paragraph) {
                if ($this->carriesTheStaleFigure($paragraph)) {
                    $hits[$label] = ($hits[$label] ?? 0) + 1;
                }
            }
        }
        ksort($hits);

        return $hits;
    }

    /**
     * Everything the census reads: every `.php` file under `src/` and every
     * `.md` page under `docs/`, keyed by repo-relative path.
     *
     * `docs/` IS IN SCOPE, AND THAT IS THE CORRECTION ROUND 44 MADE. The
     * round-43 census walked `src/` only, and the defect it was written to
     * catch had shipped in `docs/SETTINGS.md` — a sentence naming
     * `LayeredSettings` as still stale two paragraphs after the commit that
     * fixed it. A census scoped away from the place the last defect appeared is
     * a census aimed at the wrong wall.
     *
     * WHAT THE SCOPE AND THE EXEMPTION EACH EXCLUDE — written down because this
     * was the load-bearing half and it was implicit. Measured on PHP 8.3.6,
     * 2026-08-22: `grep -rn 'eight[- ]character'` over `src/`, `docs/`,
     * `README.md` and `tests/` hits five files. Twelve of the hits are in THIS
     * file, which is the census's own fixture alphabet; of the other four:
     *
     * - `src/Cli/Bootstrap.php` and `src/Config/LayeredSettings.php` — both IN
     *   scope, both excluded SEMANTICALLY by {@see carriesTheStaleFigure()}'s
     *   retraction rule, because each paragraph spells the current count too.
     *   These are rule-7 citations, not stale figures, and they must survive.
     * - `docs/SETTINGS.md` — also in scope now, also exempt for the same
     *   semantic reason. It used to be excluded by scope, which is exactly why
     *   a stale sentence could live there unremarked.
     * - `tests/App/AppModelTest.php` — excluded BY SCOPE, and the only thing
     *   scope still excludes. Its "eight characters of tail" is about a cursor
     *   fixture and has nothing to do with the glob. It is also the shape that
     *   shows scope doing real work: the same sentence inside `src/` WOULD be a
     *   false positive, because the match is on the phrase, not the subject.
     *
     * `README.md` carries no spelling of the figure at all (measured: the word
     * "eight" does not occur in it), so admitting it would neither add nor
     * remove a hit today; it is left out because it belongs to a different
     * lane's file set this round, and its absence is a gap rather than a rule.
     *
     * @return array<string, string>
     */
    private function censusScope(): array
    {
        $lib = realpath(__DIR__ . '/../..');
        self::assertIsString($lib);

        $scope = [];
        foreach ([['src', 'php'], ['docs', 'md']] as [$subdir, $extension]) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib . '/' . $subdir));
            foreach ($walk as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== $extension) {
                    continue;
                }
                $scope[substr($file->getPathname(), \strlen($lib) + 1)] = self::readOrFail($file->getPathname());
            }
        }
        ksort($scope);

        return $scope;
    }

    /**
     * The scope really does contain both halves.
     *
     * THE GUARD THIS REPLACES COULD NOT WORK, and the way it failed is worth
     * keeping: the `docs/` half used to end with
     * `assertNotSame([], $pages, 'docs/ produced no pages…')` — an assertion
     * INSIDE the branch it was meant to protect. Deleting the branch, which is
     * the realistic regression (someone narrows the scanner back to `src/`),
     * deleted the assertion with it and left the suite green while the census
     * silently stopped reading 778 assertions' worth of pages. A guard has to
     * live outside the thing it guards.
     *
     * THE FLOORS ARE FLOORS, not counts: 288 `.php` files under `src/` and 12
     * `.md` pages under `docs/` at round 44, measured by this method. A count
     * would red on every added file; a floor reds only when a half collapses,
     * which is the defect.
     *
     * `docs/` IS WALKED RECURSIVELY, though it is flat today. The doc-block
     * below says "every `.md` page under `docs/`" while the previous
     * implementation globbed `docs/*.md` — true of the tree, not of the
     * sentence, and the first `docs/reference/` page anyone adds would have
     * been out of scope without anything saying so.
     */
    public function testTheCensusReadsBothHalvesOfItsScope(): void
    {
        $scope = $this->censusScope();

        foreach (['src/Config/LayeredSettings.php', 'src/Cli/Bootstrap.php', 'docs/SETTINGS.md'] as $required) {
            $this->assertArrayHasKey(
                $required,
                $scope,
                $required . ' is not in the census scope, so the census says nothing about the file '
                . 'this whole instrument was built around',
            );
            $this->assertNotSame('', $scope[$required], $required . ' was read as empty text');
        }

        $src = array_filter($scope, static fn (string $k): bool => str_starts_with($k, 'src/'), \ARRAY_FILTER_USE_KEY);
        $docs = array_filter($scope, static fn (string $k): bool => str_starts_with($k, 'docs/'), \ARRAY_FILTER_USE_KEY);

        $this->assertGreaterThanOrEqual(200, \count($src), 'the src/ half of the census scope has collapsed');
        $this->assertGreaterThanOrEqual(8, \count($docs), 'the docs/ half of the census scope has collapsed');
        $this->assertSame(
            \count($scope),
            \count($src) + \count($docs),
            'the scope grew a third half nothing in this file describes',
        );
    }

    private static function readOrFail(string $path): string
    {
        $text = file_get_contents($path);
        self::assertIsString($text, $path . ' is unreadable, so the census over it is void');

        return $text;
    }

    /**
     * Every connector spelling between "eight" and "character", with what the
     * OLD hand-written `[- ]` class did and what the current one does.
     *
     * MEASURED ON PHP 8.3.6, 2026-08-22, by running both patterns over these
     * exact fixtures; the table is generated by
     * {@see testTheConnectorClassCatchesTheSpellingsTheHandWrittenOneMissed()}
     * rather than transcribed, so it cannot drift into decoration. `strtr()`
     * and a non-`/u` `preg_match()` over these byte sequences behave the same
     * on 8.3 and 8.4 — the stamp is provenance, and CI runs both.
     *
     * | fixture                       | OLD `[- ]` | current |
     * |-------------------------------|------------|---------|
     * | ASCII hyphen                  | HIT        | HIT     |
     * | ASCII space                   | HIT        | HIT     |
     * | U+2010 hyphen                 | miss       | HIT     |
     * | U+2011 non-breaking hyphen    | miss       | HIT     |
     * | U+2013 en dash                | miss       | HIT     |
     * | U+2014 em dash                | miss       | HIT     |
     * | U+2212 minus sign             | miss       | HIT     |
     * | U+00A0 no-break space         | miss       | HIT     |
     * | U+202F narrow no-break space  | miss       | HIT     |
     * | U+200B zero-width space       | miss       | HIT     |
     * | U+00AD soft hyphen            | miss       | HIT     |
     * | hyphenated across doc lines   | miss       | HIT     |
     * | "eighteen-character" (control)| miss       | miss    |
     * | "weight-character" (control)  | HIT        | miss    |
     * | "eight words … a character"   | miss       | miss    |
     *
     * The last three are controls, and the `weight-character` row is the one
     * worth pausing on: the old class had no word boundary, so it did not only
     * under-report, it also matched a word that merely ENDS in "eight".
     *
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     *                                                fixture => [paragraph, current census sees it, old `[- ]` class saw it]
     */
    public static function connectorSpellings(): iterable
    {
        yield 'ASCII hyphen' => ['closes the eight-character version', true, true];
        yield 'ASCII space' => ['closes the eight characters version', true, true];
        yield 'U+2010 hyphen' => ["closes the eight\u{2010}character version", true, false];
        yield 'U+2011 non-breaking hyphen' => ["closes the eight\u{2011}character version", true, false];
        yield 'U+2013 en dash' => ["closes the eight\u{2013}character version", true, false];
        yield 'U+2014 em dash' => ["closes the eight\u{2014}character version", true, false];
        yield 'U+2212 minus sign' => ["closes the eight\u{2212}character version", true, false];
        yield 'U+00A0 no-break space' => ["closes the eight\u{00A0}characters version", true, false];
        yield 'U+202F narrow no-break space' => ["closes the eight\u{202F}characters version", true, false];
        yield 'U+200B zero-width space' => ["closes the eight\u{200B}character version", true, false];
        yield 'U+00AD soft hyphen' => ["closes the eight\u{00AD}character version", true, false];
        yield 'hyphenated across doc lines' => ["     * closes the eight-\n     * character version", true, false];
        yield 'control: eighteen-character' => ['closes the eighteen-character version', false, false];
        yield 'control: weight-character' => ['a weight-character encoding', false, true];
        yield 'control: eight words about a character' => ['eight words about a character set', false, false];
    }

    /**
     * @dataProvider connectorSpellings
     */
    public function testTheConnectorClassCatchesTheSpellingsTheHandWrittenOneMissed(
        string $fixture,
        bool $mustBeSeen,
        bool $oldSawIt,
    ): void {
        $this->assertSame(
            $mustBeSeen ? ['fixture' => 1] : [],
            $this->census(['fixture' => $fixture]),
            'the census disagrees with the table in connectorSpellings()',
        );
    }

    /**
     * The old `[- ]` class really did miss these — asserted, not remembered.
     *
     * WITHOUT THIS, the table above is a claim about a pattern that no longer
     * exists anywhere in the tree, which is the kind of sentence that gets
     * copied forward until someone re-narrows the class believing nothing was
     * ever wrong with it. Here the old pattern is reconstructed and run.
     *
     * @dataProvider connectorSpellings
     */
    public function testTheHandWrittenConnectorClassIsMeasurablyWeakerThanTheCurrentOne(
        string $fixture,
        bool $ignoredCurrent,
        bool $oldSawIt,
    ): void {
        $seenByOld = false;
        foreach ($this->paragraphs($fixture) as $paragraph) {
            // The pre-round-44 census's matcher, verbatim: one ASCII hyphen or
            // one ASCII space, and no word boundary in front of the number word.
            $seenByOld = $seenByOld || preg_match('/eight[- ]character/i', $paragraph) === 1;
        }

        $this->assertSame(
            $oldSawIt,
            $seenByOld,
            'the third column of the table in connectorSpellings() no longer describes what the '
            . 'hand-written class did, so the case for widening it is being made from a stale figure',
        );
    }

    /**
     * Nothing in scope is stale, `docs/SETTINGS.md` says so, and the scanner
     * still works.
     *
     * THREE ASSERTIONS AND NOT ONE, because an empty census is a strictly
     * weaker guard than a census of one. `assertSame([], …)` passes in a tree
     * where the retracted figure is everywhere and the scanner is broken, so
     * the same scanner is run over a known-stale fixture and a known-retracted
     * fixture in the same test — a positive control and a negative control for
     * an assertion whose real result is "nothing".
     *
     * IT STILL REDS IN BOTH DIRECTIONS. A new `src/` or `docs/` paragraph
     * spelling the old count reds the first assertion. Re-introducing the figure in a paragraph
     * that also retracts it does NOT red — that is the retraction exemption
     * working, and the negative control pins it. And `docs/SETTINGS.md`
     * disagreeing with the census's cardinality reds the third.
     */
    public function testNothingInScopeStillCarriesTheStaleFigureAndTheSettingsPageAgrees(): void
    {
        // POSITIVE CONTROL, first: if this does not fire, nothing below means
        // anything, because the emptiness would be the scanner's and not the
        // tree's.
        $this->assertSame(
            ['known-stale.php' => 1],
            $this->census([
                'known-stale.php' => "/**\n * closes the eight-character version and nothing else.\n */",
            ]),
            'the census scanner no longer finds a paragraph it is meant to find, '
            . 'so its verdict on src/ is worthless',
        );

        // NEGATIVE CONTROL: the shape LayeredSettings actually has.
        $this->assertSame(
            [],
            $this->census([
                'known-retracted.php' => "/**\n * THE COUNT SAID \"eight characters\" until it was re-derived:\n"
                    . " * `[!B]*` is five, so the figure is corrected here.\n */",
            ]),
            'the retraction exemption stopped working, so every rule-7 citation of the old '
            . 'figure would now be reported as a fresh defect',
        );

        $census = $this->census($this->censusScope());

        $this->assertSame(
            [],
            $census,
            'a src/ or docs/ paragraph spells the old glob length without retracting it; '
            . "fix it and move docs/SETTINGS.md's cardinality in the same commit",
        );

        $para = $this->soleParagraphContaining($this->settingsPage(), 'remaining site', 'docs/SETTINGS.md');

        $this->assertStringContainsString(
            'the census finds ' . $this->word(\count($census)) . ' remaining site',
            $para,
            'docs/SETTINGS.md no longer states the number of src/ sites the census actually finds',
        );
    }
}
