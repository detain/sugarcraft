<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\CacheHitRate;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Dashboard\PostgresWidgetCatalog;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;

/**
 * Tests for PostgresWidgetCatalog - PostgreSQL dashboard widget definitions.
 */
final class PostgresWidgetCatalogTest extends TestCase
{
    public function testIoReturnsNonEmpty(): void
    {
        $entries = (new PostgresWidgetCatalog())->io();

        $this->assertNotEmpty($entries);
    }

    public function testIoReturnsValidWidgetEntries(): void
    {
        $entries = (new PostgresWidgetCatalog())->io();

        foreach ($entries as $entry) {
            $this->assertCount(7, $entry);
            [$caption, $kind, $calc, $format, $color, $tooltip, $serverVarsKeys] = $entry;
            $this->assertIsString($caption);
            $this->assertContains($kind, WidgetRegistry::validKinds());
            $this->assertIsObject($calc);
            $this->assertIsString($format);
            $this->assertArrayHasKey('r', $color);
            $this->assertArrayHasKey('g', $color);
            $this->assertArrayHasKey('b', $color);
            $this->assertIsString($tooltip);
        }
    }

    public function testIoUsesPgStatDatabaseKeys(): void
    {
        $entries = (new PostgresWidgetCatalog())->io();

        $keys = $this->extractCalcKeys($entries);
        $this->assertContains('pg_stat_database.tup_fetched', $keys);
        $this->assertContains('pg_stat_database.blks_read', $keys);
        $this->assertContains('pg_stat_database.blks_hit', $keys);
        $this->assertContains('pg_stat_database.numbackends', $keys);
    }

    public function testTransactionsReturnsNonEmpty(): void
    {
        $entries = (new PostgresWidgetCatalog())->transactions();

        $this->assertNotEmpty($entries);
    }

    public function testTransactionsReturnsValidWidgetEntries(): void
    {
        $entries = (new PostgresWidgetCatalog())->transactions();

        foreach ($entries as $entry) {
            $this->assertCount(7, $entry);
            [$caption, $kind, $calc, $format, $color, $tooltip, $serverVarsKeys] = $entry;
            $this->assertIsString($caption);
            $this->assertContains($kind, WidgetRegistry::validKinds());
            $this->assertIsObject($calc);
            $this->assertIsString($format);
            $this->assertArrayHasKey('r', $color);
            $this->assertArrayHasKey('g', $color);
            $this->assertArrayHasKey('b', $color);
        }
    }

    public function testTransactionsUsesPgStatDatabaseKeys(): void
    {
        $entries = (new PostgresWidgetCatalog())->transactions();

        $keys = $this->extractCalcKeys($entries);
        $this->assertContains('pg_stat_database.xact_commit', $keys);
        $this->assertContains('pg_stat_database.xact_rollback', $keys);
        $this->assertContains('pg_stat_database.deadlocks', $keys);
    }

    public function testCacheReturnsNonEmpty(): void
    {
        $entries = (new PostgresWidgetCatalog())->cache();

        $this->assertNotEmpty($entries);
    }

    public function testCacheReturnsValidWidgetEntries(): void
    {
        $entries = (new PostgresWidgetCatalog())->cache();

        foreach ($entries as $entry) {
            $this->assertCount(7, $entry);
            [$caption, $kind, $calc, $format, $color, $tooltip, $serverVarsKeys] = $entry;
            $this->assertIsString($caption);
            $this->assertContains($kind, WidgetRegistry::validKinds());
            $this->assertIsObject($calc);
            $this->assertIsString($format);
            $this->assertArrayHasKey('r', $color);
            $this->assertArrayHasKey('g', $color);
            $this->assertArrayHasKey('b', $color);
        }
    }

    public function testCacheContainsSharedBuffersAndPgStatDatabaseEntries(): void
    {
        $entries = (new PostgresWidgetCatalog())->cache();

        $sharedBufferEntries = array_filter($entries, fn($e) => $e[2] instanceof StatusVar
            && $e[2]->key === 'pg_settings.shared_buffers');
        $this->assertNotEmpty($sharedBufferEntries, 'Cache should have shared_buffers entry');

        $pgStatsEntries = array_filter($entries, fn($e) => $e[2] instanceof RatePerSecond
            && str_starts_with($e[2]->key, 'pg_stat_database.'));
        $this->assertNotEmpty($pgStatsEntries, 'Cache should have pg_stat_database rate entries');
    }

    public function testIoConnectionsHasServerVarsKeys(): void
    {
        $entries = (new PostgresWidgetCatalog())->io();

        $connectionEntries = array_filter($entries, fn($e) => $e[2] instanceof StatusVar
            && $e[2]->key === 'pg_stat_database.numbackends');
        $entryWithServerVars = null;
        foreach ($connectionEntries as $entry) {
            if ($entry[6] !== null) {
                $entryWithServerVars = $entry;
                break;
            }
        }
        $this->assertNotNull($entryWithServerVars, 'Connections should have serverVarsKeys for max_connections');
        $this->assertSame(['max' => 'max_connections'], $entryWithServerVars[6]);
    }

    /**
     * @param list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}> $entries
     * @return list<string>
     */
    private function extractCalcKeys(array $entries): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $calc = $entry[2];
            if ($calc instanceof RatePerSecond) {
                $keys[] = $calc->key;
            } elseif ($calc instanceof StatusVar) {
                $keys[] = $calc->key;
            }
        }
        return $keys;
    }
}
