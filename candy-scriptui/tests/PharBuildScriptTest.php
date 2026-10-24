<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;

/**
 * Structural pins for the phar build surface (DST-1 program, phf lane).
 *
 * Deliberately LIGHT: this suite never mints an archive (the real build +
 * smoke run out-of-suite against a monorepo-mode artifact, evidence in the
 * lane report). What IS pinned is the law every built artifact depends on:
 *
 *  - tools/build-phar.php exists, keeps the four candy-code guards plus the
 *    SOURCE_DATE_EPOCH stamp arm, ships exactly bin/src/lang/vendor (nothing
 *    else needs packaging — asserted as an ABSENT set too), and refuses any
 *    packaged composer.json: the archive's identity comes from the build
 *    manifest, never from a frozen copy of the build root's file;
 *  - bin/candy-scriptui carries the phar:// guard ahead of the ORIGINAL
 *    candidate walk, whose literal order is preserved (mandate: adapt
 *    without reordering) and which still runs in composer mode;
 *  - Application::versionString() consults the manifest BEFORE the
 *    composer.json walk and refuses the `dev-build` working-tree stamp;
 *  - the old public contract `versionFromComposer()` survives as the exact
 *    pre-phar answer (delegation pinned, so EnvVarFallbackTest's contract
 *    stays the shipped one);
 *  - outside an archive the dispatcher still answers exactly what the walk
 *    answers (whatever it finds, wherever it finds it), never a stale stamp.
 */
final class PharBuildScriptTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/..';

    public function testTheBuildScriptShipsExactlyTheRuntimeSurface(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        self::assertMatchesRegularExpression(
            "/const INCLUDE_ROOTS = \['bin', 'src', 'lang', 'vendor'\];/",
            $script,
            'scriptui packages exactly bin/src/lang/vendor — nothing else needs packaging.',
        );
        // examples/ (dev material) and composer.json (the DST-1 lie source)
        // must not sneak in via INCLUDE_FILES either.
        self::assertMatchesRegularExpression(
            "/const INCLUDE_FILES = \['LICENSE'\];/",
            $script,
        );
        self::assertStringNotContainsString("'examples'", $script);
        self::assertStringNotContainsString("'composer.json'", $script);
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
        self::assertStringContainsString('"#!/usr/bin/env php\\n<?php"', $script, 'The stub-shape refusal.');
    }

    public function testTheBinCarriesThePharGuardAheadOfTheUntouchedWalk(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-scriptui');

        self::assertStringContainsString("'phar://' . \\Phar::running(false)", $bin);
        self::assertStringContainsString("str_ends_with(__FILE__, '.phar')", $bin);
        self::assertStringContainsString("\$archive . '/vendor/autoload.php'", $bin);

        // Mandate: the original candidate list keeps its literal order — the
        // guard wraps the walk, it does not rewrite it.
        $standalone = strpos($bin, "'/../vendor/autoload.php'");
        $vendorBin = strpos($bin, "'/../../../autoload.php'");
        $monorepo = strpos($bin, "'/../../vendor/autoload.php'");
        self::assertIsInt($standalone);
        self::assertIsInt($vendorBin);
        self::assertIsInt($monorepo);
        self::assertLessThan($vendorBin, $standalone);
        self::assertLessThan($monorepo, $vendorBin);
        // ...and the walk loop is gated by the archive arm: execution takes
        // either the exclusive phar:// require or the original foreach, never
        // both (the array literal above it is declaration only).
        $archiveArm = strpos($bin, "if (\$archive !== '') {");
        $walkLoop = strpos($bin, 'foreach ($autoloadCandidates');
        self::assertIsInt($archiveArm);
        self::assertIsInt($walkLoop);
        self::assertLessThan($walkLoop, $archiveArm, 'the archive arm must decide before the composer-mode walk can run.');
        self::assertMatchesRegularExpression('/\} else \{\s*foreach \(\$autoloadCandidates/', $bin, 'the original walk must live in the composer-mode else branch.');
    }

    public function testVersionStringPrefersTheManifestStampOverTheWalk(): void
    {
        $src = self::source(self::PACKAGE . '/src/Application.php');
        $body = self::sliceFrom($src, 'public static function versionString(): string');

        $stampPos = strpos($body, "\$metadata['version']");
        $walkPos = strpos($body, 'self::readComposerVersion()');
        self::assertIsInt($stampPos, 'versionString must read the archive metadata.');
        self::assertIsInt($walkPos);
        self::assertLessThan($walkPos, $stampPos, 'the stamp must be consulted before the walk fallback.');
        self::assertStringContainsString('self::WORKING_TREE_STAMP', $body, "'dev-build' must be refused as a version.");
    }

    public function testThePublicComposerContractStillAnswersThroughTheOldPath(): void
    {
        $src = self::source(self::PACKAGE . '/src/Application.php');
        $body = self::sliceFrom($src, 'public function versionFromComposer(): string');
        $body = substr($body, 0, strpos($body, "\n    }"));

        // EnvVarFallbackTest pins the pre-phar behavior of THIS method; the
        // phar program must preserve it verbatim, so it stays a pure
        // delegation to the instance-free walk — no archive logic in here.
        self::assertStringContainsString('return self::readComposerVersion();', $body);
        self::assertStringNotContainsString('hostArchive', $body);
        self::assertStringNotContainsString('Phar', $body);
    }

    public function testOutsideAnArchiveTheAnswerIsTheOldAnswer(): void
    {
        // This suite never runs under a phar, so the composer-mode shape is
        // directly observable: no archive identity, and the dispatcher equals
        // the walk answer exactly (whatever it finds — in a monorepo checkout
        // the walk reaches the root composer.json; EnvVarFallbackTest pins
        // that value's derivation). A stale 'dev-test1'-style stamp leaking
        // here would mean the archive arms fire when they must not. Inside an
        // archive the same walk would answer '0.0.0' (the phar ships no
        // composer.json at all) — that is precisely the dead answer the
        // manifest stamp overrides.
        self::assertSame('', Application::hostArchive());
        $walked = (new Application())->versionFromComposer();
        self::assertSame($walked, Application::versionString());
        self::assertSame($walked, (new Application())->getVersion());
        self::assertNotSame('dev-test1', Application::versionString());
        self::assertNotSame(Application::WORKING_TREE_STAMP, Application::versionString());
    }

    private static function source(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "cannot read {$path}");

        return $contents;
    }

    private static function sliceFrom(string $text, string $needle): string
    {
        $pos = strpos($text, $needle);
        self::assertIsInt($pos, "expected to find {$needle}");

        return substr($text, $pos);
    }
}
