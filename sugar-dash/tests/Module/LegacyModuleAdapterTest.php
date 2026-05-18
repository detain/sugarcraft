<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\LegacyModule;
use SugarCraft\Dash\Module\LegacyModuleAdapter;
use SugarCraft\Dash\Module\Module;

/**
 * Tests for LegacyModuleAdapter.
 *
 * Verifies that old-style LegacyModule implementations are correctly
 * wrapped to satisfy the new Module interface.
 */
final class LegacyModuleAdapterTest extends TestCase
{
    public function testWrapsLegacyModuleAsModule(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'legacy-test'; }
            public function init(): array { return ['name' => 'legacy-test', 'interval' => 0]; }
            public function update(array $state): array { $state['count'] = ($state['count'] ?? 0) + 1; return $state; }
            public function view(array $state, int $width, int $height): string { return "count: {$state['count']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertInstanceOf(Module::class, $adapter);
        $this->assertInstanceOf(LegacyModuleAdapter::class, $adapter);
        $this->assertSame('legacy-test', $adapter->name());
        $this->assertSame([10, 3], $adapter->minSize());
    }

    public function testInitReturnsNull(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 1]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $width, int $height): string { return 'hello'; }
            public function minSize(): array { return [20, 4]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertNull($adapter->init());
    }

    public function testUpdateWithMsgReturnsArrayWithModuleAndNull(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { $state['x'] = 42; return $state; }
            public function view(array $state, int $width, int $height): string { return "x={$state['x']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $msg = new class implements Msg {};

        $result = $adapter->update($msg);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(Module::class, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewDelegatesToLegacyViewWithState(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { $state['value'] = 'updated'; return $state; }
            public function view(array $state, int $width, int $height): string { return "value={$state['value']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $msg = new class implements Msg {};

        // First update to set state
        $adapter->update($msg);

        // View should reflect the state
        $view = $adapter->view();
        $this->assertStringContainsString('updated', $view);
    }

    public function testUpdateAccumulatesState(): void
    {
        $counter = 0;
        $legacy = new class($counter) implements LegacyModule {
            private int $counter;
            public function __construct(int &$counter) { $this->counter =& $counter; }
            public function name(): string { return 'counter'; }
            public function init(): array { return ['name' => 'counter', 'interval' => 0]; }
            public function update(array $state): array { $this->counter++; $state['count'] = $this->counter; return $state; }
            public function view(array $state, int $width, int $height): string { return "count={$state['count']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $msg = new class implements Msg {};

        // First update
        [$next1] = $adapter->update($msg);
        $view1 = $next1->view();

        // Second update
        [$next2] = $next1->update($msg);
        $view2 = $next2->view();

        // Third update
        [$next3] = $next2->update($msg);
        $view3 = $next3->view();

        $this->assertStringContainsString('count=1', $view1);
        $this->assertStringContainsString('count=2', $view2);
        $this->assertStringContainsString('count=3', $view3);
    }

    public function testMinSizeDelegatesToLegacy(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $width, int $height): string { return ''; }
            public function minSize(): array { return [50, 10]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertSame([50, 10], $adapter->minSize());
    }

    public function testNameDelegatesToLegacy(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'my-legacy-module'; }
            public function init(): array { return ['name' => 'my-legacy-module', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $width, int $height): string { return ''; }
            public function minSize(): array { return [20, 4]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertSame('my-legacy-module', $adapter->name());
    }
}
