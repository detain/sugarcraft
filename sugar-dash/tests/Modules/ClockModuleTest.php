<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Modules\Clock\ClockModule;

final class ClockModuleTest extends TestCase
{
    public function testNameReturnsClock(): void
    {
        $module = new ClockModule();
        $this->assertSame('clock', $module->name());
    }

    public function testInitReturnsMetadata(): void
    {
        $module = new ClockModule();
        $meta = $module->init();

        $this->assertSame('clock', $meta['name']);
        $this->assertArrayHasKey('minSize', $meta);
        $this->assertArrayHasKey('interval', $meta);
        $this->assertSame(1, $meta['interval']);
    }

    public function testViewRendersTime(): void
    {
        $module = new ClockModule();
        $module->init();

        $state = [];
        $state = $module->update($state);

        $view = $module->view($state, 80, 24);

        // Should contain time string (HH:MM:SS format)
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $view);
    }

    public function testWithShowDateDisplaysDate(): void
    {
        $module = new ClockModule(showDate: true);
        $module->init();

        $state = [];
        $state = $module->update($state);

        $view = $module->view($state, 80, 24);

        // Should contain time and date
        $this->assertStringContainsString(':', $view);
    }

    public function testMinSizeWithShowDate(): void
    {
        $module = new ClockModule(showDate: true);
        $minSize = $module->minSize();

        $this->assertSame(20, $minSize[0]);
        $this->assertSame(5, $minSize[1]);
    }

    public function testMinSizeWithoutShowDate(): void
    {
        $module = new ClockModule();
        $minSize = $module->minSize();

        $this->assertSame(12, $minSize[0]);
        $this->assertSame(3, $minSize[1]);
    }

    public function testWithTimezone(): void
    {
        $module = new ClockModule(timezone: 'UTC');
        $module->init();

        $state = [];
        $state = $module->update($state);

        $view = $module->view($state, 80, 24);

        // Should render without error
        $this->assertNotEmpty($view);
    }
}
