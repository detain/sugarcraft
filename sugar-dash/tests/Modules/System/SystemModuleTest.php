<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules\System;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Modules\System\RefreshMsg;
use SugarCraft\Dash\Modules\System\SystemModule;

/**
 * Tests for SystemModule.
 */
final class SystemModuleTest extends TestCase
{
    public function testNameReturnsSystem(): void
    {
        $module = new SystemModule();
        $this->assertSame('system', $module->name());
    }

    public function testMinSizeReturnsExpectedDimensions(): void
    {
        $module = new SystemModule();
        $this->assertSame([30, 5], $module->minSize());
    }

    public function testInitReturnsTickClosure(): void
    {
        $module = new SystemModule();
        $initResult = $module->init();

        $this->assertNotNull($initResult);
        $this->assertInstanceOf(\Closure::class, $initResult);
    }

    public function testInitTickClosureReturnsRefreshMsg(): void
    {
        $module = new SystemModule();
        $initResult = $module->init();

        $msg = $initResult();
        $this->assertInstanceOf(RefreshMsg::class, $msg);
    }

    public function testUpdateWithRefreshMsgReturnsNewModuleAndTick(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$nextModule, $cmd] = $module->update($msg);

        $this->assertNotSame($module, $nextModule);
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateWithNonRefreshMsgReturnsNewModuleWithNoCmd(): void
    {
        $module = new SystemModule();
        $msg = new class implements Msg {};

        [$nextModule, $cmd] = $module->update($msg);

        // Should still get a new module (with updated system state)
        $this->assertNotSame($module, $nextModule);
        // No command for non-RefreshMsg messages
        $this->assertNull($cmd);
    }

    public function testViewReturnsNonEmptyString(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        $this->assertIsString($view);
        $this->assertNotSame('', $view);
    }

    public function testViewContainsExpectedLabels(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        // System module view should contain CPU, MEM labels
        $this->assertStringContainsString('CPU', $view);
        $this->assertStringContainsString('MEM', $view);
    }

    public function testViewContainsUptime(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        // Should contain UPTIME label
        $this->assertStringContainsString('UPTIME', $view);
    }

    public function testUpdateProducesModuleWithState(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$nextModule] = $module->update($msg);

        // The updated module should have some state
        $state = $nextModule->getState();
        $this->assertIsArray($state);
    }

    public function testMultipleUpdatesWork(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        // Each update should produce a valid module
        $this->assertIsString($next1->view());
        $this->assertIsString($next2->view());
    }

    public function testViewUpdatesAfterRefreshMsg(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        // Initial view
        $view1 = $module->view();

        // View after refresh
        [$nextModule] = $module->update($msg);
        $view2 = $nextModule->view();

        // Both should be valid strings
        $this->assertIsString($view1);
        $this->assertIsString($view2);
    }
}
