<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Version;

/**
 * Structural pins for the phar build surface (DST-1 program, phf lane).
 *
 * LIGHT by design — no archive is minted inside the suite (that is the build
 * smoke's job, evidence in the lane report). Pinned here:
 *
 *  - tools/build-phar.php exists, packages data/ (the ~412 KB admin catalogs),
 *    and keeps the candy-code guard inventory;
 *  - bin/candy-sql carries the phar:// autoload guard in the measured shape;
 *  - Version consults the archive manifest BEFORE the InstalledVersions
 *    answer, keeps `dev-build` fail-closed, and its composer-mode answer is
 *    exactly the installed package's own Composer metadata — 14a7e605a's
 *    contract preserved through the fusion, no historical literal anywhere.
 */
final class PharBuildScriptTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/..';

    public function testTheBuildScriptExistsAndPackagesTheDataCatalogs(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        // data/ is the artifact's admin half: Reports\Catalog and
        // Variables\Catalog load their JSON through file_get_contents with a
        // `__DIR__ . '/../../../data'` default — packaging makes it work
        // under phar://, absence silently hollows the dashboards.
        self::assertMatchesRegularExpression(
            '/const INCLUDE_ROOTS = \[[^\]]*\'data\'[^\]]*\];/',
            $script,
            'build-phar.php must keep data/ in INCLUDE_ROOTS.',
        );
        foreach (['bin', 'src', 'lang', 'vendor'] as $root) {
            self::assertStringContainsString("'" . $root . "'", $script);
        }
    }

    public function testTheBuildScriptKeepsEveryCandyCodeGuard(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        self::assertStringContainsString('phar.readonly', $script, 'Guard 1 (build-only flag).');
        self::assertStringContainsString('status --porcelain', $script, 'Guard 2 (integrity preflight).');
        self::assertStringContainsString('--vendor=', $script, 'The dev-only monorepo hatch.');
        self::assertStringContainsString('is_link($path)', $script, 'Guard 4 (symlink refusal).');
        self::assertStringContainsString('SOURCE_DATE_EPOCH', $script, 'The reproducible built_at arm.');
        self::assertStringContainsString('__HALT_COMPILER();', $script, 'The stub IS the bin, sealed.');
        self::assertStringContainsString("\"#!/usr/bin/env php\\n<?php\"", $script, 'The stub-shape refusal.');
    }

    public function testTheBinCarriesTheMeasuredPharGuard(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-sql');

        self::assertStringContainsString("'phar://' . \\Phar::running(false)", $bin);
        self::assertStringContainsString("str_ends_with(__FILE__, '.phar')", $bin);
        self::assertStringContainsString("\$archive . '/vendor/autoload.php'", $bin);
        // Archive-exclusive: the filesystem candidates live BEHIND the
        // archive arm, so a stray vendor can never boot code outside the
        // frozen archive.
        $archivePos = strpos($bin, "if (\$archive !== '') {");
        $candidatesPos = strpos($bin, "foreach (['vendor/autoload.php'");
        self::assertIsInt($archivePos);
        self::assertIsInt($candidatesPos);
        self::assertLessThan($candidatesPos, $archivePos);
    }

    public function testVersionPrefersThePharStampOverTheComposerAnswer(): void
    {
        $src = self::source(self::PACKAGE . '/src/Version.php');

        // Precedence by source position in current()'s chain: the archive
        // identity is consulted BEFORE the InstalledVersions answer. A swap
        // makes every shipped phar answer the frozen vendor's build-root
        // version — the DST-1 lie, restored.
        $body = self::sliceFrom($src, 'public static function current(): string');
        $archivePos = strpos($body, 'self::hostArchive()');
        $composerPos = strpos($body, 'self::installedVersion()');
        self::assertIsInt($archivePos, 'current() must consult the archive first.');
        self::assertIsInt($composerPos, 'current() must still answer via Composer in composer mode.');
        self::assertLessThan($composerPos, $archivePos);

        // Fail-closed sentinel: a working-tree build stamps 'dev-build', and
        // that must NOT be served as a version.
        self::assertStringContainsString('self::WORKING_TREE_STAMP', self::sliceFrom($src, 'public static function stampFrom'));
    }

    public function testResolvePinsTheFusedChainWithoutAnArchive(): void
    {
        // The pure seam is the whole precedence law; the metadata-wins arm is
        // simulated through stampFrom() because the suite never mints an
        // archive (the real build smoke does, per the lane report).
        self::assertSame('composer-answer', Version::resolve('', 'composer-answer'), 'no archive: the composer answer passes through untouched.');
        self::assertSame('composer-answer', Version::resolve('/no/such/thing.phar', 'composer-answer'), 'an unreadable archive falls through, never prints garbage.');

        // Simulated build stamps — what resolve() serves for a real archive:
        self::assertSame('candy-sql/v9.9.9', Version::stampFrom(['version' => 'candy-sql/v9.9.9']));
        self::assertSame('dev-fin1', Version::stampFrom(['version' => '  dev-fin1  ']), 'the stamp is trimmed, not rejected for whitespace.');
        self::assertNull(Version::stampFrom(['version' => 'dev-build']), 'the working-tree sentinel is never a served version.');
        self::assertNull(Version::stampFrom(['version' => '   ']));
        self::assertNull(Version::stampFrom([]));
        self::assertNull(Version::stampFrom('not-an-array'));
        self::assertNull(Version::stampFrom(['version' => 42]), 'a non-string stamp fails closed.');
    }

    public function testHostArchiveFromPinsAllThreeIdentitiesAndTheEmptyAnswer(): void
    {
        self::assertSame('/x/y.phar', Version::hostArchiveFrom('/x/y.phar', 'anything'));
        self::assertSame('/x/y.phar', Version::hostArchiveFrom('', '/x/y.phar'));
        self::assertSame('/x/y.phar', Version::hostArchiveFrom('', 'phar:///x/y.phar/bin/candy-sql'));
        self::assertSame('', Version::hostArchiveFrom('', '/plain/path/bin/candy-sql'));
        self::assertSame('', Version::hostArchiveFrom('', 'phar:///x/notphar/src/Version.php'));
    }

    public function testInComposerModeTheArchiveLawStaysAsleepAndComposerAnswers(): void
    {
        // Running under phpunit: no archive identity anywhere, so the fused
        // law must not disturb the composer-mode answer 14a7e605a pinned.
        // The expectation mirrors the resolution chain rather than a literal,
        // so this stays true across linked/published vendor modes.
        self::assertSame('', Version::hostArchive());

        $expected = 'dev';
        if (class_exists(\Composer\InstalledVersions::class)) {
            $expected = \Composer\InstalledVersions::isInstalled('sugarcraft/candy-sql')
                ? (string) \Composer\InstalledVersions::getPrettyVersion('sugarcraft/candy-sql')
                : 'dev';
        }
        self::assertSame($expected, Version::current());

        // The historical literal is dead everywhere (14a7e605a's intent,
        // extended across the fusion): no source prints it, and the runtime
        // answer is not it while Composer reports otherwise.
        self::assertStringNotContainsString('1.0.0', self::source(self::PACKAGE . '/src/Version.php'));
        self::assertStringNotContainsString('COMPOSER_FALLBACK', self::source(self::PACKAGE . '/src/Version.php'));
    }

    /**
     * @return string the file's bytes, with a loud failure when unreadable
     */
    private static function source(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "cannot read {$path}");

        return $contents;
    }

    /**
     * The file text from the first occurrence of $needle to the end.
     */
    private static function sliceFrom(string $text, string $needle): string
    {
        $pos = strpos($text, $needle);
        self::assertIsInt($pos, "expected to find {$needle}");

        return substr($text, $pos);
    }
}
