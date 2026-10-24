<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The launcher is a script, so it cannot be included by tests (it runs the
 * program at include time). These pins read its source and assert the exact
 * wiring shapes that docs-review-1023 demanded: query history default-on
 * with an opt-out flag, the CANDY_SQL_PASS fallback, and a version string
 * that tracks the installed package instead of a hardcoded literal.
 */
final class BinWiringTest extends TestCase
{
    private const BIN_PATH = __DIR__ . '/../bin/candy-sql';

    private static function binSource(): string
    {
        $src = \file_get_contents(self::BIN_PATH);
        \assert(\is_string($src), 'the launcher must be readable');

        return $src;
    }

    public function testHistoryIsWiredDefaultOnWithOptOutFlags(): void
    {
        $src = self::binSource();

        $this->assertStringContainsString('App::start($database, $flavor, $historyRecorder)', $src);
        $this->assertStringContainsString('new HistoryRecorder(new SqliteHistoryStore($historyPath))', $src);
        $this->assertStringContainsString("'--no-history'", $src);
        $this->assertStringContainsString("'--history='", $src);
        $this->assertStringContainsString(".candy-sql/history.db", $src);
    }

    public function testPasswordFallsBackToEnvironment(): void
    {
        $src = self::binSource();

        $this->assertStringContainsString("getenv('CANDY_SQL_PASS')", $src);
        $this->assertStringContainsString("str_starts_with(\$arg, '--pass=')", $src);
    }

    public function testVersionComesFromComposerMetadata(): void
    {
        // 14a7e605a's law, kept through the phar fusion (DST-1): the answer
        // tracks the installed package through Composer, never a hardcoded
        // literal. The resolution now lives behind Version (the phar
        // precedence demanded a single fused seam), so the token pin follows
        // it — plus the bin must delegate, and no literal may resurface in
        // either file.
        $src = self::binSource();
        $versionSrc = \file_get_contents(__DIR__ . '/../src/Version.php');
        \assert(\is_string($versionSrc), 'the version resolver must be readable');

        $this->assertStringContainsString('Version::current()', $src);
        $this->assertStringContainsString('InstalledVersions::getPrettyVersion', $versionSrc);
        $this->assertStringNotContainsString('"candy-sql 1.0.0"', $src);
        $this->assertStringNotContainsString('1.0.0', $versionSrc);
    }

    public function testLauncherIsNotNamespaced(): void
    {
        $this->assertStringNotContainsString(
            'namespace SugarCraft\Sql\bin;',
            self::binSource(),
            'a script-only namespace was never loadable and misled the docs'
        );
    }
}
