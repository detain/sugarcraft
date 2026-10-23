<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A light net for the phar packaging surface (lane PHE). It does NOT build an
 * archive in-suite (a real build needs a clean published vendor/ and writes to
 * disk). It pins the three laws whose breach would make a published .phar
 * silently misreport itself:
 *
 *  1. the bin is a valid archive stub (shebang + open tag) carrying an
 *     archive-aware autoload guard (both 'phar://' and 'Phar::running' — the
 *     measured PHP 8.3 rule that the stub and the required-from-inside
 *     contexts name the archive through different identities), and preserves
 *     its three original /.. fallback candidates for non-archive runs;
 *  2. tools/build-phar.php refuses an unknown option with exit 2 before it
 *     could start a half-build, and keeps the symlink (linked-vendor poison)
 *     guard;
 *  3. the version SOURCE agrees — the build stamps metadata['version'] and the
 *     runtime Support\Version reader consumes exactly that field, with the
 *     compiled-in CURRENT as the non-archive fallback (--version prints the
 *     stamp under an archive, CURRENT otherwise).
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
        $bin = $this->readAppFile('bin/candy-ssh-launcher');

        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin/candy-ssh-launcher no longer starts with the shebang+<?php stub shape',
        );
        self::assertStringContainsString('phar://', $bin);
        self::assertStringContainsString('Phar::running', $bin);
        // A bare '/x.phar/entry' path fails is_file in PHP 8.3 — the guard must
        // never probe one; it must prefix with the phar:// stream instead.
        self::assertStringNotContainsString(".phar/vendor", $bin);
        // The three original /.. filesystem candidates survive for non-archive
        // runs (the guard only swaps them out when an archive is detected).
        self::assertStringContainsString("'/../../../vendor/autoload.php'", $bin);
    }

    public function testTheBuildScriptRefusesUnknownOptionsBeforeBuilding(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');

        self::assertStringContainsString('unrecognized option', $build);
        self::assertStringContainsString('exit(2)', $build);
        self::assertStringContainsString('__HALT_COMPILER();', $build);
        self::assertStringContainsString('is_link', $build);
    }

    public function testTheVersionStampIsStampedByTheBuildAndReadByTheRuntime(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');
        $reader = $this->readAppFile('src/Support/Version.php');
        $bin = $this->readAppFile('bin/candy-ssh-launcher');

        self::assertStringContainsString("'version'", $build);
        self::assertStringContainsString('setMetadata', $build);

        self::assertStringContainsString("metadata['version']", $reader);
        self::assertStringContainsString('fromPharMetadata', $reader);
        self::assertStringContainsString('CURRENT', $reader);

        self::assertStringContainsString('Support\\Version', $bin);
        self::assertStringContainsString('::resolve(', $bin);
    }
}
