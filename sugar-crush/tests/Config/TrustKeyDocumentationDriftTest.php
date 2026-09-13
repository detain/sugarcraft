<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Tests\Config\Support\DocumentParagraphs;

/**
 * `docs/PERMISSIONS.md` enumerated THREE `trustedProject*` grants for as long
 * as there were four. A page that enumerates trust grants and misses one is
 * worse than no page: a reader who counts three has been told, by a document
 * whose whole job is to be complete, that `trustedProjectSettings` does not
 * exist.
 *
 * WHY THIS SCANS SOURCE TEXT INSTEAD OF READING CONSTANTS. The four keys live
 * in two classes and are declared three different ways: three are
 * `private const` on `Bootstrap`
 * (`TRUSTED_PROJECT_{HOOKS,MCP,COMMANDS}_CONFIG_KEY`), one is
 * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY} and public. Reflection
 * can reach all four — private constants included — but only if this test
 * already knows which two classes to look in, which is precisely the
 * assumption a fifth key added in a THIRD class would break, silently and in
 * the passing direction. A scan over `src/` has no such blind spot.
 *
 * THE THIRD PAGE THIS FAMILY NOW READS: `sugar-crush/README.md`. The two
 * checks at the bottom of this file police the README's key enumerations
 * against {@see LayeredSettings::LAYERED_KEYS} and
 * {@see LayeredSettings::userTierOnlyKeys()} for the same reason the SETTINGS.md
 * rows below are policed rather than proof-read — and because a MEASURED grep
 * showed nothing else did it: `ReadmeRosterDriftTest` never names those two
 * constants, and on the tree this step branched from (`03c584c2a`, MEASURED with
 * `git grep -n "Only these\|taken from a project file" 03c584c2a -- sugar-crush/tests/`,
 * which returns nothing) no test quoted either roster sentence. That is no longer
 * true of THIS tip — the two guards at the bottom of this file are the quote — and
 * it is stated as a fact about the pre-step tree because that is the gap they were
 * written to close, not a property of the tree they now live on. Both claims
 * in the README were stale on the day `disabledRules` landed, and one of them is
 * the SECURITY enumeration: a reader told "four keys are never taken from a
 * project file" concludes the fifth is project-settable under a trust grant,
 * which is the exact inverse of the tiering decision.
 *
 * WHICH SENTENCE EACH HALF POLICES, because README.md carries spelled-out
 * numbers belonging to several different censuses and a guard must know which
 * one it is reading:
 *
 *  - {@see testTheReadmeLayeredKeyRosterAgreesWithLayeredKeys()} polices the
 *    roster sentence opening "Only these keys are layered —" (the
 *    {@see LayeredSettings::LAYERED_KEYS} enumeration).
 *  - {@see testTheReadmeNamesEveryKeyNoProjectMaySet()} polices the sentence
 *    opening "Even for a trusted project, …keys are **never** taken from a
 *    project file" (the {@see LayeredSettings::userTierOnlyKeys()} enumeration).
 *
 * THE BUILT-IN TOOL CENSUS IS NOT THIS FILE'S JOB. "out of the eleven built-in
 * tools the project tier can filter" and the launch-report sample in the same
 * page are numbers about
 * `Bootstrap::tools()`, not about layered keys, and they are already derived by
 * {@see ReadmeSettingsTierClaimTest} (its `SPELLED_COUNTS` map) and by
 * {@see ReadmeRosterDriftTest}. Nothing here reads or constrains either of
 * those, so the two guards cannot disagree about which figure a word stands for.
 *
 * @internal
 */
final class TrustKeyDocumentationDriftTest extends TestCase
{
    private const PERMISSIONS_DOC = __DIR__ . '/../../docs/PERMISSIONS.md';

    /**
     * Every `trustedProject*` key spelled as a string literal anywhere under
     * `src/`.
     *
     * @return list<string>
     */
    private function keysInSource(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // `[A-Za-z0-9_]` and not `[A-Za-z]`: round 43 measured a sibling
            // census silently SKIPPING a call because its key class rejected
            // one character of the key — a narrow identifier class does not
            // report "unrecognised key", it reports nothing at all. Every trust
            // key is camelCase today, so this widening changes no result; it
            // changes what happens the day one is not.
            if (preg_match_all('/[\'"](trustedProject[A-Za-z0-9_]+)[\'"]/', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $key) {
                $found[$key] = true;
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * The census itself. This is the assertion that fails the day a fifth key
     * is added — before anyone reaches the documentation checks below.
     */
    public function testSourceDeclaresExactlyTheFourKnownTrustKeys(): void
    {
        $this->assertSame(
            [
                'trustedProjectCommands',
                'trustedProjectHooks',
                'trustedProjectMcp',
                'trustedProjectSettings',
            ],
            $this->keysInSource(),
            'a trustedProject* key was added or removed; update docs/PERMISSIONS.md and this list together',
        );
    }

    /** The fourth key is the one that was missing, so pin it by name. */
    public function testTheFourthKeyIsTheOneLayeredSettingsDeclares(): void
    {
        $this->assertSame('trustedProjectSettings', LayeredSettings::PROJECT_SETTINGS_TRUST_KEY);
        $this->assertContains(LayeredSettings::PROJECT_SETTINGS_TRUST_KEY, $this->keysInSource());
    }

    /**
     * Each key must appear in the doc's own table, not merely somewhere on the
     * page — a passing mention in prose is how the fourth key was "documented"
     * while the enumeration still said three.
     */
    public function testEveryTrustKeyHasARowInThePermissionsTable(): void
    {
        $doc = (string) file_get_contents(self::PERMISSIONS_DOC);
        $this->assertNotSame('', $doc);

        foreach ($this->keysInSource() as $key) {
            $this->assertSame(
                1,
                preg_match('/^\| `' . preg_quote($key, '/') . '` \|/m', $doc),
                $key . ' has no row in the trustedProject* table in docs/PERMISSIONS.md',
            );
        }
    }

    /** The heading counts them out loud, so the count has to be right too. */
    public function testTheHeadingCountsTheKeysItActuallyLists(): void
    {
        $doc = (string) file_get_contents(self::PERMISSIONS_DOC);

        $this->assertStringContainsString('## The four `trustedProject*` keys', $doc);
        $this->assertStringNotContainsString('## The three `trustedProject*` keys', $doc);
    }

    /**
     * And so does every OTHER page that counts them in passing. The heading
     * was not the only place the number lived: `ARCHITECTURE.md` and
     * `MEMORY.md` both said "the three `trustedProject*`", and `MCP.md` said
     * "the other two", each of them true when written and none of them
     * updated when the fourth key landed. A count is the cheapest possible
     * thing to leave stale, so all of them are checked here rather than by
     * whoever next reads one.
     */
    public function testNoDocPageStillCountsThreeTrustGrants(): void
    {
        $docs = realpath(__DIR__ . '/../../docs');
        self::assertIsString($docs);

        $stale = [];
        foreach ((array) glob($docs . '/*.md') as $path) {
            $source = (string) file_get_contents((string) $path);
            // `(?<!other )` because "the other three `trustedProject*` keys"
            // is CORRECT prose on a page discussing the fourth — three others
            // plus the one in hand. Only a BARE "three" and the stale "the
            // other two" are wrong.
            $stalePattern = '/(?<!other )three\s+`?trustedProject|the other two\s+`?trustedProject/i';
            if (preg_match($stalePattern, $source) === 1) {
                $stale[] = basename((string) $path);
            }
        }

        $this->assertSame([], $stale, 'these pages still count three trustedProject* grants; there are four');
    }

    /**
     * Renaming the heading breaks every deep link into it, and a dead anchor
     * lands the reader at the top of a long page with no sign anything went
     * wrong.
     */
    public function testEveryDeepLinkToThatHeadingStillResolves(): void
    {
        $docs = realpath(__DIR__ . '/../../docs');
        self::assertIsString($docs);

        $anchor = '#the-four-trustedproject-keys';
        $linkers = 0;
        foreach ((array) glob($docs . '/*.md') as $path) {
            $source = (string) file_get_contents((string) $path);
            $this->assertStringNotContainsString(
                '#the-three-trustedproject-keys',
                $source,
                basename((string) $path) . ' links to the old heading anchor',
            );
            if (str_contains($source, $anchor)) {
                $linkers++;
            }
        }

        $this->assertGreaterThan(0, $linkers, 'nothing links to the section any more — did it get renamed?');
    }

    /**
     * THE SAME COUNT, IN `src/`. Every check above globs `docs/*.md`, so a
     * source doc-block that miscounts the grants is outside all of them —
     * and one was: {@see \SugarCraft\Crush\Cli\Bootstrap::trustedProjectRoots()}
     * opened with "ONE PARSER FOR BOTH TRUST KEYS" and named
     * `trustedProjectHooks` and `trustedProjectMcp` while FOUR call sites
     * already passed through it. `PERMISSIONS.md` points readers straight at
     * that method, so the page a reader was sent to for the authoritative
     * answer contradicted the page that sent them.
     *
     * SCANS COMMENTS ONLY, via `token_get_all()`, which is what makes the
     * needle safe to keep loose: a count phrase is prose, and matching it
     * against code would red on any local named `$two` near a trust key. The
     * `(?<!other )` carve-out is the same one the docs scan needs — "the other
     * three `trustedProject*` keys" is correct prose on a doc-block discussing
     * the fourth.
     *
     * WHAT THIS CANNOT DO: it catches a count written as a WORD next to a
     * trust-key mention. A doc-block that miscounts by enumerating three of
     * the four by name, with no numeral, still passes. That is why
     * `trustedProjectRoots()` now writes the list out and cites this test —
     * an enumeration a reader can check beats a number a reader must trust.
     */
    public function testNoSourceDocBlockStillMiscountsTheTrustGrants(): void
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        // "four" is absent on purpose: it is the correct count, so a doc-block
        // saying it must not red. Add the next numeral here when a fifth key
        // lands, and drop the one that has become right.
        //
        // "one" is absent for a different reason, and it was MEASURED: this
        // method's own corrected doc-block opens "ONE PARSER FOR ALL FOUR
        // TRUST KEYS", which is exactly right and matched a needle that
        // included it. One parser, one grant and one key are all legitimate
        // singulars near a trust key; only a plural miscount is a defect.
        $stale = '/\b(?<!other )(two|three)\b[^.\n]{0,60}trust(ed)?[ _-]?(project|key)'
            . '|both\s+trust\s+keys'
            . '|\b(?<!other )(two|three)\s+`?trustedProject/i';

        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token) || ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)) {
                    continue;
                }
                if (preg_match($stale, $token[1]) === 1) {
                    $offenders[] = substr($file->getPathname(), \strlen($root) + 1) . ':' . $token[2];
                }
            }
        }
        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'a src/ comment counts the trustedProject* grants as fewer than four; there are four',
        );
    }

    /**
     * The settings stack had no reference page at all, which is why the
     * fourth key had nowhere to be documented and ended up mentioned only in
     * the README.
     */
    public function testTheSettingsPageExistsAndCoversTheGate(): void
    {
        $path = __DIR__ . '/../../docs/SETTINGS.md';
        $this->assertFileExists($path);

        $doc = (string) file_get_contents($path);
        $this->assertStringContainsString(LayeredSettings::PROJECT_SETTINGS_TRUST_KEY, $doc);
        $this->assertStringContainsString(LayeredSettings::SHARED_PATH, $doc);
        $this->assertStringContainsString(LayeredSettings::LOCAL_PATH, $doc);
    }

    /**
     * The page's key table names a reader beside every key, and the whole
     * point of that column is that it is checkable. Assert the layered keys
     * are all present rather than trusting the table was kept in step.
     */
    public function testTheSettingsPageListsEveryLayeredKey(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../docs/SETTINGS.md');

        foreach (LayeredSettings::LAYERED_KEYS as $key) {
            $this->assertSame(
                1,
                preg_match('/^\| `' . preg_quote($key, '/') . '` \|/m', $doc),
                $key . ' is layered but has no row in docs/SETTINGS.md',
            );
        }
    }

    /**
     * And the project-may-set column has to agree with
     * {@see LayeredSettings::PROJECT_TIER_KEYS} rather than with whatever the
     * author believed on the day.
     */
    public function testTheSettingsPageAgreesOnWhichKeysAProjectMaySet(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../docs/SETTINGS.md');

        foreach (LayeredSettings::LAYERED_KEYS as $key) {
            $matched = preg_match('/^\| `' . preg_quote($key, '/') . '` \| [^|]+ \| ([^|]+) \|/m', $doc, $m);
            $this->assertSame(1, $matched, $key . ' row is not in the expected three-column shape');

            $allowed = !str_contains($m[1], 'no');
            $this->assertSame(
                \in_array($key, LayeredSettings::PROJECT_TIER_KEYS, true),
                $allowed,
                $key . ': docs/SETTINGS.md disagrees with PROJECT_TIER_KEYS',
            );
        }
    }

    // -------------------------------------------------------------------------
    // README.md's two key enumerations — derived, not proof-read
    // -------------------------------------------------------------------------

    /**
     * The number words README.md spells, for the two censuses below.
     *
     * Deliberately SHORT, and the shape is copied from
     * {@see ReadmeSettingsTierClaimTest}'s `SPELLED_COUNTS` on purpose: every
     * reader asserts `assertArrayHasKey()` BEFORE indexing, because a const-array
     * miss on PHP 8.3.6 is `Warning: Undefined array key` evaluating to `null`
     * rather than a throw, and a `null` needle turns "widen the map" into an
     * unexplained containment miss. That map counts BUILT-IN TOOLS; this one
     * counts layered settings keys. Two censuses, two maps, neither read by the
     * other — which is what keeps a twelfth tool from reddening a key guard and
     * vice versa.
     *
     * @var array<int, string>
     */
    private const NUMBER_WORDS = [
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
    ];

    /** README.md as bytes, loud when the page it reads is gone. */
    private function readme(): string
    {
        $path = __DIR__ . '/../../README.md';
        self::assertFileExists($path, 'README.md enumerates the layered keys but is missing');

        $text = file_get_contents($path);
        self::assertIsString($text, 'README.md is unreadable');

        return $text;
    }

    /**
     * Every key {@see LayeredSettings::LAYERED_KEYS} holds, and nothing else, in
     * README's "Only these keys are layered" roster — plus the count word that
     * sentence spells, derived from the same constant.
     *
     * WHY THIS IS HERE AND NOT IN {@see ReadmeRosterDriftTest}, which is the file
     * that polices README's other rosters: that class walks tools, slash commands,
     * aliases, subcommands and the permission modes. It never names
     * `LAYERED_KEYS`, `PROJECT_TIER_KEYS` or `userTierOnlyKeys()` — MEASURED, a
     * `grep -rn` for all three over `tests/` returns it in
     * {@see LayeredSettingsTest} and this family only — and a settings-key census is
     * a TRUST-tier claim, which is exactly what this file is for. Extending the
     * class that already reads both constants is what keeps the two guards from
     * disagreeing about which list a sentence describes.
     *
     * BOTH DIRECTIONS are pinned, because the two failures are different accidents:
     * a key added to the constant without a README edit (the silent half — the page
     * still reads complete), and a key named in the page that the constant refused
     * to carry (the loudly wrong half — a project told it may set `provider`).
     */
    public function testTheReadmeLayeredKeyRosterAgreesWithLayeredKeys(): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());

        // UNIQUENESS FIRST, and for the reason the sibling tier guard below has
        // always had it: `preg_match` answers 1 for the FIRST match and cannot see a
        // second, so without this count a page carrying two roster sentences — an
        // edited one and a forgotten stale one — would be policed on whichever came
        // first and would let the other say anything it liked. Asserting the count of
        // openers is what makes the failure message below literally true rather than
        // a description of only the half it checks.
        preg_match_all('/Only these\b.*?keys are layered/', $flat, $openers);
        self::assertSame(
            1,
            \count($openers[0]),
            'README.md must carry exactly one sentence opening "Only these … keys are layered" — '
                . 'none means the LAYERED_KEYS roster this guard reads is gone or reworded, and two '
                . 'means one of them is unpoliced',
        );

        self::assertSame(
            1,
            preg_match('/Only these\b(.*?)\./', $flat, $m),
            'README.md must carry exactly one sentence opening "Only these … keys are layered" — '
                . 'the LAYERED_KEYS roster this guard reads is gone or reworded',
        );
        $sentence = $m[1];

        // Half one: the spelled count word, required rather than tolerated. A
        // digit here fails this match, and so does dropping the numeral, so the
        // figure can never again be prose nothing reads.
        self::assertSame(
            1,
            preg_match('/^\s*(?:\*\*)?([a-z]+)(?:\*\*)? keys are layered/', $sentence, $w),
            'README.md\'s "Only these … keys are layered" roster must spell its count in words — '
                . 'this guard derives that word from count(LAYERED_KEYS), so a digit or a '
                . 'missing numeral leaves the figure unpinned',
        );
        self::assertArrayHasKey(
            \count(LayeredSettings::LAYERED_KEYS),
            self::NUMBER_WORDS,
            'count(LAYERED_KEYS) has moved outside the range this file can spell; add the word',
        );
        self::assertSame(
            self::NUMBER_WORDS[\count(LayeredSettings::LAYERED_KEYS)],
            strtolower($w[1]),
            "README.md spells a layered-key count that is not count(LAYERED_KEYS): the constant holds "
                . \count(LayeredSettings::LAYERED_KEYS) . ' keys',
        );

        // Half two: the names themselves, as a set. No separate "did the pattern
        // match anything" shape assertion — an empty `$actual` against a non-empty
        // constant already fails the comparison below, and a second assert of a
        // fact the first one entails would only inflate the count.
        preg_match_all('/`([a-z][A-Za-z0-9]*)`/', $sentence, $n);
        $named = $n[1];
        $expected = LayeredSettings::LAYERED_KEYS;
        sort($expected);
        $actual = $named;
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            "README.md's \"Only these … keys are layered\" roster disagrees with LayeredSettings::LAYERED_KEYS — "
                . 'a key was layered without a README edit, or named in the page without being layered',
        );
    }

    /**
     * The security enumeration: README's "Even for a trusted project, … keys are
     * **never** taken from a project file" sentence must name every member of
     * {@see LayeredSettings::userTierOnlyKeys()} and spell exactly its count.
     *
     * THIS IS THE HALF THAT MATTERS. The sentence is the complete list a reader
     * takes as closed — miss one key and the page has told them that key IS
     * project-settable under a trust grant. That is the inverse of the tiering
     * decision it documents, and for `disabledRules` the inverted reading hands a
     * cloned repository the power to silence the operator's own rule packs. The
     * count word and the names are checked together for that reason: a numeral
     * that matches a short list is the shape of the defect.
     *
     * The window is a {@see DocumentParagraphs} unit rather than a fixed line
     * range or a regex over the whole page, because the enumeration runs across
     * a paragraph and the sentence after it introduces `disabledTools`, which IS
     * project-settable — a window that leaked one line would "document" the
     * refusal by naming the sibling that has none.
     */
    public function testTheReadmeNamesEveryKeyNoProjectMaySet(): void
    {
        $units = DocumentParagraphs::of($this->readme());
        $hits = array_values(array_filter(
            $units,
            static fn (string $unit): bool => str_contains($unit, 'taken from a project file'),
        ));
        self::assertCount(
            1,
            $hits,
            'README.md must carry exactly one paragraph enumerating the keys no project file may set'
        );
        $unit = $hits[0];

        $keys = LayeredSettings::userTierOnlyKeys();

        foreach ($keys as $key) {
            self::assertStringContainsString(
                '`' . $key . '`',
                $unit,
                '`' . $key . '` is a key no project may set, but README.md\'s "never taken from a project '
                    . 'file" enumeration does not name it — a reader of that page concludes the opposite',
            );
        }

        self::assertSame(
            1,
            preg_match('/Even for a trusted project, (?:\*\*)?([a-z]+)(?:\*\*)? keys are/', $unit, $m),
            'README.md\'s refusal enumeration must spell its count in words after "Even for a trusted project, " — '
                . 'this guard derives that word from count(userTierOnlyKeys())',
        );
        self::assertArrayHasKey(
            \count($keys),
            self::NUMBER_WORDS,
            'count(userTierOnlyKeys()) has moved outside the range this file can spell; add the word',
        );
        self::assertSame(
            self::NUMBER_WORDS[\count($keys)],
            strtolower($m[1]),
            'README.md spells a never-project-settable count that is not count(userTierOnlyKeys()): there are '
                . \count($keys) . ' such keys, and the sentence must name all of them',
        );
    }
}
