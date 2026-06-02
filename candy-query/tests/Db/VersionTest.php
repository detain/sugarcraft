<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\Version;

/**
 * Tests for Version immutable value object.
 */
final class VersionTest extends TestCase
{
    public function testParseSimpleVersion(): void
    {
        $version = Version::parse('8.0.33');

        $this->assertSame(8, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(33, $version->release);
        $this->assertSame('8.0.33', $version->raw);
    }

    public function testParseMariaDBVersionWithPrefix(): void
    {
        // MariaDB version string with the MySQL compatibility prefix
        $version = Version::parse('5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201');

        $this->assertSame(10, $version->major);
        $this->assertSame(11, $version->minor);
        $this->assertSame(4, $version->release);
        $this->assertSame('5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201', $version->raw);
    }

    public function testParseMariaDBVersionShortForm(): void
    {
        $version = Version::parse('10.11.4-MariaDB-ubu2201');

        $this->assertSame(10, $version->major);
        $this->assertSame(11, $version->minor);
        $this->assertSame(4, $version->release);
    }

    public function testParsePostgresVersion(): void
    {
        $version = Version::parse('PostgreSQL 16.0');

        $this->assertSame(16, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(0, $version->release);
        $this->assertSame('PostgreSQL 16.0', $version->raw);
    }

    public function testParseSqliteVersion(): void
    {
        $version = Version::parse('3.41.0');

        $this->assertSame(3, $version->major);
        $this->assertSame(41, $version->minor);
        $this->assertSame(0, $version->release);
        $this->assertSame('3.41.0', $version->raw);
    }

    public function testIsAtLeastExactMatch(): void
    {
        $version = Version::parse('8.0.33');

        $this->assertTrue($version->isAtLeast(8, 0, 33));
        $this->assertTrue($version->isAtLeast(8, 0, 0));
        $this->assertTrue($version->isAtLeast(8));
        $this->assertTrue($version->isAtLeast(7));
        $this->assertTrue($version->isAtLeast(7, 9));
        $this->assertTrue($version->isAtLeast(7, 9, 999));
    }

    public function testIsAtLeastWhenVersionIsGreater(): void
    {
        $version = Version::parse('8.0.33');

        $this->assertTrue($version->isAtLeast(8, 0, 30));
        $this->assertTrue($version->isAtLeast(8, 0, 32));
        $this->assertTrue($version->isAtLeast(7, 9, 999));
        $this->assertTrue($version->isAtLeast(7, 10));
    }

    public function testIsAtLeastReturnsFalseWhenOlder(): void
    {
        $version = Version::parse('8.0.33');

        $this->assertFalse($version->isAtLeast(8, 0, 34));
        $this->assertFalse($version->isAtLeast(8, 1));
        $this->assertFalse($version->isAtLeast(9));
    }

    public function testIsAtLeastWithMariaDBVersion(): void
    {
        $version = Version::parse('5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201');

        // Should be treated as MariaDB 10.11.4
        $this->assertTrue($version->isAtLeast(10, 11));
        $this->assertTrue($version->isAtLeast(10, 11, 4));
        $this->assertTrue($version->isAtLeast(10));
        $this->assertTrue($version->isAtLeast(9, 999));
        $this->assertFalse($version->isAtLeast(10, 11, 5));
        $this->assertFalse($version->isAtLeast(11));
    }

    public function testToStringReturnsRaw(): void
    {
        $version = Version::parse('8.0.33');

        $this->assertSame('8.0.33', (string) $version);
    }

    public function testToStringWithMariaDBVersion(): void
    {
        $version = Version::parse('5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201');

        $this->assertSame('5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201', (string) $version);
    }

    public function testParseEmptyVersion(): void
    {
        $version = Version::parse('');

        $this->assertSame(0, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(0, $version->release);
        $this->assertSame('', $version->raw);
    }

    public function testParsePartialVersion(): void
    {
        $version = Version::parse('8.0');

        $this->assertSame(8, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(0, $version->release);
    }
}
