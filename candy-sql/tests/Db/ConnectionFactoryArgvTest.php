<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * fromArgv() must not silently eat options it does not know
 * (review m9, D-M3 driver list, 2026-11-03). A swallowed --sslmode
 * typo quietly downgraded TLS posture; a swallowed --whatever made
 * "it accepted my flag" a lie.
 */
final class ConnectionFactoryArgvTest extends TestCase
{
    public function testTheAuthoritativeDriverListIsPublic(): void
    {
        // README cites ConnectionFactory::SUPPORTED_DRIVERS; a private
        // const made that citation a phantom.
        $this->assertSame(['sqlite', 'mysql', 'pgsql'], ConnectionFactory::SUPPORTED_DRIVERS);
    }

    public function testUnknownOptionRefusesWithItsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option: --sslmode');
        ConnectionFactory::fromArgv(['--driver=mysql', '--db=x', '--sslmode=require']);
    }

    public function testValuelessKnownOptionRefusesWithItsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --port requires a value');
        ConnectionFactory::fromArgv(['--driver=mysql', '--db=x', '--port']);
    }

    public function testValuelessUnknownOptionIsReportedAsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option: --frobnicate');
        ConnectionFactory::fromArgv(['--frobnicate']);
    }

    public function testKnownFlagsStillBuildAConnection(): void
    {
        $db = ConnectionFactory::fromArgv(['--driver=sqlite', '--db=:memory:']);
        $this->assertInstanceOf(SqliteDatabase::class, $db);
        $this->assertSame('sqlite::memory:', $db->dsn());
        $db->close();
    }

    public function testDsnFlagStillWinsOverIndividualFlags(): void
    {
        $db = ConnectionFactory::fromArgv(['--dsn=sqlite://:memory:']);
        $this->assertInstanceOf(SqliteDatabase::class, $db);
        $db->close();
    }
}
