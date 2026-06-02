<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Connections\ConnectionFilters;

final class ConnectionFiltersTest extends TestCase
{
    public function testNewCreatesDefaultFilters(): void
    {
        $filters = ConnectionFilters::new();

        $this->assertFalse($filters->hideSleeping);
        $this->assertFalse($filters->hideBackground);
        $this->assertFalse($filters->skipFullInfo);
        $this->assertNull($filters->refreshRate);
        $this->assertFalse($filters->isRefreshEnabled());
    }

    public function testWithHideSleepingReturnsNewInstance(): void
    {
        $filters = ConnectionFilters::new();
        $filtered = $filters->withHideSleeping(true);

        $this->assertNotSame($filters, $filtered);
        $this->assertTrue($filtered->hideSleeping);
        $this->assertFalse($filters->hideSleeping);
    }

    public function testWithHideBackgroundReturnsNewInstance(): void
    {
        $filters = ConnectionFilters::new();
        $filtered = $filters->withHideBackground(true);

        $this->assertNotSame($filters, $filtered);
        $this->assertTrue($filtered->hideBackground);
        $this->assertFalse($filters->hideBackground);
    }

    public function testWithSkipFullInfoReturnsNewInstance(): void
    {
        $filters = ConnectionFilters::new();
        $filtered = $filters->withSkipFullInfo(true);

        $this->assertNotSame($filters, $filtered);
        $this->assertTrue($filtered->skipFullInfo);
        $this->assertFalse($filters->skipFullInfo);
    }

    public function testWithRefreshRateClampsToValidRange(): void
    {
        $filters = ConnectionFilters::new();

        $filtered = $filters->withRefreshRate(0.1);
        $this->assertSame(0.5, $filtered->refreshRate);

        $filtered = $filters->withRefreshRate(100.0);
        $this->assertSame(30.0, $filtered->refreshRate);

        $filtered = $filters->withRefreshRate(5.0);
        $this->assertSame(5.0, $filtered->refreshRate);
    }

    public function testWithRefreshRateAcceptsNull(): void
    {
        $filters = ConnectionFilters::new()->withRefreshRate(5.0);
        $disabled = $filters->withRefreshRate(null);

        $this->assertSame(5.0, $filters->refreshRate);
        $this->assertNull($disabled->refreshRate);
        $this->assertFalse($disabled->isRefreshEnabled());
    }

    public function testIsRefreshEnabledWhenRateSet(): void
    {
        $filters = ConnectionFilters::new()->withRefreshRate(5.0);
        $this->assertTrue($filters->isRefreshEnabled());
    }

    public function testFluentChaining(): void
    {
        $filters = ConnectionFilters::new()
            ->withHideSleeping(true)
            ->withHideBackground(true)
            ->withSkipFullInfo(true)
            ->withRefreshRate(10.0);

        $this->assertTrue($filters->hideSleeping);
        $this->assertTrue($filters->hideBackground);
        $this->assertTrue($filters->skipFullInfo);
        $this->assertSame(10.0, $filters->refreshRate);
    }
}
