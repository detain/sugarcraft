<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A light net for the phar packaging surface (lane PHE). It deliberately does
 * NOT build an archive inside the suite — a real build needs a clean published
 * vendor/ and writes to disk, neither of which belongs in a unit run. What it
 * pins is the three laws that, if broken, would make a published .phar silently
 * misbehave:
 *
 *  1. the bin is a valid archive stub (shebang + open tag) and carries an
 *     archive-aware autoload guard (both 'phar://' and 'Phar::running' — the
 *     measured PHP 8.3 rule that the stub and the required-from-inside
 *     contexts name the archive through different identities), while the
 *     consumer-install candidate list stays intact outside an archive;
 *  2. tools/build-phar.php exists and refuses an unknown option with exit 2
 *     BEFORE touching the archive (so a typo cannot start a half-build);
 *  3. the version SOURCE agrees: the build stamps metadata['version'] and the
 *     runtime Support\Version reader consumes exactly that field — the DST-1
 *     lesson that a stamp nothing reads makes --version lie — and the bin's
 *     --version arm routes through it.
 */
final class PharBuildScriptTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/..';

    private function readAppFile(string $relative): string
    {
        $path = self::APP_ROOT . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "expected readable file {$relative}");

        return $contents;
    }

    public function testTheBinStubIsAValidArchiveWithAPharAwareGuard(): void
    {
        $bin = $this->readAppFile('bin/candy-codeshot');

        // The build refuses unless the stub begins with the shebang+<?php
        // shape; pin the same precondition the build script checks for.
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin/candy-codeshot no longer starts with the shebang+<?php stub shape',
        );
        // The autoload guard must resolve BOTH phar identities, not just one.
        self::assertStringContainsString('phar://', $bin);
        self::assertStringContainsString('Phar::running', $bin);
        // A bare '/x.phar/entry' path fails is_file in PHP 8.3 — the guard
        // must never probe one; the archive path is only ever concatenated
        // onto a '/vendor/autoload.php' suffix at runtime.
        self::assertStringNotContainsString('.phar/vendor', $bin);
        // The three consumer candidates survive for non-archive runs (the
        // BinStructureTest order law re-pins them from the live bin).
        self::assertStringContainsString("__DIR__ . '/../../../autoload.php'", $bin);
        self::assertStringContainsString("__DIR__ . '/../../vendor/autoload.php'", $bin);
    }

    public function testTheBuildScriptRefusesUnknownOptionsBeforeBuilding(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');

        self::assertStringContainsString('unrecognized option', $build);
        // The unknown-option arm and the phar.readonly guard both bail with 2.
        self::assertStringContainsString('exit(2)', $build);
        // The stub is bin/candy-codeshot verbatim with __HALT_COMPILER();.
        self::assertStringContainsString('__HALT_COMPILER();', $build);
        // The symlink guard is the load-bearing linked-vs-published law.
        self::assertStringContainsString('is_link', $build);
    }

    public function testTheVersionStampIsStampedByTheBuildAndReadByTheRuntime(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');
        $reader = $this->readAppFile('src/Support/Version.php');
        $bin = $this->readAppFile('bin/candy-codeshot');

        // Build side: metadata carries a 'version' field.
        self::assertStringContainsString("'version'", $build);
        self::assertStringContainsString('setMetadata', $build);

        // Runtime side: the reader consumes exactly that field...
        self::assertStringContainsString("metadata['version']", $reader);
        self::assertStringContainsString('fromPharMetadata', $reader);
        // ...and the bin answers --version through it, before any other boot.
        self::assertStringContainsString("case '--version'", $bin);
        self::assertStringContainsString('displayVersion', $bin);
        self::assertStringContainsString("'candy-codeshot ' . displayVersion()", $bin);
    }
}
