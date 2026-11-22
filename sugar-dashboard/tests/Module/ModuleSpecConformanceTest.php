<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Await;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Module\Module;
use SugarCraft\Dashboard\Modules\Clock\ClockModule;
use SugarCraft\Dashboard\Modules\Generic\GenericModule;
use SugarCraft\Dashboard\Modules\System\SystemModule;
use SugarCraft\Dashboard\Modules\Uptime\TickMsg as UptimeTickMsg;
use SugarCraft\Dashboard\Modules\Uptime\UptimeModule;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Registry\Registry;

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
        yield 'ClockModule named' => ['clock-berlin', new ClockModule(name: 'clock-berlin')];
        yield 'SystemModule' => ['system', new SystemModule()];
        yield 'SystemModule named' => ['system-rack2', new SystemModule(name: 'system-rack2')];
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
        $tickMsg = new TickMsg();

        // A tick starts the command on the loop; its output lands in the next update.
        // The tick returns a batch of the re-armed next tick and the run, so the run
        // is the second member, the same shape GenericModuleTest::runOf() pins.
        [$inFlight, $cmd] = $module->update($tickMsg);
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds, 'a tick re-arms its chain beside the run');
        [$nextModule] = $inFlight->update(Await::until($batch->cmds[1]()->promise, 15.0));
        $view = $nextModule->view();

        $this->assertStringContainsString('hello world', $view);
    }

    public function testUptimeModuleReturnsFormattedUptime(): void
    {
        $module = new UptimeModule();
        // D-110 per-event law: /proc sampling lives behind the module's own
        // TickMsg gate now, so the pin must drive a real tick (the old
        // anonymous-msg label was a misnomer leaning on ungated I/O).
        [$nextModule] = $module->update(new UptimeTickMsg());
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
            new UptimeModule(),
            new GenericModule('echo test'),
        ];

        foreach ($modules as $module) {
            [$w, $h] = $module->minSize();
            $this->assertLessThanOrEqual(120, $w, "{$module->name()}: width should be <= 120");
            $this->assertLessThanOrEqual(50, $h, "{$module->name()}: height should be <= 50");
        }
    }

    /**
     * sugar-dashboard-102: the deleted GreetingModule had left the README
     * catalog stale once already. Pin the modules table BOTH ways against
     * the shipped namespaces, so a phantom row fails as loudly as an
     * uncataloged module does.
     */
    public function testReadmeModulesCatalogMatchesTheShippedNamespaces(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $sectionStart = strpos($readme, '## Modules Namespace (Built-in Modules)');
        self::assertNotFalse($sectionStart, 'the README must carry the Modules catalog section');
        $section = substr($readme, $sectionStart);
        $nextHeading = strpos($section, "\n## ", 4);
        if ($nextHeading !== false) {
            $section = substr($section, 0, $nextHeading);
        }

        preg_match_all('/^\| `([A-Za-z0-9]+)\\\\([A-Za-z0-9]+)` \|/m', $section, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'the Modules catalog parsed nothing — the table shape moved');

        $catalogedDirs = [];
        foreach ($matches as [$_, $dir, $class]) {
            $catalogedDirs[$dir] = true;
            $fqcn = 'SugarCraft\\Dashboard\\Modules\\' . $dir . '\\' . $class;
            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn),
                "README catalog row {$dir}\\{$class} names a type that does not ship",
            );
        }

        $shippedDirs = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Modules/*', GLOB_ONLYDIR) as $path) {
            $shippedDirs[basename($path)] = true;
        }

        $cataloged = array_keys($catalogedDirs);
        $shipped = array_keys($shippedDirs);
        sort($cataloged);
        sort($shipped);
        self::assertSame(
            $shipped,
            $cataloged,
            'README Modules catalog and src/Modules/ must list the same namespaces',
        );
    }

    // ─── D-99 (findings 087 + 211): per-instance names ───────────────────

    /**
     * The 087 fix proper: a built-in's name is its own, not its type's
     * constant. Default stays the type name; the ctor knob overrides it.
     */
    public function testNameCtorKnobOverridesTypeDefault(): void
    {
        $this->assertSame('clock', (new ClockModule())->name());
        $this->assertSame('clock-berlin', (new ClockModule(name: 'clock-berlin'))->name());
        $this->assertSame('uptime', (new UptimeModule())->name());
        $this->assertSame('uptime-2', (new UptimeModule(name: 'uptime-2'))->name());
    }

    /**
     * The name must survive the clone lineage — withTime()/withState()
     * carriers carry it, so a ticked clock-berlin is still clock-berlin
     * (an unforwarded ctor arg would surface here as 'clock').
     */
    public function testNamedInstanceKeepsItsNameAcrossUpdates(): void
    {
        $named = new ClockModule(name: 'clock-berlin');
        [$next] = $named->update(new TickMsg());
        $this->assertSame('clock-berlin', $next->name());
    }

    /**
     * The producer used to be a per-type literal memoised in a
     * function-local static: a second named instance would have minted
     * ticks addressed to the default name and starved itself. The
     * instance's producer must address the instance's own name.
     */
    public function testTickProducerAddressesThisInstanceName(): void
    {
        $named = new ClockModule(name: 'clock-berlin');
        $method = new \ReflectionMethod($named, 'tickProducer');
        $method->setAccessible(true);
        $produce = $method->invoke($named);
        $msg = $produce();

        $this->assertInstanceOf(TickMsg::class, $msg);
        $this->assertTrue($msg->isFor('clock-berlin'), "producer must address 'clock-berlin'");
        $this->assertFalse($msg->isFor('clock'), 'an addressed tick must not serve a sibling name');

        // Memoisation law (cadence stability): same object on repeat
        // calls, and clones share the handle — the reconciler diffs it
        // by object id.
        $this->assertSame($produce, $method->invoke($named));
        $clone = clone $named;
        $this->assertSame($produce, $method->invoke($clone));
    }

    /**
     * The acceptance composition from the finding: two instances of one
     * type coexist side by side — distinct names, distinct tick routing,
     * one registry holding both without a panic.
     */
    public function testTwoNamedInstancesCoexistAndRegister(): void
    {
        $berlin = new ClockModule(timezone: 'Europe/Berlin', name: 'clock-berlin');
        $local = new ClockModule();

        $this->assertNotSame($berlin->name(), $local->name());

        $registry = new Registry();
        $registry->register('clock', static fn(): Module => new ClockModule());
        $registry->register('clock-berlin', static fn(): Module => new ClockModule(timezone: 'Europe/Berlin', name: 'clock-berlin'));

        $this->assertSame('clock', ($registry->get('clock'))()->name());
        $this->assertSame('clock-berlin', ($registry->get('clock-berlin'))()->name());
        $this->assertSame(['clock', 'clock-berlin'], $registry->list());

        // Independent refresh: the addressed tick drives only its module.
        $berlinTick = TickMsg::for('clock-berlin');
        [$berlinNext, $berlinCmd] = $berlin->update($berlinTick);
        $this->assertSame('clock-berlin', $berlinNext->name());
        $this->assertNotNull($berlinCmd, 'the addressed tick must keep the cadence alive');
    }

    private function assertNullOrIsCallable(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            $this->assertIsCallable($value, $message);
        }
    }
}
