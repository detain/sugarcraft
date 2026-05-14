<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Modules\System\SystemModule;

final class SystemModuleTest extends TestCase
{
    public function testNameReturnsSystem(): void
    {
        $module = new SystemModule();
        $this->assertSame('system', $module->name());
    }

    public function testInitReturnsMetadata(): void
    {
        $module = new SystemModule();
        $meta = $module->init();

        $this->assertSame('system', $meta['name']);
        $this->assertArrayHasKey('minSize', $meta);
        $this->assertArrayHasKey('interval', $meta);
        $this->assertGreaterThan(0, $meta['interval']);
    }

    public function testViewRendersProcData(): void
    {
        $module = new SystemModule();
        $module->init();

        $state = [];
        $state = $module->update($state);

        $view = $module->view($state, 80, 24);

        // Should contain CPU and MEM labels
        $this->assertStringContainsString('CPU', $view);
        $this->assertStringContainsString('MEM', $view);
        $this->assertStringContainsString('UPTIME', $view);
    }

    public function testMinSizeReturnsCorrectDimensions(): void
    {
        $module = new SystemModule();
        $minSize = $module->minSize();

        $this->assertSame(30, $minSize[0]);
        $this->assertSame(5, $minSize[1]);
    }

    public function testStateContainsLoadValues(): void
    {
        $module = new SystemModule();
        $module->init();

        $state = [];
        $state = $module->update($state);

        $this->assertArrayHasKey('cpuLoad', $state);
        $this->assertArrayHasKey('memLoad', $state);
        $this->assertArrayHasKey('uptime', $state);
        $this->assertIsFloat($state['cpuLoad']);
        $this->assertIsFloat($state['memLoad']);
        $this->assertIsString($state['uptime']);
    }

    public function testStateContainsHistory(): void
    {
        $module = new SystemModule();
        $module->init();

        $state = [];
        $state = $module->update($state);

        $this->assertArrayHasKey('cpuHistory', $state);
        $this->assertArrayHasKey('memHistory', $state);
        $this->assertIsArray($state['cpuHistory']);
        $this->assertIsArray($state['memHistory']);
    }
}
