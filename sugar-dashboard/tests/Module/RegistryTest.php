<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Dashboard\Module\LegacyModuleAdapter;
use SugarCraft\Dashboard\Module\Module;
use SugarCraft\Dashboard\Registry\Registry;

/**
 * Registry is an INSTANCE store (finding sugar-dashboard-211): it used to
 * be class-level statics, so tests coupling through a shared namespace and
 * a global reset() were order-dependent by construction. Every test here
 * owns its own Registry — that ownership is part of the pin.
 */
final class RegistryTest extends TestCase
{
    private function namedModuleCtor(string $name): \Closure
    {
        return function () use ($name): Module {
            return new class($name) extends BaseModule {
                public function __construct(private readonly string $moduleName)
                {
                }

                public function name(): string { return $this->moduleName; }
                public function view(): string { return ''; }
            };
        };
    }

    public function testRegisterAndGet(): void
    {
        $registry = new Registry();
        $registry->register('test-module', $this->namedModuleCtor('test'));
        $retrieved = $registry->get('test-module');

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
        $registry = new Registry();
        $constructions = 0;
        $constructor = function () use (&$constructions): Module {
            $constructions++;
            return new class extends BaseModule {
                public function name(): string { return 'counted'; }
                public function view(): string { return ''; }
            };
        };

        $registry->register('counted', $constructor);
        $this->assertSame(0, $constructions, 'registering must not construct — registration-time probing spawned real plugin children');

        $retrieved = $registry->get('counted');
        $this->assertSame(0, $constructions, 'get() itself stays side-effect free too');

        $first = $retrieved();
        $this->assertSame(1, $constructions);
        $this->assertInstanceOf(Module::class, $first);
    }

    public function testLegacyModulesWrapOnInvoke(): void
    {
        $registry = new Registry();
        $constructions = 0;
        $constructor = function () use (&$constructions): LegacyModule {
            $constructions++;
            return new class implements LegacyModule {
                public function name(): string { return 'old-style'; }
                public function init(): array { return ['name' => 'old-style', 'interval' => 0]; }
                public function update(array $state): array { return $state; }
                public function view(array $state, int $width, int $height): string { return ''; }
                public function minSize(): array { return [10, 3]; }
            };
        };

        $registry->register('old', $constructor);
        $this->assertSame(0, $constructions);

        $module = ($registry->get('old'))();
        $this->assertInstanceOf(LegacyModuleAdapter::class, $module);
        $this->assertSame('old-style', $module->name());
    }

    public function testRegisterDuplicatePanics(): void
    {
        $registry = new Registry();
        $constructor = $this->namedModuleCtor('test');
        $registry->register('duplicate-module', $constructor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Module 'duplicate-module' is already registered");

        $registry->register('duplicate-module', $constructor);
    }

    public function testListReturnsAll(): void
    {
        $registry = new Registry();
        $ctor1 = function (): Module {
            return new class extends BaseModule {
                public function name(): string { return 'module1'; }
                public function view(): string { return ''; }
            };
        };
        $ctor2 = function (): Module {
            return new class extends BaseModule {
                public function name(): string { return 'module2'; }
                public function view(): string { return ''; }
            };
        };

        $registry->register('module-a', $ctor1);
        $registry->register('module-b', $ctor2);

        $list = $registry->list();

        $this->assertContains('module-a', $list);
        $this->assertContains('module-b', $list);
        $this->assertCount(2, $list);
    }

    public function testResetClearsAll(): void
    {
        $registry = new Registry();
        $registry->register('reset-test', $this->namedModuleCtor('test'));
        $this->assertTrue($registry->has('reset-test'));

        $registry->reset();

        $this->assertFalse($registry->has('reset-test'));
        $this->assertEmpty($registry->list());
    }

    /**
     * The 211 pin: registries never share a namespace. The same name can
     * live in two hosts side by side, and one host's reset cannot empty
     * another's — order coupling is structurally gone (this whole file
     * needs no setUp reset and survives --order-by=random unchanged).
     */
    public function testRegistriesDoNotShareState(): void
    {
        $a = new Registry();
        $b = new Registry();

        $a->register('clock', $this->namedModuleCtor('a'));
        $b->register('clock', $this->namedModuleCtor('b'));

        $this->assertSame('a', ($a->get('clock'))()->name());
        $this->assertSame('b', ($b->get('clock'))()->name());

        $b->reset();
        $this->assertTrue($a->has('clock'), 'resetting one registry must not touch another');
        $this->assertFalse($b->has('clock'));
    }
}
