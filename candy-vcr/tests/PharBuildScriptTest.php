<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural pins for the phar build surface (DST-1 program, phf lane).
 *
 * Deliberately LIGHT: this suite never mints an archive (build + smoke are
 * verified out-of-suite against a real monorepo-mode build, evidence in the
 * lane report). What IS pinned here is the law each built artifact depends
 * on, read out of the sources:
 *
 *  - tools/build-phar.php exists, packages fonts/, and keeps the four
 *    candy-code guards (readonly door, integrity preflight, --vendor=monorepo
 *    hatch, symlink refusal) plus the SOURCE_DATE_EPOCH stamp arm;
 *  - bin/candy-vcr carries the phar:// autoload guard in the measured shape
 *    (Phar::running or the self-naming __FILE__, archive-exclusive);
 *  - FontLoader discovers fonts without glob() — glob reads no stream
 *    wrapper, so a glob-based bundle goes hollow under phar://;
 *  - versionString() consults the archive manifest BEFORE falling back, and
 *    refuses the `dev-build` working-tree sentinel as a version (precedence
 *    pinned by source position, fail-closed by the sentinel check).
 */
final class PharBuildScriptTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/..';

    public function testTheBuildScriptExistsAndPackagesTheFontBundle(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        // The INCLUDE_ROOTS line is the artifact's manifest: fonts/ is the
        // candy-vcr-specific entry (~1.8 MB of bundled TTFs) and its absence
        // would ship a renderer that cannot render.
        self::assertMatchesRegularExpression(
            '/const INCLUDE_ROOTS = \[[^\]]*\'fonts\'[^\]]*\];/',
            $script,
            'build-phar.php must keep fonts/ in INCLUDE_ROOTS.',
        );
        foreach (['bin', 'src', 'vendor'] as $root) {
            self::assertStringContainsString("'" . $root . "'", $script);
        }
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

    public function testTheBinCarriesTheMeasuredPharGuard(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-vcr');

        // The two archive identities, addressed the only way that works.
        self::assertStringContainsString("'phar://' . \\Phar::running(false)", $bin);
        self::assertStringContainsString("str_ends_with(__FILE__, '.phar')", $bin);
        // Archive-exclusive boot: inside an archive the ONLY candidate is the
        // archive's own vendor — a stray vendor beside the phar must never win.
        self::assertStringContainsString("\$archive . '/vendor/autoload.php'", $bin);
        self::assertStringContainsString("if (\$archive !== '') {", $bin);
    }

    public function testFontLoaderDiscoversFontsWithoutGlob(): void
    {
        $code = self::executableCode(self::PACKAGE . '/src/Raster/FontLoader.php');

        // glob() reads no stream wrapper: under phar:// a family-subdirectory
        // listing comes back empty and the bundled fonts vanish from the
        // shipped artifact. The house law (candy-code ToolCatalog/BuiltInCommands)
        // is scandir + filter.
        self::assertStringNotContainsString('glob(', $code, 'FontLoader must not call glob() outside comments.');
        self::assertStringContainsString('scandir(', $code);
    }

    public function testTheFontLoaderWalksFamilySubdirectoriesViaScandir(): void
    {
        // Behavior half of the same law, on the real filesystem: a family
        // laid out as <dir>/<Family>/x.ttf must still resolve (the scandir
        // conversion must not have dropped the subdirectory arm).
        $dir = sys_get_temp_dir() . '/vcr-fonts-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $family = $dir . '/TestFamilyMono';
        mkdir($family, 0o755, true);
        $font = $family . '/TestFamilyMono-Regular.ttf';
        file_put_contents($font, 'not a real ttf — resolution only');

        try {
            $loader = new \SugarCraft\Vcr\Raster\FontLoader([$dir]);
            $resolved = $loader->resolve('TestFamilyMono', 'regular');

            self::assertSame($font, $resolved, 'subdirectory font must resolve through scandir');
            self::assertSame($font, $loader->lastResolvedPath());
        } finally {
            @unlink($font);
            @rmdir($family);
            @rmdir($dir);
        }
    }

    public function testVersionStringPrefersThePharStampOverTheFallback(): void
    {
        $src = self::source(self::PACKAGE . '/src/Cli/Application.php');

        // Precedence, pinned by source position inside versionString(): the
        // metadata read happens before the FALLBACK return. A swap (fallback
        // first) makes every shipped phar answer `dev` — the DST-1 lie in
        // mirror image.
        $body = self::sliceFrom($src, 'public static function versionString(): string');
        $stampPos = strpos($body, "\$metadata['version']");
        $fallbackPos = strpos($body, 'self::FALLBACK_VERSION');
        self::assertIsInt($stampPos, 'versionString must read the archive metadata.');
        self::assertIsInt($fallbackPos);
        self::assertLessThan($fallbackPos, $stampPos);
        // Fail-closed sentinel: a working-tree build stamps 'dev-build', and
        // that must NOT be served as a version.
        self::assertStringContainsString("'dev-build'", $body);
    }

    public function testVersionFlagIsRoutedBeforeTheHelpAndUnknownArms(): void
    {
        $src = self::source(self::PACKAGE . '/src/Cli/Application.php');
        $run = self::sliceFrom($src, 'public function run(array $argv, $stdout, $stderr): int');

        $versionPos = strpos($run, 'self::VERSION_FLAGS');
        $helpPos = strpos($run, "'-h', '--help', 'help'");
        self::assertIsInt($versionPos);
        self::assertIsInt($helpPos);
        self::assertLessThan($helpPos, $versionPos, '--version must be answered before --help/unknown dispatch.');
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
     * The file with every comment token removed — so prose that MENTIONS
     * glob() cannot satisfy (or defeat) the call-site pin.
     */
    private static function executableCode(string $path): string
    {
        $code = '';
        foreach (\token_get_all(self::source($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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
