<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Calc\MakeTuple;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetCatalog;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Db\Version;

/**
 * Tests for dashboard widget model, catalog, and registry.
 */
final class WidgetTest extends TestCase
{
    public function testWidgetConstruction(): void
    {
        $calc = new RatePerSecond('Bytes_received');
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: $calc,
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
            tooltip: 'Incoming traffic',
        );

        $this->assertSame('Bytes In', $widget->caption);
        $this->assertSame('timeline', $widget->kind);
        $this->assertSame($calc, $widget->calc);
        $this->assertSame('%s/s', $widget->format);
        $this->assertSame(['r' => 60, 'g' => 178, 'b' => 191], $widget->color);
        $this->assertSame('Incoming traffic', $widget->tooltip);
    }

    public function testWidgetComputeRatePerSecond(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $current = ['Bytes_received' => '1100'];
        $previous = ['Bytes_received' => '1000'];
        $elapsed = 10.0;

        $result = $widget->compute($current, $previous, $elapsed);

        $this->assertEqualsWithDelta(10.0, $result, 0.001);
    }

    public function testWidgetComputeStatusVar(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new StatusVar('Threads_connected'),
            format: '%d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
        );

        $current = ['Threads_connected' => '42'];
        $previous = ['Threads_connected' => '40'];
        $elapsed = 10.0;

        $result = $widget->compute($current, $previous, $elapsed);

        $this->assertSame('42', $result);
    }

    public function testWidgetComputeMakeTuple(): void
    {
        $widget = new Widget(
            caption: 'SQL Statements',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: (new MakeTuple(','))
                ->addRate('Com_select')
                ->addRate('Com_insert')
                ->addRate('Com_delete'),
            format: '%s/s',
            color: ['r' => 255, 'g' => 215, 'b' => 0],
        );

        $current = [
            'Com_select' => '1100',
            'Com_insert' => '200',
            'Com_delete' => '50',
        ];
        $previous = [
            'Com_select' => '1000',
            'Com_insert' => '100',
            'Com_delete' => '50',
        ];
        $elapsed = 10.0;

        $result = $widget->compute($current, $previous, $elapsed);

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(10.0, $result['Com_select'], 0.001);
        $this->assertEqualsWithDelta(10.0, $result['Com_insert'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['Com_delete'], 0.001);
    }

    public function testWidgetIsTupleWithMakeTuple(): void
    {
        $widget = new Widget(
            caption: 'SQL Statements',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: (new MakeTuple(','))->addRate('Com_select'),
            format: '%s/s',
            color: ['r' => 255, 'g' => 215, 'b' => 0],
        );

        $this->assertTrue($widget->isTuple());
    }

    public function testWidgetIsTupleWithRatePerSecond(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $this->assertFalse($widget->isTuple());
    }

    public function testWidgetFormatValueScalar(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $this->assertSame('10.5/s', $widget->formatValue(10.5));
    }

    public function testWidgetFormatValueArray(): void
    {
        $widget = new Widget(
            caption: 'SQL Statements',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: (new MakeTuple(','))->addRate('Com_select'),
            format: '%s/s',
            color: ['r' => 255, 'g' => 215, 'b' => 0],
        );

        $result = $widget->formatValue(['Com_select' => 10.5]);
        $this->assertSame('10.5/s', $result);
    }

    public function testWidgetServerVarsKeys(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $this->assertSame(['max' => 'max_connections'], $widget->serverVarsKeys);
    }

    public function testWidgetCatalogNetworkReturnsNonEmpty(): void
    {
        $entries = WidgetCatalog::network();

        $this->assertNotEmpty($entries);
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

    public function testWidgetCatalogMysqlPre80ReturnsNonEmpty(): void
    {
        $entries = WidgetCatalog::mysqlPre80();

        $this->assertNotEmpty($entries);
        $this->assertContains('Com_alter_db_upgrade', $this->extractCalcKeys($entries));
    }

    public function testWidgetCatalogMysqlPost80ReturnsNonEmpty(): void
    {
        $entries = WidgetCatalog::mysqlPost80();

        $this->assertNotEmpty($entries);
        $this->assertContains('Com_create_role', $this->extractCalcKeys($entries));
        $this->assertNotContains('Com_alter_db_upgrade', $this->extractCalcKeys($entries));
    }

    public function testWidgetCatalogInnodbReturnsNonEmpty(): void
    {
        $entries = WidgetCatalog::innodb();

        $this->assertNotEmpty($entries);
        $this->assertContains('Innodb_buffer_pool_read_requests', $this->extractCalcKeys($entries));
    }

    public function testWidgetRegistryBuildForPre80(): void
    {
        $version = Version::parse('5.7.42');
        $widgets = WidgetRegistry::build($version);

        $this->assertNotEmpty($widgets);
        $allKeys = $this->extractAllRateKeys($widgets);
        $this->assertNotContains('Com_create_role', $allKeys, 'Pre-80 build should not contain 8.0-only keys');
    }

    public function testWidgetRegistryBuildForPost80(): void
    {
        $version = Version::parse('8.0.36');
        $widgets = WidgetRegistry::build($version);

        $this->assertNotEmpty($widgets);
        $allKeys = $this->extractAllRateKeys($widgets);
        $this->assertContains('Com_create_role', $allKeys, 'Post-80 build should contain Com_create_role');
    }

    public function testWidgetRegistryNetwork(): void
    {
        $widgets = WidgetRegistry::network();

        $this->assertNotEmpty($widgets);
        $captions = array_map(fn($w) => $w->caption, $widgets);
        $this->assertContains('Bytes In', $captions);
        $this->assertContains('Bytes Out', $captions);
        $this->assertContains('Connections', $captions);
    }

    public function testWidgetRegistryInnodb(): void
    {
        $widgets = WidgetRegistry::innodb();

        $this->assertNotEmpty($widgets);
        $captions = array_map(fn($w) => $w->caption, $widgets);
        $this->assertContains('InnoDB Disk Writes', $captions);
        $this->assertContains('InnoDB Disk Reads', $captions);
    }

    public function testWidgetRegistryIsValidKind(): void
    {
        $this->assertTrue(WidgetRegistry::isValidKind('timeline'));
        $this->assertTrue(WidgetRegistry::isValidKind('counter'));
        $this->assertTrue(WidgetRegistry::isValidKind('round'));
        $this->assertTrue(WidgetRegistry::isValidKind('level'));
        $this->assertFalse(WidgetRegistry::isValidKind('invalid'));
        $this->assertFalse(WidgetRegistry::isValidKind(''));
    }

    public function testWidgetRegistryValidKinds(): void
    {
        $kinds = WidgetRegistry::validKinds();

        $this->assertContains('timeline', $kinds);
        $this->assertContains('counter', $kinds);
        $this->assertContains('round', $kinds);
        $this->assertContains('level', $kinds);
        $this->assertCount(4, $kinds);
    }

    public function testWidgetComputeZeroElapsed(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $current = ['Bytes_received' => '1100'];
        $previous = ['Bytes_received' => '1000'];
        $elapsed = 0.0;

        $result = $widget->compute($current, $previous, $elapsed);

        $this->assertSame(0.0, $result);
    }

    public function testWidgetComputeMissingKey(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $current = [];
        $previous = ['Bytes_received' => '1000'];
        $elapsed = 10.0;

        $result = $widget->compute($current, $previous, $elapsed);

        $this->assertSame(0.0, $result);
    }

    /**
     * Extract status variable keys referenced by RatePerSecond calcs in catalog entries.
     *
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
            } elseif ($calc instanceof MakeTuple) {
                $reflection = new \ReflectionClass($calc);
                $prop = $reflection->getProperty('rates');
                $prop->setAccessible(true);
                $rates = $prop->getValue($calc);
                foreach ($rates as $rate) {
                    if ($rate instanceof RatePerSecond) {
                        $keys[] = $rate->key;
                    }
                }
            }
        }
        return $keys;
    }

    /**
     * Extract all status variable keys from a list of Widget objects.
     *
     * @param list<Widget> $widgets
     * @return list<string>
     */
    private function extractAllRateKeys(array $widgets): array
    {
        $keys = [];
        foreach ($widgets as $w) {
            if ($w->calc instanceof RatePerSecond) {
                $keys[] = $w->calc->key;
            } elseif ($w->calc instanceof MakeTuple) {
                $reflection = new \ReflectionClass($w->calc);
                $prop = $reflection->getProperty('rates');
                $prop->setAccessible(true);
                $rates = $prop->getValue($w->calc);
                foreach ($rates as $rate) {
                    if ($rate instanceof RatePerSecond) {
                        $keys[] = $rate->key;
                    }
                }
            }
        }
        return $keys;
    }
}
