<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;

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
            if (preg_match_all('/[\'"](trustedProject[A-Za-z]+)[\'"]/', $source, $matches) === 0) {
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
}
