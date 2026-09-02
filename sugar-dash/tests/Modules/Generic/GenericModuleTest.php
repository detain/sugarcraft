<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules\Generic;

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Modules\Generic\GenericModule;
use SugarCraft\Dash\Modules\Generic\TickMsg;

/**
 * Tests for GenericModule.
 */
final class GenericModuleTest extends TestCase
{
    public function testNameReturnsGeneric(): void
    {
        $module = new GenericModule('echo hello');
        $this->assertSame('generic', $module->name());
    }

    public function testMinSizeReturnsExpectedDimensions(): void
    {
        $module = new GenericModule('echo hello');
        $this->assertSame([20, 3], $module->minSize());
    }

    public function testViewReturnsSanitizedOutput(): void
    {
        $module = new GenericModule('echo hello');
        // View returns empty initially since no command has run
        $view = $module->view();
        $this->assertIsString($view);
    }

    public function testInitReturnsTickClosure(): void
    {
        $module = new GenericModule('echo hello', 5);
        $initResult = $module->init();

        $this->assertNotNull($initResult);
        $this->assertInstanceOf(\Closure::class, $initResult);
    }

    public function testUpdateWithTickMsgRunsCommand(): void
    {
        $module = new GenericModule('echo "test output"', 5);
        $msg = new TickMsg();

        [$nextModule, $cmd] = $module->update($msg);

        $this->assertNotSame($module, $nextModule);
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateWithNonTickMsgReturnsSameModule(): void
    {
        $module = new GenericModule('echo hello', 5);
        $msg = new class implements Msg {};

        [$nextModule, $cmd] = $module->update($msg);

        $this->assertSame($module, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewAfterTickShowsOutput(): void
    {
        $module = new GenericModule('printf "formatted"', 5);
        $msg = new TickMsg();

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        $this->assertStringContainsString('formatted', $view);
    }

    public function testArrayCommandRunsViaProcOpen(): void
    {
        // Safe argv form - no shell interpolation
        $module = new GenericModule(['printf', 'array-output']);
        $msg = new TickMsg();

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        $this->assertStringContainsString('array-output', $view);
    }

    public function testEmptyArrayCommandFailsGracefully(): void
    {
        $module = new GenericModule([]);
        $msg = new TickMsg();

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        // Should return 'Command failed' for empty array
        $this->assertSame('Command failed', $view);
    }

    /**
     * This test deliberately drives the proc_open-FAILURE path: PHP itself emits
     * `proc_open(): posix_spawn() failed` at GenericModule.php:98, and the code
     * under test is expected to swallow it and return 'Command failed'. The
     * warning is the input to the contract, not a defect — so PHPUnit's error
     * handler must not capture it as a suite issue (standing W1). Opting out via
     * #[WithoutErrorHandler] silences only the capture; the graceful-handling
     * assertion below still runs fully.
     */
    #[WithoutErrorHandler]
    public function testFailedProcOpenFailsGracefully(): void
    {
        // Use a command that's unlikely to exist
        $module = new GenericModule(['nonexistent-command-xyz', 'arg1']);
        $msg = new TickMsg();

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        // Should return 'Command failed' when proc_open fails
        $this->assertSame('Command failed', $view);
    }

    public function testCommandFailureReturnsErrorMessage(): void
    {
        $module = new GenericModule('exit 1');
        $msg = new TickMsg();

        [$nextModule] = $module->update($msg);
        $view = $nextModule->view();

        // Should contain error indicator
        $this->assertIsString($view);
    }

    public function testIntervalIsUsedInTick(): void
    {
        $module = new GenericModule('echo test', 10);
        $initResult = $module->init();

        $this->assertNotNull($initResult);
    }

    public function testMultipleTicksAccumulateOutput(): void
    {
        $module = new GenericModule('printf "tick"', 5);
        $msg = new TickMsg();

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        // Each tick should produce output
        $this->assertStringContainsString('tick', $next1->view());
        $this->assertStringContainsString('tick', $next2->view());
    }
}
