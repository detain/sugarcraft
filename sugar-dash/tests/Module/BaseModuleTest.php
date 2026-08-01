<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Tests for BaseModule abstract class.
 *
 * Tests the default implementations of the Module interface methods.
 */
final class BaseModuleTest extends TestCase
{
    /**
     * Concrete implementation of BaseModule for testing.
     */
    private function createConcreteModule(array $state = []): BaseModule
    {
        return new class($state) extends BaseModule {
            public function __construct(private array $testState = []) {
                parent::__construct();
            }
            public function name(): string { return 'test-module'; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return 'test-view'; }
            public function getState(): array { return $this->testState; }
        };
    }

    public function testInitReturnsNullByDefault(): void
    {
        $module = $this->createConcreteModule();
        $this->assertNull($module->init());
    }

    public function testUpdateReturnsSelfAndNullByDefault(): void
    {
        $module = $this->createConcreteModule();
        $msg = new class implements Msg {};

        [$nextModule, $cmd] = $module->update($msg);

        $this->assertSame($module, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewReturnsEmptyStringByDefault(): void
    {
        $module = new class extends BaseModule {
            public function name(): string { return 'empty'; }
            public function update(Msg $msg): array { return [$this, null]; }
        };

        $this->assertSame('', $module->view());
    }

    public function testMinSizeReturnsDefaultDimensions(): void
    {
        $module = $this->createConcreteModule();
        $minSize = $module->minSize();

        $this->assertSame([30, 4], $minSize);
    }

    public function testSubscriptionsReturnsNullByDefault(): void
    {
        $module = $this->createConcreteModule();
        $this->assertNull($module->subscriptions());
    }

    public function testGetStateReturnsEmptyArrayByDefault(): void
    {
        $module = $this->createConcreteModule();
        $this->assertSame([], $module->getState());
    }

    public function testGetStateReturnsProvidedState(): void
    {
        $state = ['key' => 'value', 'count' => 42];
        $module = $this->createConcreteModule($state);
        $this->assertSame($state, $module->getState());
    }

    public function testWithStateCreatesCloneWithMergedState(): void
    {
        $module = new class extends BaseModule {
            public function __construct() { parent::__construct(); }
            public function name(): string { return 'test'; }
            public function update(Msg $msg): array { return [$this, null]; }
        };

        $originalState = $module->getState();
        $clone = $module->withState(['new' => 'value']);

        // Original should be unchanged
        $this->assertSame($originalState, $module->getState());

        // Clone should have merged state
        $clonedState = $clone->getState();
        $this->assertArrayHasKey('new', $clonedState);
        $this->assertSame('value', $clonedState['new']);
    }

    public function testWithStateOverridesExistingKeys(): void
    {
        $module = new class extends BaseModule {
            public function __construct() { parent::__construct(); }
            public function name(): string { return 'test'; }
            public function update(Msg $msg): array { return [$this, null]; }
        };

        $clone1 = $module->withState(['key' => 'original']);
        $clone2 = $clone1->withState(['key' => 'updated']);

        $this->assertSame('original', $clone1->getState()['key']);
        $this->assertSame('updated', $clone2->getState()['key']);
    }

    public function testWithStateReturnsNewInstance(): void
    {
        $module = new class extends BaseModule {
            public function __construct() { parent::__construct(); }
            public function name(): string { return 'test'; }
            public function update(Msg $msg): array { return [$this, null]; }
        };

        $clone = $module->withState(['key' => 'value']);

        $this->assertNotSame($module, $clone);
    }
}
