<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\Application;

/**
 * Structural pins for the phar build surface.
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
     * The archive-identity leg of Application::versionString() (:45-96) was
     * dead coverage: every other pin here is structural source-grep, so the
     * whole metadata arm could be deleted while README's phar-release-identity
     * promise quietly lay. This MINTS a real archive in a
     * `-d phar.readonly=0` subprocess and RUNS it: the stub loads
     * SugarCraft\MdView classes from inside the archive (so their `__FILE__`
     * carries the `phar://` prefix hostArchive() inspects) while every other
     * namespace resolves through the package's live autoload. A tagged stamp
     * must round-trip; the `dev-build` sentinel and a stamp-less manifest
     * must fall through to the composer literal.
     *
     * Deliberately NOT the full build-phar.php package: Guard 4 refuses
     * symlinked linked-mode vendor trees (monorepo CI state) and sealing the
     * whole vendor would freeze working-tree code. The identity channel is
     * what gets pinned, not the packaging.
     */
    public function testStampedArchiveRoundTripsItsVersionThroughTheMetadataArm(): void
    {
        $autoload = realpath(self::PACKAGE . '/vendor/autoload.php');
        self::assertIsString($autoload, 'the suite runs with vendor installed');
        $srcDir = realpath(self::PACKAGE . '/src');
        self::assertIsString($srcDir);

        $cases = [
            'tagged stamp wins'          => ['candy-mdview/v9.9.9-pinned', 'candy-mdview/v9.9.9-pinned'],
            'dev-build sentinel refused' => [Application::WORKING_TREE_STAMP, '0.1.0'],
            'stamp-less manifest'        => [null, '0.1.0'],
        ];

        foreach ($cases as $label => [$stamp, $expected]) {
            $pharPath = sys_get_temp_dir() . '/mdview-identity-' . uniqid() . '.phar';
            $builder  = sys_get_temp_dir() . '/mdview-identity-build-' . uniqid() . '.php';

            $boot = "<?php\n"
                // boot.php runs in the REQUIRED file's own scope — variables
                // set in the stub region do not reach it. The archive root is
                // this file's dirname: inside a phar load, __FILE__ is
                // 'phar:///abs/x.phar/boot.php', so dirname answers
                // 'phar:///abs/x.phar' without any stub handoff.
                . '$archiveRoot = dirname(__FILE__);' . "\n"
                // Register AFTER the composer require and PREPENDED: the
                // generated autoload_real calls ClassLoader::register(true),
                // which inserts composer at the HEAD of the spl queue, so a
                // plain-append here would be shadowed (measured: the host
                // src/ copy answers every SugarCraft\MdView class and the
                // metadata arm stays dead coverage — the very trap 025
                // caught). Prepending puts the archive loader in front.
                . 'require ' . var_export($autoload, true) . ";\n"
                . "spl_autoload_register(static function (string \$class) use (\$archiveRoot): void {\n"
                . "    if (str_starts_with(\$class, 'SugarCraft\\\\MdView\\\\') === false) { return; }\n"
                . "    \$rel = str_replace('\\\\', '/', substr(\$class, 18));\n"
                . "    \$file = \$archiveRoot . '/src/' . \$rel . '.php';\n"
                . "    if (is_file(\$file)) { require \$file; }\n"
                . "}, true, true);\n"
                . "echo \\SugarCraft\\MdView\\Application::versionString();\n";

            file_put_contents($builder, "<?php\n"
                . '$p = $argv[1];' . "\n"
                . '@unlink($p);' . "\n"
                . '$phar = new Phar($p, 0, "mdview-identity.phar");' . "\n"
                . 'foreach (glob(' . var_export($srcDir . '/*.php', true) . ') as $f) {' . "\n"
                . "    \$phar->addFile(\$f, 'src/' . basename(\$f));\n"
                . '}' . "\n"
                . '$phar->addFromString(\'boot.php\', ' . var_export($boot, true) . ');' . "\n"
                . '$phar->setMetadata(["version" => ' . var_export($stamp, true) . ']);' . "\n"
                . "\$phar->setStub(\"<?php require 'phar://' . __FILE__ . '/boot.php'; __HALT_COMPILER();\");\n"
                . '$phar->stopBuffering();' . "\n");

            try {
                [$buildRc, $buildOut, $buildErr] = self::runPhp(['-d', 'phar.readonly=0', $builder, $pharPath]);
                self::assertSame(0, $buildRc, "build ({$label}): {$buildErr}{$buildOut}");

                [$runRc, $runOut, $runErr] = self::runPhp([$pharPath]);
                self::assertSame(0, $runRc, "run ({$label}): {$runErr}");
                self::assertSame($expected, $runOut, "version channel ({$label})");
            } finally {
                @unlink($builder);
                @unlink($pharPath);
            }
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string,2:string} rc + stdout + stderr
     */
    private static function runPhp(array $args): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        // Pipe-EOF law: drain both streams to EOF before judging.
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
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
