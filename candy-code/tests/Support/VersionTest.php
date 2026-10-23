<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\Version;

/**
 * Pins for the version ordering `self-update` consults before it writes a
 * byte (DST-3): the exact grammar it accepts, the numeric ordering, and the
 * fail-closed `null` every non-conforming shape must answer with.
 */
final class VersionTest extends TestCase
{
    use MintsStampedPharTrait;
    /**
     * @return array<string, array{0: string, 1: ?array{0: int, 1: int, 2: int}}>
     */
    public static function shapeProvider(): array
    {
        return [
            'bare triple' => ['1.2.3', [1, 2, 3]],
            'tag spelling' => ['v0.1.0', [0, 1, 0]],
            'zero padded' => ['v00.01.002', [0, 1, 2]],
            'large segments' => ['10.20.300', [10, 20, 300]],
            'surrounding space' => [" 1.2.3\n", [1, 2, 3]],
            'prerelease suffix refused' => ['1.2.3-rc1', null],
            'build metadata refused' => ['1.2.3+build7', null],
            'two segments refused' => ['1.2', null],
            'four segments refused' => ['1.2.3.4', null],
            'bare word refused' => ['dev-master', null],
            'double v refused' => ['vv1.2.3', null],
            'padded tag parses' => [' v1.2.3 ', [1, 2, 3]],
            'empty refused' => ['', null],
            'negative segment refused' => ['-1.2.3', null],
            'trailing dot refused' => ['1.2.3.', null],
            'inner space refused' => ['1. 2.3', null],
        ];
    }

    /**
     * @dataProvider shapeProvider
     */
    public function testParseAcceptsExactlyTheDottedTriple(string $input, ?array $expected): void
    {
        self::assertSame($expected, Version::parse($input));
    }

    public static function orderProvider(): array
    {
        return [
            'major older' => ['v0.9.9', 'v1.0.0', -1],
            'minor newer' => ['1.2.0', '1.1.9', 1],
            'patch equal' => ['v2.3.4', '2.3.4', 0],
            'patch order' => ['2.3.4', '2.3.5', -1],
            'numeric not lexical' => ['1.10.0', '1.9.0', 1],
        ];
    }

    /**
     * @dataProvider orderProvider
     */
    public function testCompareOrdersSegmentsNumerically(string $left, string $right, int $expected): void
    {
        self::assertSame($expected, Version::compare($left, $right));
        self::assertSame(-$expected, Version::compare($right, $left), 'compare must be antisymmetric');
    }

    public static function unorderableProvider(): array
    {
        return [
            'left dev' => ['dev-master', '1.0.0'],
            'right dev' => ['1.0.0', 'dev-master'],
            'left unknown' => ['unknown', '1.0.0'],
            'right rc' => ['1.0.0', '1.0.0-rc1'],
            'both junk' => ['alpha', 'beta'],
        ];
    }

    /**
     * @dataProvider unorderableProvider
     */
    public function testCompareRefusesEveryNonConformingSideWithNull(string $left, string $right): void
    {
        self::assertNull(Version::compare($left, $right), 'an ordering guess is how a dev checkout gets downgraded');
    }

    public function testIsComparableMirrorsParse(): void
    {
        self::assertTrue(Version::isComparable('v0.1.0'));
        self::assertFalse(Version::isComparable('dev-master'));
        self::assertFalse(Version::isComparable('0.1'));
    }

    /**
     * The dev-channel classifier is the literal prefix rule and nothing
     * more: uppercase answers like lowercase ('DEV-'), the bare word 'dev'
     * is NOT a dev build (the hyphen is part of the prefix), and no amount
     * of surrounding text excuses its absence.
     */
    public function testIsDevIsTheStrictPrefixRule(): void
    {
        self::assertTrue(Version::isDev('dev-1a2b3c4'), 'the canonical CI stamp');
        self::assertTrue(Version::isDev('DEV-1A2B3C4'), 'case is not a channel');
        self::assertTrue(Version::isDev('dev-master'), 'the composer arm guesses dev');
        self::assertTrue(Version::isDev('dev-build'), 'classification is cheaper than ordering');
        self::assertTrue(Version::isDev('dev-'), 'even an empty remainder classifies as dev');
        self::assertFalse(Version::isDev('dev'), 'bare word: no hyphen, no prefix');
        self::assertFalse(Version::isDev('devx-1'), 'the hyphen must be the third byte');
        self::assertFalse(Version::isDev('1dev-'), 'prefix means FIRST, not somewhere');
        self::assertFalse(Version::isDev('v1.2.3'));
        self::assertFalse(Version::isDev('unknown'));
        self::assertFalse(Version::isDev(''), 'the empty answer orders nothing and dev-names nothing');
    }

    /**
     * devShaOf is the parse-don't-validate seam: a canonical dev stamp
     * yields its lower-cased embedded commit; every shape the dev channel
     * cannot compare with commit precision — a branch name, a too-short or
     * non-hex remainder, no remainder, no prefix — yields null, and the
     * verb reads that null as its fail-closed refusal.
     */
    public function testDevShaOfReadsTheEmbeddedCommitOrFailClosed(): void
    {
        self::assertSame('1a2b3c4', Version::devShaOf('dev-1a2b3c4'));
        self::assertSame('1a2b3c4', Version::devShaOf('DEV-1A2B3C4'), 'the commit reads back lower-cased');
        self::assertSame('abcdef0123456789', Version::devShaOf(' dev-abcdef0123456789 '));
        self::assertNull(Version::devShaOf('dev-master'), 'a branch name is not a commit');
        self::assertNull(Version::devShaOf('dev-build'));
        self::assertNull(Version::devShaOf('dev-'), 'no remainder to trust');
        self::assertNull(Version::devShaOf('dev-abc'), 'three digits are below git own abbreviation floor');
        self::assertNull(Version::devShaOf('dev-1a2b3c4g'), 'a non-hex byte poisons the whole stamp');
        self::assertNull(Version::devShaOf('dev-' . \str_repeat('a', 41)), 'longer than a full object id is junk');
        self::assertNull(Version::devShaOf('v1.2.3'), 'stable strings are not dev stamps');
        self::assertNull(Version::devShaOf(''));
    }

    /**
     * DST-1: the reader's refusal arms need no archive at all — the shape
     * gate (empty path / no file / a file that is not a phar / a directory)
     * must answer null, never an exception, because `--version` runs this
     * before any of its own gates have said a word.
     */
    public function testFromPharMetadataRefusesEveryShapeThatIsNotAStampedArchive(): void
    {
        $plain = \sys_get_temp_dir() . '/dst1-plain-' . \bin2hex(\random_bytes(4)) . '.phar';
        self::assertNotFalse(\file_put_contents($plain, 'not a phar, a plain file wearing its name'));
        $dir = \sys_get_temp_dir() . '/dst1-dir-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0o700, true));

        try {
            self::assertNull(Version::fromPharMetadata(''), 'the empty host-archive answer must read as no stamp');
            self::assertNull(Version::fromPharMetadata($dir . '/never-created.phar'), 'a missing path is not a stamp');
            self::assertNull(Version::fromPharMetadata($plain), 'a .phar-named plain file must not raise, only refuse');
            self::assertNull(Version::fromPharMetadata($dir), 'a directory is not an archive');
        } finally {
            @\unlink($plain);
            @\rmdir($dir);
        }
    }

    /**
     * DST-1: the minted-archive round-trip — a tagged stamp comes back as
     * the bare comparable version (the `candy-code/` namespace decoration
     * stripped), a canonical `dev-<sha>` stamp comes back as itself (the
     * dev channel's commit identity), and every other shape — the
     * working-tree `dev-build` fallback, junk — answers null so the caller
     * falls through to the Composer arms. Mints via the shared child
     * process helper, so this runs (no skip) on every runner.
     */
    public function testFromPharMetadataReadsBackAStampedRelease(): void
    {
        $mints = [];

        try {
            $stamped = self::mintStampedPhar('candy-code/v7.8.9');
            $mints[] = $stamped;
            self::assertSame('v7.8.9', Version::fromPharMetadata($stamped), 'the tagged stamp must round-trip decoration-free');

            $bare = self::mintStampedPhar('v1.2.3');
            $mints[] = $bare;
            self::assertSame('v1.2.3', Version::fromPharMetadata($bare), 'a stamp that needs no stripping still round-trips');

            $dev = self::mintStampedPhar('dev-build');
            $mints[] = $dev;
            self::assertNull(Version::fromPharMetadata($dev), 'a working-tree build is not a release version');

            // The CI dev workflow stamps `--version=dev-$SHORT_SHA`; that
            // canonical stamp is the archive's honest version and must read
            // back — falling through here would resurrect the DST-1 lie the
            // stamp exists to cure (the frozen vendor guessing `dev-master`).
            $devSha = self::mintStampedPhar('dev-1a2b3c4');
            $mints[] = $devSha;
            self::assertSame('dev-1a2b3c4', Version::fromPharMetadata($devSha), 'a canonical dev stamp round-trips');

            $devTagged = self::mintStampedPhar('candy-code/dev-1a2b3c4');
            $mints[] = $devTagged;
            self::assertSame('dev-1a2b3c4', Version::fromPharMetadata($devTagged), 'and the tag namespace strips the same for dev as for stable');

            $junk = self::mintStampedPhar('banana');
            $mints[] = $junk;
            self::assertNull(Version::fromPharMetadata($junk), 'junk in the stamp slot is the same refusal, fail-closed');
        } finally {
            foreach ($mints as $path) {
                self::discardStampedPhar($path);
            }
        }
    }
}
