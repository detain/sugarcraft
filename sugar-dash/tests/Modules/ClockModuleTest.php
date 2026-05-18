<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Modules\Clock\ClockModule;

final class ClockModuleTest extends TestCase
{
    public function testNameReturnsClock(): void
    {
        $module = new ClockModule();
        $this->assertSame('clock', $module->name());
    }

    public function testInitReturnsNull(): void
    {
        $module = new ClockModule();
        $cmd = $module->init();
        // New contract: init() returns ?Closure, base returns null
        $this->assertNull($cmd);
    }

    public function testUpdateReturnsModuleAndNull(): void
    {
        $module = new ClockModule();
        $msg = new class implements Msg {};

        $result = $module->update($msg);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(ClockModule::class, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewRendersTime(): void
    {
        $module = new ClockModule();
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        // Should contain time string (HH:MM:SS format)
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $view);
    }

    public function testWithShowDateDisplaysDate(): void
    {
        $module = new ClockModule(showDate: true);
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

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
        $msg = new class implements Msg {};

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        // Should render without error
        $this->assertNotEmpty($view);
    }

    public function testMultipleUpdatesCreateNewInstances(): void
    {
        $module = new ClockModule();
        $msg = new class implements Msg {};

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        // Each update returns a new instance
        $this->assertNotSame($module, $next1);
        $this->assertNotSame($next1, $next2);
    }
}
