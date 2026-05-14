<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Module\Module;
use SugarCraft\Dash\Registry\Registry;

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
                public function view(array $state, int $width, int $height): string { return ''; }
            };
        };

        Registry::register('test-module', $constructor);
        $retrieved = Registry::get('test-module');

        $this->assertSame($constructor, $retrieved);
    }

    public function testRegisterDuplicatePanics(): void
    {
        $constructor = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'test'; }
                public function view(array $state, int $width, int $height): string { return ''; }
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
                public function view(array $state, int $width, int $height): string { return ''; }
            };
        };
        $ctor2 = function(): Module {
            return new class extends BaseModule {
                public function name(): string { return 'module2'; }
                public function view(array $state, int $width, int $height): string { return ''; }
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
                public function view(array $state, int $width, int $height): string { return ''; }
            };
        };

        Registry::register('reset-test', $constructor);
        $this->assertTrue(Registry::has('reset-test'));

        Registry::reset();

        $this->assertFalse(Registry::has('reset-test'));
        $this->assertEmpty(Registry::list());
    }
}
