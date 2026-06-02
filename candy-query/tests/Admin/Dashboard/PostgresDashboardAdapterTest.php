<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Dashboard\PostgresDashboardAdapter;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Db\Flavor;

/**
 * Tests for PostgresDashboardAdapter stub functionality.
 */
final class PostgresDashboardAdapterTest extends TestCase
{
    public function testBuildPostgresDashboardReturnsNoticeWidget(): void
    {
        $adapter = PostgresDashboardAdapter::new(Flavor::Postgres);
        $widgets = $adapter->buildPostgresDashboard();

        $this->assertCount(1, $widgets);

        $widget = $widgets[0];
        $this->assertInstanceOf(Widget::class, $widget);
        $this->assertSame('Postgres support coming', $widget->caption);
        $this->assertSame(WidgetRegistry::KIND_COUNTER, $widget->kind);
    }

    public function testBuildPostgresDashboardReturnsValidWidget(): void
    {
        $adapter = PostgresDashboardAdapter::new(Flavor::Postgres);
        $widgets = $adapter->buildPostgresDashboard();

        $widget = $widgets[0];

        // Verify the widget can compute a value (notice message)
        $value = $widget->compute([], [], 1.0);
        $this->assertSame('Postgres dashboard widgets are not yet implemented. Help wanted!', $value);
    }

    public function testSupportsFlavorReturnsTrueForPostgres(): void
    {
        $adapter = PostgresDashboardAdapter::new(Flavor::Postgres);

        $this->assertTrue($adapter->supportsFlavor(Flavor::Postgres));
    }

    public function testSupportsFlavorReturnsFalseForMySQL(): void
    {
        $adapter = PostgresDashboardAdapter::new(Flavor::Postgres);

        $this->assertFalse($adapter->supportsFlavor(Flavor::MySQL));
        $this->assertFalse($adapter->supportsFlavor(Flavor::MariaDB));
        $this->assertFalse($adapter->supportsFlavor(Flavor::Sqlite));
    }

    public function testNewFactoryCreatesInstance(): void
    {
        $adapter = PostgresDashboardAdapter::new(Flavor::Postgres);

        $this->assertInstanceOf(PostgresDashboardAdapter::class, $adapter);
    }
}
