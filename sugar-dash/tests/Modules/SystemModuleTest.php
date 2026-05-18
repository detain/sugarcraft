<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Modules\System\SystemModule;

final class SystemModuleTest extends TestCase
{
    public function testNameReturnsSystem(): void
    {
        $module = new SystemModule();
        $this->assertSame('system', $module->name());
    }

    public function testInitReturnsTickCmd(): void
    {
        $module = new SystemModule();
        $cmd = $module->init();
        // Step 03.06 changed contract: SystemModule returns Cmd::tick(2.0, RefreshMsg) from init()
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateReturnsModuleAndNull(): void
    {
        $module = new SystemModule();
        $msg = new class implements Msg {};

        $result = $module->update($msg);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(SystemModule::class, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewRendersProcData(): void
    {
        $module = new SystemModule();
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

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
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $state = $nextModule->getState();

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
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $state = $nextModule->getState();

        $this->assertArrayHasKey('cpuHistory', $state);
        $this->assertArrayHasKey('memHistory', $state);
        $this->assertIsArray($state['cpuHistory']);
        $this->assertIsArray($state['memHistory']);
    }

    public function testMultipleUpdatesAccumulateState(): void
    {
        $module = new SystemModule();
        $msg = new class implements Msg {};

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        // Each update returns a new instance
        $this->assertNotSame($module, $next1);
        $this->assertNotSame($next1, $next2);
    }
}
