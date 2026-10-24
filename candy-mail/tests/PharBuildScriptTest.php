<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural pins for the phar build surface (DST-1 program, phf lane).
 *
 * Deliberately LIGHT: this suite never mints an archive (build + smoke are
 * verified out-of-suite against a real monorepo-mode build, evidence in the
 * lane report). What IS pinned is the law the shipped artifact depends on:
 *
 *  - tools/build-phar.php exists, packages lang/ (the 16-locale error-message
 *    catalog) alongside bin/src/lang/vendor, and keeps the candy-code guards;
 *  - bin/candy-mail carries the phar:// autoload guard in the measured shape,
 *    with the composer-mode require byte-untouched in the else arm;
 *  - cliVersion() reads the archive manifest BEFORE InstalledVersions — the
 *    frozen vendor/composer/installed.php inside a phar would otherwise answer
 *    the build root's `dev-master` for the artifact's whole life (the DST-1
 *    lie this mandate exists to break), and the `dev-build` working-tree
 *    sentinel fails closed;
 *  - hostArchiveFrom() is pure, so all three archive identities (and the
 *    no-archive answer) are pinned in-process without any archive at all.
 */
final class PharBuildScriptTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/..';

    protected function setUp(): void
    {
        // The bin's functions (dispatch guard stays asleep: $argv[0] is
        // phpunit, not the bin — the same idiom BinDispatchTest uses).
        require_once __DIR__ . '/../bin/candy-mail';
    }

    public function testTheBuildScriptPackagesTheLocaleCatalogAndNothingElse(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        // mail's packaged data surface is exactly lang/ — no fonts/, themes/ or
        // data/ directory exists in this package, and the manifest says so.
        self::assertMatchesRegularExpression(
            '/const INCLUDE_ROOTS = \[\'bin\', \'src\', \'lang\', \'vendor\'\];/',
            $script,
            'mail ships bin, src, lang, vendor in that order — nothing more, nothing less.',
        );
        self::assertStringNotContainsString("'themes'", $script);
        self::assertStringNotContainsString("'fonts'", $script);
    }

    public function testTheBuildScriptKeepsEveryCandyCodeGuard(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        self::assertStringContainsString("phar.readonly", $script, 'Guard 1 (build-only flag).');
        self::assertStringContainsString('status --porcelain', $script, 'Guard 2 (integrity preflight).');
        self::assertStringContainsString('--vendor=', $script, 'The dev-only monorepo hatch.');
        self::assertStringContainsString('is_link($path)', $script, 'Guard 4 (symlink refusal).');
        self::assertStringContainsString('SOURCE_DATE_EPOCH', $script, 'The reproducible built_at arm.');
        self::assertStringContainsString('__HALT_COMPILER();', $script, 'The stub IS the bin, sealed.');
        self::assertStringContainsString('"#!/usr/bin/env php\\n<?php"', $script, 'The stub-shape refusal.');
    }

    public function testTheBinCarriesTheMeasuredPharGuardAndUntouchedComposerRequire(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-mail');

        // Archive identity via the pure helper, addressed as phar://, archive-
        // vendor EXCLUSIVE, and the composer-mode require kept verbatim in the
        // else arm (this app's bin had a single bare require — no candidate
        // list to preserve an order of).
        self::assertStringContainsString('hostArchiveFrom(\Phar::running(false), __FILE__)', $bin);
        self::assertStringContainsString("'phar://' . \$archive . '/vendor/autoload.php'", $bin);
        self::assertStringContainsString('the archive has no vendor/autoload.php', $bin);
        self::assertStringContainsString("require __DIR__ . '/../vendor/autoload.php';", $bin);
    }

    public function testCliVersionPrefersThePharStampOverInstalledVersions(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-mail');
        $body = self::sliceFrom($bin, 'function cliVersion(): string');

        // Precedence by source position: the metadata read happens BEFORE the
        // InstalledVersions chain. A swap makes every shipped phar answer the
        // frozen `dev-master` — the DST-1 lie, restored.
        $stampPos = strpos($body, "\$metadata['version']");
        $composerPos = strpos($body, 'InstalledVersions::class');
        self::assertIsInt($stampPos, 'cliVersion must read the archive manifest.');
        self::assertIsInt($composerPos, 'cliVersion must still answer via Composer in composer mode.');
        self::assertLessThan($composerPos, $stampPos);
        // Fail-closed sentinel: a working-tree build stamps 'dev-build', and
        // that must NOT be served as a version.
        self::assertStringContainsString("'dev-build'", $body);
    }

    public function testHostArchiveFromPinsAllThreeIdentitiesAndTheEmptyAnswer(): void
    {
        // The pure helper is the whole phar-detection law; every branch here
        // is a live code path measured under the real build in the lane
        // report — pinned in-process because no archive is needed to pin it.
        self::assertSame('/x/y.phar', \SugarCraft\Mail\hostArchiveFrom('/x/y.phar', 'anything'));
        self::assertSame('/x/y.phar', \SugarCraft\Mail\hostArchiveFrom('', '/x/y.phar'));
        self::assertSame('/x/y.phar', \SugarCraft\Mail\hostArchiveFrom('', 'phar:///x/y.phar/bin/candy-mail'));
        self::assertSame('', \SugarCraft\Mail\hostArchiveFrom('', '/plain/path/bin/candy-mail'));
        self::assertSame('', \SugarCraft\Mail\hostArchiveFrom('', 'phar:///x/notphar/src/A.php'));
    }

    public function testInComposerModeTheArchiveLawStaysAsleepAndComposerAnswers(): void
    {
        // Running under phpunit: no archive identity anywhere, so the new law
        // must not disturb the historical answer. The expectation mirrors the
        // bin's own fallback chain rather than a literal, so this stays true
        // across linked/published vendor modes.
        self::assertSame('', \SugarCraft\Mail\hostArchive());

        $expected = 'dev';
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $expected = \Composer\InstalledVersions::isInstalled('sugarcraft/candy-mail')
                    ? (string) \Composer\InstalledVersions::getPrettyVersion('sugarcraft/candy-mail')
                    : (string) (\Composer\InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev');
            } catch (\Throwable) {
                $expected = 'dev';
            }
        }
        self::assertSame($expected, \SugarCraft\Mail\cliVersion());
        self::assertNotSame('dev-test1', \SugarCraft\Mail\cliVersion());
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
