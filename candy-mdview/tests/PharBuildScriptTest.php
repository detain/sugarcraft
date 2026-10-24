<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\Application;

/**
 * Structural pins for the phar build surface (DST-1 program, phf lane).
 *
 * LIGHT by design — the suite never mints an archive (build + smoke live in
 * the lane report). Pinned here:
 *
 *  - tools/build-phar.php exists, packages themes/ (the three bundled JSON
 *    themes RenderCommand::pickTheme loads through __DIR__-relative paths),
 *    and keeps the candy-code guard inventory;
 *  - bin/candy-mdview carries the phar:// autoload guard and the historical
 *    CANDIDATE ORDER survives untouched (the 'mdview-order' shape is itself
 *    the pinned thing — the archive arm runs before it, never inside it);
 *  - Application::versionString() prefers the archive stamp over the
 *    historical `0.1.0`, keeps `dev-build` fail-closed, and outside an
 *    archive the answer is byte-true to the pre-phar literal.
 */
final class PharBuildScriptTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/..';

    public function testTheBuildScriptExistsAndPackagesTheThemes(): void
    {
        $script = self::source(self::PACKAGE . '/tools/build-phar.php');

        self::assertMatchesRegularExpression(
            '/const INCLUDE_ROOTS = \[[^\]]*\'themes\'[^\]]*\];/',
            $script,
            'build-phar.php must keep themes/ in INCLUDE_ROOTS.',
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

    public function testTheBinCarriesThePharGuardAndKeepsCandidateOrder(): void
    {
        $bin = self::source(self::PACKAGE . '/bin/candy-mdview');

        self::assertStringContainsString("'phar://' . \\Phar::running(false)", $bin);
        self::assertStringContainsString("str_ends_with(__FILE__, '.phar')", $bin);
        self::assertStringContainsString("\$archive . '/vendor/autoload.php'", $bin);

        // The 'mdview-order' law: the pre-existing candidate sequence
        // (standalone → vendor/bin → monorepo) survives the guard untouched,
        // and the archive arm sits strictly BEFORE the loop that consumes the
        // candidates — inside an archive the loop is unreachable (else-branch),
        // so the guard adapted around the order instead of reordering it.
        $standalone = strpos($bin, "__DIR__ . '/../vendor/autoload.php'");
        $vendorBin = strpos($bin, "__DIR__ . '/../../../autoload.php'");
        $monorepo = strpos($bin, "__DIR__ . '/../../vendor/autoload.php'");
        $archiveArm = strpos($bin, "if (\$archive !== '') {");
        $consumeLoop = strpos($bin, 'foreach ($autoloadCandidates');
        self::assertIsInt($standalone);
        self::assertIsInt($vendorBin);
        self::assertIsInt($monorepo);
        self::assertIsInt($archiveArm);
        self::assertIsInt($consumeLoop);
        self::assertLessThan($vendorBin, $standalone);
        self::assertLessThan($monorepo, $vendorBin);
        self::assertLessThan($consumeLoop, $archiveArm, 'archive identity must gate every filesystem candidate.');
    }

    public function testVersionStringPrefersThePharStampOverTheLiteral(): void
    {
        $src = self::source(self::PACKAGE . '/src/Application.php');
        $body = self::sliceFrom($src, 'public static function versionString(): string');

        $stampPos = strpos($body, "\$metadata['version']");
        $fallbackPos = strpos($body, 'self::COMPOSER_FALLBACK');
        self::assertIsInt($stampPos, 'versionString must read the archive metadata.');
        self::assertIsInt($fallbackPos);
        self::assertLessThan($fallbackPos, $stampPos, 'fallback must come last, not first.');
        self::assertStringContainsString('self::WORKING_TREE_STAMP', $body, 'dev-build is never served.');
    }

    public function testComposerModeStillAnswersTheHistoricalLiteral(): void
    {
        // Negative control + byte-compat: outside an archive the constructor
        // hands Symfony exactly what it handed before the phar program
        // (ApplicationTest pins getVersion()==='0.1.0' from the other side).
        self::assertSame('', Application::hostArchive(), 'the suite never runs inside an archive.');
        self::assertSame('0.1.0', Application::versionString());
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
