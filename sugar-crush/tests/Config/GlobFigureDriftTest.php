<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
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
 * and calls `strlen()` on nothing; no `strlen()` appears anywhere under
 * `tests/`. MEASURED on PHP 8.3.6, 2026-08-22: putting "eight" back into the
 * `LayeredSettings` paragraph left that test and its five sibling doc-drift
 * suites at `OK (80 tests, 297 assertions)`. The exact stale figure the round
 * had just retracted could be restored with zero test movement, inside the
 * sentence claiming it could not.
 *
 * THIS FILE IS THAT GENERATOR, and it is deliberately narrow: it does not
 * re-check the behaviour (that is the other test's job and duplicating it would
 * drift), only the arithmetic. Every number either page spells about the glob
 * is derived here from `strlen()` of the glob the page itself quotes, so
 * changing the glob and changing the words have to happen together.
 *
 * AND THE SECOND HALF: a retracted figure is only retracted where someone
 * rewrote it. `docs/SETTINGS.md` keeps a list of the `src/` sites still
 * carrying the old count, which is the only thing that makes the next one
 * findable — and that list had itself gone stale within the same commit that
 * fixed one of the two sites it named.
 * {@see testTheSettingsPageNamesExactlyTheSourceFilesStillCarryingTheStaleFigure()}
 * censuses `src/` for it, so the list reds in BOTH directions: when a new stale
 * copy appears, and when a named one is finally fixed and the list is not.
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

    /** Number words this file knows how to read, low enough to cover any plausible glob. */
    private const WORDS = [
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
        return (string) file_get_contents((string) (new \ReflectionClass(LayeredSettings::class))->getFileName());
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
        $text = $which === 'source' ? $this->layeredSettingsSource() : $this->settingsPage();
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
     * And the RETRACTION's arithmetic, which is the part that made the
     * correction believable: `[!B]*` five, `"[!B]*"` seven, `["[!B]*"]` nine.
     *
     * Pinned because a retraction carrying its own wrong numbers is worse than
     * no retraction — it is the sentence a reader stops re-deriving.
     *
     * @dataProvider pagesThatSpellTheLength
     */
    public function testTheRetractionsThreeCountsAreStillArithmetic(string $which): void
    {
        $text = $which === 'source' ? $this->layeredSettingsSource() : $this->settingsPage();
        // Located by the retraction's OPENING, not by any of the numbers it
        // asserts: a locator made of the figure under test would move with the
        // defect and find nothing to complain about.
        $para = $this->soleParagraphContaining($text, '"' . $this->word(8) . ' characters" until', $which);

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
     * Every `src/` paragraph that spells the OLD count without retracting it.
     *
     * PARAGRAPH-SCOPED and not file-scoped: {@see LayeredSettings} legitimately
     * contains the word "eight" inside the sentence retracting it, and a file
     * census would either flag the retraction or need an exemption keyed to a
     * filename — which is the exemption that goes stale the moment the file is
     * fixed. The rule instead is semantic: a paragraph that says the old figure
     * and does not also say the new one is carrying it.
     *
     * @return array<string, int>
     */
    private function paragraphsStillCarryingTheStaleFigure(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        $stale = $this->word(8);
        $current = $this->word(\strlen(self::GLOB));

        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->paragraphs((string) file_get_contents($file->getPathname())) as $para) {
                if (preg_match('/' . $stale . '[- ]character/i', $para) !== 1) {
                    continue;
                }
                if (preg_match('/\b' . $current . '\b/i', $para) === 1) {
                    continue;
                }
                $rel = 'src/' . ltrim(str_replace($root, '', $file->getPathname()), '/');
                $hits[$rel] = ($hits[$rel] ?? 0) + 1;
            }
        }
        ksort($hits);

        return $hits;
    }

    /**
     * The census, and `docs/SETTINGS.md`'s list of known-stale copies, must be
     * the same set.
     *
     * THIS REDS IN BOTH DIRECTIONS, which is the whole point. A new `src/`
     * paragraph spelling the old count reds it. So does FIXING the one that is
     * left without updating the page that advertises it as outstanding — the
     * exact defect that shipped in round 43, where the commit correcting
     * `LayeredSettings` left `docs/SETTINGS.md` naming `LayeredSettings` as
     * still stale two paragraphs later.
     */
    public function testTheSettingsPageNamesExactlyTheSourceFilesStillCarryingTheStaleFigure(): void
    {
        $census = $this->paragraphsStillCarryingTheStaleFigure();

        $this->assertSame(
            ['src/Cli/Bootstrap.php' => 1],
            $census,
            'the set of src/ paragraphs still spelling the old glob length has changed; '
            . "update docs/SETTINGS.md's list of remaining sites in the same commit",
        );

        $para = $this->soleParagraphContaining($this->settingsPage(), 'is the one remaining site', 'docs/SETTINGS.md');

        // The IDENTITY and the CARDINALITY in one phrase, both derived. A page
        // that named the right file while still implying there were two would
        // send the next reader looking for a second one that no longer exists.
        $this->assertStringContainsString(
            '`Bootstrap::reportProjectTierToolRemovals()` is the ' . $this->word(\count($census)) . ' remaining site',
            $para,
            'docs/SETTINGS.md no longer names exactly the src/ sites the census finds still carrying the figure',
        );
    }
}
