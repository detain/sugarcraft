<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\Support\Version;

/**
 * Pins the stamp grammar behind `--version` inside a phar. fromPharMetadata
 * needs a real archive, so the normalization step is exercised directly via
 * reflection — the grammar is the interesting part, and building a phar per
 * test would be heavier than the logic it guards.
 */
final class VersionTest extends TestCase
{
    private static function normalize(string $stamped): ?string
    {
        $method = new \ReflectionMethod(Version::class, 'normalizeVersion');
        $method->setAccessible(true);

        return $method->invoke(null, $stamped);
    }

    public function testReleaseStampsPassThrough(): void
    {
        self::assertSame('0.1.2', self::normalize('0.1.2'));
        self::assertSame('0.9.9', self::normalize('v0.9.9'));
        self::assertSame('0.9.9', self::normalize('candy-codeshot/v0.9.9'));
        self::assertSame('0.9.9', self::normalize('candy-codeshot/0.9.9'));
    }

    public function testDevBuildStampsPassThrough(): void
    {
        // CI (phar-dev.yml) stamps dev-<shortsha>; those must report their
        // commit id instead of silently falling back to CURRENT.
        self::assertSame('dev-1a2b3c4', self::normalize('dev-1a2b3c4'));
        self::assertSame('dev-1a2b3c4', self::normalize('candy-codeshot/dev-1a2b3c4'));
        self::assertSame('dev-' . str_repeat('a', 40), self::normalize('dev-' . str_repeat('a', 40)));
    }

    public function testUnreportableStampsYieldNull(): void
    {
        self::assertNull(self::normalize('dev-build'));
        self::assertNull(self::normalize('0.1.2-14-g1a2b3c4'));
        self::assertNull(self::normalize('dev-1a2b3c'));
        self::assertNull(self::normalize('not-a-version'));
    }

    public function testResolveFallsBackForMissingArchive(): void
    {
        self::assertSame('9.9.9', Version::resolve('/nonexistent-file.phar', '9.9.9'));
        self::assertSame(Version::CURRENT, Version::resolve(''));
    }
}
