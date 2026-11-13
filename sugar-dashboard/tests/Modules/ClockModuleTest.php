<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Modules\Clock\ClockModule;
use SugarCraft\Dashboard\Modules\Clock\TickMsg;

final class ClockModuleTest extends TestCase
{
    public function testNameReturnsClock(): void
    {
        $module = new ClockModule();
        $this->assertSame('clock', $module->name());
    }

    public function testInitReturnsTickCmd(): void
    {
        $module = new ClockModule();
        $cmd = $module->init();
        // Step 03.06 changed contract: ClockModule returns Cmd::tick(1.0, TickMsg) from init()
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
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

    public function testNonTickMessagesRebuildNoClockTime(): void
    {
        // D-110 per-event law: DateTimeImmutable + zone construction belongs
        // behind the TickMsg gate, not on every routed message.
        $module = new ClockModule();
        ClockModule::resetTimeBuildCountForTesting();

        $msg = new class implements Msg {};
        $current = $module;
        for ($i = 0; $i < 100; $i++) {
            [$current] = $current->update($msg);
        }

        $this->assertSame(0, ClockModule::timeBuildCountForTesting());
        // The fresh instance still renders the boot-sampled time.
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $current->view());

        [$ticked] = $current->update(new TickMsg());
        $this->assertSame(1, ClockModule::timeBuildCountForTesting());
        $this->assertNotSame($current, $ticked);
    }

    public function testTickArmStillReSamplesAndReturnsCmd(): void
    {
        $module = new ClockModule();
        ClockModule::resetTimeBuildCountForTesting();

        [$next, $cmd] = $module->update(new TickMsg());

        $this->assertSame(1, ClockModule::timeBuildCountForTesting());
        $this->assertInstanceOf(ClockModule::class, $next);
        // Tick arm keeps re-arming while unconsulted (legacy chain shape).
        $this->assertNotNull($cmd);
    }
}
