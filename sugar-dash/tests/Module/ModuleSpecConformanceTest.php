<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Module\Module;
use SugarCraft\Dash\Modules\Clock\ClockModule;
use SugarCraft\Dash\Modules\Generic\GenericModule;
use SugarCraft\Dash\Modules\Greeting\GreetingModule;
use SugarCraft\Dash\Modules\System\SystemModule;
use SugarCraft\Dash\Modules\Uptime\UptimeModule;

/**
 * Conformance test verifying every built-in module satisfies Module.
 *
 * Each built-in module is instantiated and exercised through the
 * Module interface contract: init(), update(Msg), view(), name(),
 * minSize().
 */
final class ModuleSpecConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: Module}>
     */
    public static function builtInModulesProvider(): iterable
    {
        yield 'ClockModule' => ['clock', new ClockModule()];
        yield 'ClockModule with date' => ['clock-date', new ClockModule(showDate: true)];
        yield 'ClockModule with timezone' => ['clock-utc', new ClockModule(timezone: 'UTC')];
        yield 'SystemModule' => ['system', new SystemModule()];
        yield 'GreetingModule' => ['greeting', new GreetingModule()];
        yield 'UptimeModule' => ['uptime', new UptimeModule()];
        yield 'GenericModule' => ['generic', new GenericModule('echo hello')];
    }

    /**
     * @dataProvider builtInModulesProvider
     */
    public function testModuleConformsToContract(string $label, Module $module): void
    {
        // name() returns a non-empty string
        $this->assertNotEmpty($module->name(), "{$label}: name() must be non-empty");

        // minSize() returns [width, height] with positive integers
        $minSize = $module->minSize();
        $this->assertIsArray($minSize, "{$label}: minSize() must return an array");
        $this->assertCount(2, $minSize, "{$label}: minSize() must return 2-element array");
        $this->assertIsInt($minSize[0], "{$label}: minSize()[0] must be int (width)");
        $this->assertIsInt($minSize[1], "{$label}: minSize()[1] must be int (height)");
        $this->assertGreaterThan(0, $minSize[0], "{$label}: width must be positive");
        $this->assertGreaterThan(0, $minSize[1], "{$label}: height must be positive");

        // init() returns ?Closure (null is valid — no startup command)
        $cmd = $module->init();
        $this->assertNullOrIsCallable($cmd, "{$label}: init() must return null or a callable");

        // update() accepts a Msg and returns [Module, ?Cmd]
        $tickMsg = new class implements Msg {};
        $result = $module->update($tickMsg);
        $this->assertIsArray($result, "{$label}: update() must return an array");
        $this->assertCount(2, $result, "{$label}: update() must return 2-element array [Module, ?Cmd]");
        [$nextModule, $nextCmd] = $result;
        $this->assertInstanceOf(Module::class, $nextModule, "{$label}: update()[0] must be a Module");
        $this->assertNullOrIsCallable($nextCmd, "{$label}: update()[1] must be null or a callable");

        // view() returns a string (no parameters in new contract)
        $view = $module->view();
        $this->assertIsString($view, "{$label}: view() must return a string");
    }

    public function testClockModuleWithDateShowsDate(): void
    {
        $module = new ClockModule(showDate: true);
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        $this->assertStringContainsString(':', $view, 'Clock with date should contain time separator');
    }

    public function testClockModuleWithoutDateShowsOnlyTime(): void
    {
        $module = new ClockModule(showDate: false);
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        // Should match HH:MM:SS format only, no newline
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $view);
    }

    public function testGreetingModuleReturnsTimeBasedGreeting(): void
    {
        $module = new GreetingModule();
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        $validGreetings = ['Good morning', 'Good afternoon', 'Good evening', 'Good night'];
        $this->assertContains($view, $validGreetings, 'Greeting must be a time-based greeting');
    }

    public function testSystemModuleContainsProcData(): void
    {
        $module = new SystemModule();
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        $this->assertStringContainsString('CPU', $view);
        $this->assertStringContainsString('MEM', $view);
        $this->assertStringContainsString('UPTIME', $view);
    }

    public function testGenericModuleRunsCommand(): void
    {
        $module = new GenericModule('echo "hello world"');
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        $this->assertStringContainsString('hello world', $view);
    }

    public function testUptimeModuleReturnsFormattedUptime(): void
    {
        $module = new UptimeModule();
        $tickMsg = new class implements Msg {};

        [$nextModule] = $module->update($tickMsg);
        $view = $nextModule->view();

        // Should contain numbers and time units (d, h, m)
        $this->assertMatchesRegularExpression('/\d+[dhms]/', $view);
    }

    public function testMinSizeIsReasonable(): void
    {
        $modules = [
            new ClockModule(),
            new ClockModule(showDate: true),
            new SystemModule(),
            new GreetingModule(),
            new UptimeModule(),
            new GenericModule('echo test'),
        ];

        foreach ($modules as $module) {
            [$w, $h] = $module->minSize();
            $this->assertLessThanOrEqual(120, $w, "{$module->name()}: width should be <= 120");
            $this->assertLessThanOrEqual(50, $h, "{$module->name()}: height should be <= 50");
        }
    }

    private function assertNullOrIsCallable(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            $this->assertIsCallable($value, $message);
        }
    }
}
