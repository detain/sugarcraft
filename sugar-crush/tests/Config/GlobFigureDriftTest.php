<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Tests\Config\Support\DocumentParagraphs;

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
     * The shared paragraph window.
     *
     * WHAT THIS SAID: three suites each carried a byte-identical private
     * `paragraphs()` with its own copy of the same justification — the claims
     * being checked are line-wrapped, so a raw `str_contains()` reports a line
     * break as a defect.
     *
     * WHAT IS TRUE NOW: that justification still holds and has not been
     * deleted; it moved, in full and with the measurements behind it, to
     * {@see DocumentParagraphs}. What changed there is the rule itself — a
     * fenced code block, a table row and a list item are each their own unit
     * now, because markdown puts no blank line between them and the old rule
     * therefore handed every guard one unit where a reader sees several.
     *
     * WHY THIS METHOD STILL EARNS ITS PLACE: it is the seam. Every call site
     * reads `$this->paragraphs(...)`, so the window can be changed in one place
     * and the change measured against
     * {@see \SugarCraft\Crush\Tests\Config\DocumentParagraphsTest}'s fixture
     * table rather than three times by hand.
     *
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        return DocumentParagraphs::of($text);
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
     * {@see staleFigureSpellings()} slipped past it. A census cannot find what
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
     * THREE RULES, and all three are load-bearing.
     *
     * THE NUMBER is `(?:eight|8)` — the word from `word(8)` and the numeral
     * from `(string) 8`, so both move with the glob. It was the WORD ALONE for
     * one round, and that is the same class of gap the connector had: a census
     * whose alphabet admits one spelling of its subject reports zero because it
     * cannot say the other one. A reviewer observed both injections survive at
     * `1f10b622` — `closes the 8-character version and nothing else` put into
     * `docs/HOOKS.md` and into `src/Cli/Bootstrap.php`, green both times — and
     * the arithmetic is re-derived here rather than taken on the report:
     * `preg_match('/\beight[\s\-]*character/i', 'closes the 8-character
     * version')` is 0 on PHP 8.3.6, so the old alphabet could not see either
     * injection at all. The tree already writes numerals for figures of exactly
     * this kind.
     *
     * THE NOUN is `char`, `chars`, `character`, `characters` — and `byte`,
     * `bytes` for the WORD form only. The asymmetry is measured, not tidy.
     * Admitting `bytes?` after the numeral reports
     * `src/Tools/Concerns/TruncatesOutput.php`, whose "an 8-byte `# BIG-RU`" is
     * a correct statement about a truncation head and has nothing to do with
     * any glob; admitting it without the lookbehind below reports ten more
     * paragraphs, every one of them the phrase "UTF-8 byte". A census that
     * reports true sentences as defects gets an exemption list bolted onto it,
     * and an exemption list is what went stale in round 43.
     *
     * THE LOOKBEHIND `(?<![\w.\-])` in front of the numeral is what keeps
     * "UTF-8" and "PHP 8.4" from being the number eight. `\b` does not do it:
     * the `8` of `UTF-8` is preceded by a hyphen, which is a non-word
     * character, so `\b8` matches there.
     *
     * THE SEPARATOR run is `[\s\-_]*` — permissive because every narrower
     * spelling has already been evaded once; see {@see staleFigureSpellings()}
     * for the fixture table, in which the OLD `[- ]` class misses fifteen of
     * eighteen true positives. `_` is in the class because `eight_character` is
     * a spelling an identifier produces.
     *
     * THE RETRACTION EXEMPTION is a paragraph that spells the CURRENT count AND
     * quotes the glob. It is semantic rather than keyed to a filename on
     * purpose: all three surviving mentions of "eight" in scope live inside
     * sentences retracting it, and an exemption list naming those files would
     * have to be edited every time one was fixed — precisely the maintenance
     * step round 43 skipped and shipped stale. QUOTING THE GLOB WAS ADDED IN
     * ROUND 44, because "five" on its own is an ordinary English word:
     * `closes the eight-character version; there are five reasons this matters`
     * was silently exempt, and "no such paragraph exists" was a statement about
     * that day's tree rather than a property of the rule. All three real
     * retractions quote `[!B]*`; a paragraph that retracts the count without
     * naming what was counted is not a retraction a reader can check.
     */
    private function carriesTheStaleFigure(string $paragraph): bool
    {
        $normalised = (string) preg_replace('/\s+/', ' ', $this->normaliseSeparators($paragraph));

        if (!$this->matchOrFail($this->stalePattern(), $normalised, 'stale-figure probe')) {
            return false;
        }

        return !$this->retracts($normalised);
    }

    /**
     * The stale-figure pattern, derived from the glob rather than written out.
     *
     * Every part that could rot is generated: the number word from
     * {@see word()}, the numeral from the same integer, and the exemption's
     * word from `strlen(self::GLOB)`. Change the glob and the whole alphabet
     * moves with it.
     */
    private function stalePattern(): string
    {
        $separator = '[\s\-_]*';
        $characters = 'char(?:acter)?s?';

        return '/(?:'
            . '\b' . $this->word(8) . $separator . '(?:' . $characters . '|bytes?)'
            . '|(?<![\w.\-])' . 8 . $separator . '(?:' . $characters . ')'
            . ')\b/i';
    }

    /** Does this paragraph retract the figure rather than assert it? */
    private function retracts(string $normalised): bool
    {
        $spellsTheCurrentCount = $this->matchOrFail(
            '/\b' . $this->word(\strlen(self::GLOB)) . '\b/i',
            $normalised,
            'retraction probe',
        );

        return $spellsTheCurrentCount && str_contains($normalised, self::GLOB);
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
     * was the load-bearing half and it was implicit. RE-MEASURED at round 44
     * with the widened alphabet, on PHP 8.3.6, by
     * `grep -rlP '(?i)(?:\beight[\s\-_]*(?:char(?:acter)?s?|bytes?)|(?<![\w.\-])8[\s\-_]*char(?:acter)?s?)\b'`
     * over `src/`, `docs/`, `tests/` and `README.md` — seven files, up from the
     * five the old `eight[- ]character` grep found:
     *
     * - `src/Cli/Bootstrap.php` and `src/Config/LayeredSettings.php` — both IN
     *   scope, both excluded SEMANTICALLY by {@see carriesTheStaleFigure()}'s
     *   retraction rule, because each paragraph spells the current count AND
     *   quotes the glob. These are rule-7 citations, not stale figures, and
     *   they must survive.
     * - `docs/SETTINGS.md` — also in scope, also exempt for the same semantic
     *   reason. It used to be excluded by scope, which is exactly why a stale
     *   sentence could live there unremarked.
     * - `tests/App/AppModelTest.php` and
     *   `tests/Cli/BootstrapToolAndPermissionSettingsTest.php` — excluded BY
     *   SCOPE. The first's "eight characters of tail" is about a cursor
     *   fixture; both are the shape that shows scope doing real work, because
     *   the match is on the phrase, not on the subject, so the same sentence
     *   inside `src/` WOULD be reported.
     * - `src/Skills/BuiltIn/api-design/SKILL.md` — a NEW exclusion, and the
     *   only one the widened alphabet added. It lives under `src/` but is not a
     *   `.php` file, so the extension filter drops it. Its "at least 8
     *   characters" is an example password-policy message. Admitting `src/**.md`
     *   would report it, which is the measured reason the `src/` half is
     *   `.php`-only rather than everything-under-`src/`.
     * - THIS file — the census's own fixture alphabet, in scope and reported by
     *   nothing, because `tests/` is out of scope.
     *
     * `README.md` carries no spelling of the figure at all (measured with the
     * widened pattern above: no hit), so admitting it would neither add nor
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
     * Every spelling of the retracted figure this census must see, with what
     * the OLD hand-written matcher did and what the current alphabet does.
     *
     * MEASURED ON PHP 8.3.6, 2026-08-22, by running both patterns over these
     * exact fixtures; the table is generated by
     * {@see testTheCensusAlphabetCatchesTheSpellingsTheHandWrittenOneMissed()}
     * and its tallies by
     * {@see testTheTableMeasuresHowMuchWiderTheAlphabetGot()}, rather than
     * transcribed, so it cannot drift into decoration. `strtr()` and a non-`/u`
     * `preg_match()` over these byte sequences behave the same on 8.3 and 8.4 —
     * the stamp is provenance, and CI runs both.
     *
     * THE TABLE HAS TWO HALVES because the alphabet has two axes, and round 44
     * widened the second after round 43 widened the first. The CONNECTOR rows
     * came first; the NUMBER rows are the gap that widening left behind — the
     * census could spell "eight" every way the connector rows list, and "8" not
     * at all.
     *
     * | fixture                        | OLD `[- ]` | current |
     * |--------------------------------|------------|---------|
     * | ASCII hyphen                   | HIT        | HIT     |
     * | ASCII space                    | HIT        | HIT     |
     * | U+2010 hyphen                  | miss       | HIT     |
     * | U+2011 non-breaking hyphen     | miss       | HIT     |
     * | U+2013 en dash                 | miss       | HIT     |
     * | U+2014 em dash                 | miss       | HIT     |
     * | U+2212 minus sign              | miss       | HIT     |
     * | U+00A0 no-break space          | miss       | HIT     |
     * | U+202F narrow no-break space   | miss       | HIT     |
     * | U+200B zero-width space        | miss       | HIT     |
     * | U+00AD soft hyphen             | miss       | HIT     |
     * | hyphenated across doc lines    | miss       | HIT     |
     * | underscore separator           | miss       | HIT     |
     * | numeral, ASCII hyphen          | miss       | HIT     |
     * | numeral, ASCII space           | miss       | HIT     |
     * | short noun "eight-char"        | miss       | HIT     |
     * | noun "eight bytes"             | miss       | HIT     |
     * | retraction without the glob    | HIT        | HIT     |
     * | retraction quoting the glob    | HIT        | miss    |
     * | "eighteen-character" (control) | miss       | miss    |
     * | "weight-character" (control)   | HIT        | miss    |
     * | "eight words … a character"    | miss       | miss    |
     * | "UTF-8 bytes" (control)        | miss       | miss    |
     * | "an 8-byte head" (control)     | miss       | miss    |
     * | "PHP 8.4 characters" (control) | miss       | miss    |
     *
     * THE CONTROLS ARE THE HALF WORTH PAUSING ON. `weight-character` is what a
     * missing word boundary did: the old class under-reported AND matched a
     * word that merely ends in "eight". `UTF-8 bytes` and `PHP 8.4 characters`
     * are what a missing lookbehind would do to the numeral form — ten
     * paragraphs of the first phrase exist in `src/` today. `an 8-byte head` is
     * the measured reason `bytes?` is admitted after the word and not after the
     * numeral. And the two retraction rows are the exemption: identical
     * sentences apart from whether they quote the glob they are retracting a
     * count of.
     *
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     *                                                fixture => [paragraph, current census sees it, old `[- ]` class saw it]
     */
    public static function staleFigureSpellings(): iterable
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
        // A COMPLETE DOC-BLOCK, NOT TWO LEADER-PREFIXED LINES, and the wrapper
        // is load-bearing rather than decoration. DocumentParagraphs' leader
        // strip became conditional on the text being PHP, because on markdown
        // that strip ate the first asterisk of every line-leading `**bold**`
        // lead. A bare fragment is not PHP by that test, so the leaders would
        // survive and ` * ` would sit between `eight-` and `character` — a
        // separator the alphabet does not contain, and this row would report a
        // miss for a spelling the census really does catch. In the tree the
        // shape only ever arrives inside a whole `.php` file, so the wrapper is
        // what makes the fixture faithful to it.
        yield 'hyphenated across doc lines' => [
            "/**\n     * closes the eight-\n     * character version\n     */",
            true,
            false,
        ];
        yield 'underscore separator' => ['closes the eight_character version', true, false];
        yield 'numeral, ASCII hyphen' => ['closes the 8-character version and nothing else', true, false];
        yield 'numeral, ASCII space' => ['closes the 8 characters version and nothing else', true, false];
        yield 'short noun: eight-char' => ['the eight-char spelling is the one it closes', true, false];
        yield 'noun: eight bytes' => ['a fixed eight bytes of glob is all it takes', true, false];
        yield 'retraction without the glob' => [
            'closes the eight-character version; there are five reasons this matters',
            true,
            true,
        ];
        yield 'retraction quoting the glob' => [
            'THE COUNT SAID "eight characters" until it was re-derived: `[!B]*` is five.',
            false,
            true,
        ];
        yield 'control: eighteen-character' => ['closes the eighteen-character version', false, false];
        yield 'control: weight-character' => ['a weight-character encoding', false, true];
        yield 'control: eight words about a character' => ['eight words about a character set', false, false];
        yield 'control: UTF-8 bytes' => ['matched on UTF-8 bytes rather than on a decoded codepoint', false, false];
        yield 'control: an 8-byte head' => ['an 8-byte `# BIG-RU` where the head path gives `# BIG-RULE`', false, false];
        yield 'control: PHP 8.4 characters' => ['PHP 8.4 characters are handled the same way', false, false];
    }

    /**
     * @dataProvider staleFigureSpellings
     */
    public function testTheCensusAlphabetCatchesTheSpellingsTheHandWrittenOneMissed(
        string $fixture,
        bool $mustBeSeen,
        bool $oldSawIt,
    ): void {
        $this->assertSame(
            $mustBeSeen ? ['fixture' => 1] : [],
            $this->census(['fixture' => $fixture]),
            'the census disagrees with the table in staleFigureSpellings()',
        );
    }

    /**
     * The old `[- ]` matcher really did miss these — asserted, not remembered.
     *
     * WITHOUT THIS, the table above is a claim about a pattern that no longer
     * exists anywhere in the tree, which is the kind of sentence that gets
     * copied forward until someone re-narrows the alphabet believing nothing
     * was ever wrong with it. Here the old pattern is reconstructed and run.
     *
     * The two RETRACTION rows are in this table too, and their third column
     * still means what it says: the old raw matcher had no exemption at all, so
     * it matched both of them. That is a fact about the matcher, not about the
     * exemption, which is why the rows belong here rather than in a table of
     * their own.
     *
     * @dataProvider staleFigureSpellings
     */
    public function testTheHandWrittenMatcherIsMeasurablyWeakerThanTheCurrentAlphabet(
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
            'the third column of the table in staleFigureSpellings() no longer describes what the '
            . 'hand-written matcher did, so the case for widening it is being made from a stale figure',
        );
    }

    /**
     * How much wider the alphabet actually got, as a number with a generator.
     *
     * The doc-block above says the old matcher misses most of what the current
     * one catches. That is a FIGURE, and a figure in prose beside a table it
     * summarises is the exact failure this whole file exists to stop, so it is
     * counted from the table rather than asserted by eye. Adding a row makes
     * this red until the sentence is updated with it, which is the point.
     */
    public function testTheTableMeasuresHowMuchWiderTheAlphabetGot(): void
    {
        $truePositives = 0;
        $oldSawThem = 0;
        $oldFalsePositives = 0;

        foreach (self::staleFigureSpellings() as [$fixture, $mustBeSeen, $oldSawIt]) {
            if ($mustBeSeen) {
                $truePositives++;
                $oldSawThem += $oldSawIt ? 1 : 0;
            } elseif ($oldSawIt) {
                $oldFalsePositives++;
            }
        }

        $this->assertSame(18, $truePositives, 'the table grew or shrank; update the sentence that counts it');
        $this->assertSame(3, $oldSawThem, 'the old matcher now sees a different number of the true positives');
        $this->assertSame(15, $truePositives - $oldSawThem, 'the "misses fifteen of eighteen" sentence is stale');
        $this->assertSame(2, $oldFalsePositives, 'the old matcher over-reported on a different number of controls');
    }

    /**
     * THE POSITIVE CONTROL, and it is a test of its own rather than the first
     * assertion of the census test.
     *
     * It used to be exactly that, and the arrangement hid something: a reviewer
     * mutating away the retraction exemption saw ONE failure, because the
     * control assertions ran first in the same method and PHPUnit stops at the
     * first one. The real census — the assertion the mutation was aimed at —
     * was never reached, so the evidence offered for the guard was evidence
     * from a different assertion. Separate methods make every mutation's
     * failure count mean what it looks like it means.
     *
     * WHY A CONTROL AT ALL: `assertSame([], …)` over the real scope also passes
     * in a tree where the retracted figure is everywhere and the scanner has
     * quietly stopped matching. The same scanner is therefore run over a
     * fixture whose answer is known.
     */
    public function testTheCensusScannerStillFindsAKnownStaleParagraph(): void
    {
        $this->assertSame(
            ['known-stale.php' => 1],
            $this->census([
                'known-stale.php' => "/**\n * closes the eight-character version and nothing else.\n */",
            ]),
            'the census scanner no longer finds a paragraph it is meant to find, '
            . 'so its verdict on src/ and docs/ is worthless',
        );
    }

    /**
     * THE NEGATIVE CONTROL: the shape `LayeredSettings` actually has.
     *
     * Without it, tightening the exemption into uselessness would report every
     * rule-7 citation of the old figure as a fresh defect, and the census test
     * would red for a reason that has nothing to do with the tree.
     */
    public function testTheRetractionExemptionSparesAKnownRetractedParagraph(): void
    {
        $this->assertSame(
            [],
            $this->census([
                'known-retracted.php' => "/**\n * THE COUNT SAID \"eight characters\" until it was re-derived:\n"
                    . " * `[!B]*` is five, so the figure is corrected here.\n */",
            ]),
            'the retraction exemption stopped working, so every rule-7 citation of the old '
            . 'figure would now be reported as a fresh defect',
        );
    }

    /**
     * Nothing in scope is stale, and `docs/SETTINGS.md` says the same number.
     *
     * IT REDS IN BOTH DIRECTIONS. A new `src/` or `docs/` paragraph spelling
     * the old count reds the first assertion; the last stale copy being fixed
     * without the page's cardinality moving reds the second. The two controls
     * above keep both honest — this test's own result is "nothing", and an
     * assertion of nothing is only worth what the scanner behind it is worth.
     */
    public function testNothingInScopeStillCarriesTheStaleFigureAndTheSettingsPageAgrees(): void
    {
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
            'docs/SETTINGS.md no longer states the number of sites the census actually finds',
        );
    }
}
