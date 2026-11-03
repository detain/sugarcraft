<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Dashboard\Module\LegacyModuleAdapter;
use SugarCraft\Dashboard\Module\Module;
use SugarCraft\Dashboard\Registry\Registry;

final class RegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Registry::reset();
    }

    public function testRegisterAndGet(): void
    {
        $constructor = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'test'; }
                public function view(): string { return ''; }
            };
        };

        Registry::register('test-module', $constructor);
        $retrieved = Registry::get('test-module');

        // get() hands back a deferred wrapper, not the registered closure
        // itself: the old Registry probed the constructor at registration
        // time — a real spawn for plugin-backed modules — and stored a
        // legacy branch that re-ran the constructor per lookup. Identity
        // with the stored closure was incidental; producing the module is
        // the contract.
        $module = $retrieved();
        $this->assertInstanceOf(Module::class, $module);
        $this->assertSame('test', $module->name());
    }

    public function testRegistrationPerformsNoConstruction(): void
    {
        $constructions = 0;
        $constructor = function() use (&$constructions): Module {
            $constructions++;
            return new class extends BaseModule {
                public function name(): string { return 'counted'; }
                public function view(): string { return ''; }
            };
        };

        Registry::register('counted', $constructor);
        $this->assertSame(0, $constructions, 'registering must not construct — registration-time probing spawned real plugin children');

        $retrieved = Registry::get('counted');
        $this->assertSame(0, $constructions, 'get() itself stays side-effect free too');

        $first = $retrieved();
        $this->assertSame(1, $constructions);
        $this->assertInstanceOf(Module::class, $first);
    }

    public function testLegacyModulesWrapOnInvoke(): void
    {
        $constructions = 0;
        $constructor = function() use (&$constructions): LegacyModule {
            $constructions++;
            return new class implements LegacyModule {
                public function name(): string { return 'old-style'; }
                public function init(): array { return ['name' => 'old-style', 'interval' => 0]; }
                public function update(array $state): array { return $state; }
                public function view(array $state, int $width, int $height): string { return ''; }
                public function minSize(): array { return [10, 3]; }
            };
        };

        Registry::register('old', $constructor);
        $this->assertSame(0, $constructions);

        $module = (Registry::get('old'))();
        $this->assertInstanceOf(LegacyModuleAdapter::class, $module);
        $this->assertSame('old-style', $module->name());
    }

    public function testRegisterDuplicatePanics(): void
    {
        $constructor = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'test'; }
                public function view(): string { return ''; }
            };
        };

        Registry::register('duplicate-module', $constructor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Module 'duplicate-module' is already registered");

        Registry::register('duplicate-module', $constructor);
    }

    public function testListReturnsAll(): void
    {
        $ctor1 = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'module1'; }
                public function view(): string { return ''; }
            };
        };
        $ctor2 = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'module2'; }
                public function view(): string { return ''; }
            };
        };

        Registry::register('module-a', $ctor1);
        Registry::register('module-b', $ctor2);

        $list = Registry::list();

        $this->assertContains('module-a', $list);
        $this->assertContains('module-b', $list);
        $this->assertCount(2, $list);
    }

    public function testResetClearsAll(): void
    {
        $constructor = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'test'; }
                public function view(): string { return ''; }
            };
        };

        Registry::register('reset-test', $constructor);
        $this->assertTrue(Registry::has('reset-test'));

        Registry::reset();

        $this->assertFalse(Registry::has('reset-test'));
        $this->assertEmpty(Registry::list());
    }
}
