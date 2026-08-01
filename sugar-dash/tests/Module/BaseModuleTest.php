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
    private function createConcreteModule(): BaseModule
    {
        return new class extends BaseModule {
            public function name(): string { return 'test-module'; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return 'test-view'; }
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
}
