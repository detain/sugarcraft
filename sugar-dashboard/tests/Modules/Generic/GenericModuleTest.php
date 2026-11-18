<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules\Generic;

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Await;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Module\BoundedCommand;
use SugarCraft\Dashboard\Modules\Generic\GenericModule;
use SugarCraft\Dashboard\Modules\Generic\TickMsg;

/**
 * Tests for GenericModule.
 *
 * A tick starts the command on the loop and returns at once; the output arrives
 * as a CommandOutputMsg that the module takes in. {@see self::tick()} plays that
 * round trip to completion so each test sees the settled view.
 */
final class GenericModuleTest extends TestCase
{
    /**
     * Runs one tick's command to completion and feeds its result back in. A tick
     * returns the module in flight, so the settled module is what the tests read.
     */
    private function tick(GenericModule $module): GenericModule
    {
        [$inFlight, $cmd] = $module->update(new TickMsg());
        self::assertNotNull($cmd, 'a tick must start its command');

        $output = Await::until($cmd()->promise, 15.0);
        [$settled] = $inFlight->update($output);

        return $settled;
    }

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

    /**
     * Regression: the tick used to run the child inside update(), so a hung
     * command froze the dashboard for its whole deadline. The tick now returns
     * at once with the command on the loop.
     */
    public function testTickReturnsAtOnceWhileACommandHangs(): void
    {
        $module = new GenericModule([\PHP_BINARY, '-r', 'sleep(30);']);

        $start = hrtime(true);
        [$inFlight, $cmd] = $module->update(new TickMsg());
        $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertLessThan(1.0, $elapsedSeconds, 'update() must not wait on the child');
        $this->assertNotNull($cmd);
        $this->assertSame('', $inFlight->view(), 'nothing is shown until the command settles');

        $output = Await::until($cmd()->promise, 15.0);
        [$settled] = $inFlight->update($output);
        $this->assertSame('Command timed out', $settled->view());
    }

    public function testATickWhileItsCommandIsInFlightStartsNoSecondRun(): void
    {
        $module = new GenericModule('printf "x"', 5);
        [$inFlight] = $module->update(new TickMsg());

        [$again, $cmd] = $inFlight->update(new TickMsg());

        $this->assertSame($inFlight, $again);
        $this->assertNull($cmd);
    }

    /**
     * Regression: a host that fans a source-less TickMsg out to every module and
     * keeps only the returned module drops the Cmd. The in-flight latch then
     * never cleared, and the module went silent for the rest of the session.
     * Past the settle ceiling the latch is stale, so the next tick runs again.
     */
    public function testATickAfterADroppedCommandsSettleCeilingRunsAgain(): void
    {
        $module = new GenericModule('printf "x"', 5);
        [$dropped] = $module->update(new TickMsg());
        $this->backdateLatch($dropped, BoundedCommand::SETTLE_CEILING_SECONDS + 1.0);

        [$again, $cmd] = $dropped->update(new TickMsg());

        $this->assertNotNull($cmd, 'a dropped command must not silence the module');
        $settled = $again->update(Await::until($cmd()->promise, 15.0))[0];
        $this->assertSame('x', $settled->view());
    }

    private function backdateLatch(GenericModule $module, float $seconds): void
    {
        $since = new \ReflectionProperty(GenericModule::class, 'inFlightSince');
        $since->setValue($module, (float) $since->getValue($module) - $seconds);
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

        $view = $this->tick($module)->view();

        $this->assertStringContainsString('formatted', $view);
    }

    public function testArrayCommandRunsViaProcOpen(): void
    {
        // Safe argv form - no shell interpolation
        $module = new GenericModule(['printf', 'array-output']);

        $view = $this->tick($module)->view();

        $this->assertStringContainsString('array-output', $view);
    }

    public function testEmptyArrayCommandFailsGracefully(): void
    {
        $module = new GenericModule([]);

        $view = $this->tick($module)->view();

        // The empty command is named as such — not the old blanket 'Command failed'.
        $this->assertSame('No command configured', $view);
    }

    /**
     * A child that writes once and then hangs must not hang the tick.
     * The read pump stops at the wall budget, the TERM→KILL ladder retires
     * the child, and whatever arrived so far is still displayed. If the
     * bound or the ladder is removed this waits out the child's whole
     * 30-second sleep and the elapsed assertion reddens.
     */
    public function testHungArgvChildIsBoundedAndItsPartialOutputSurvives(): void
    {
        $module = new GenericModule([
            \PHP_BINARY,
            '-r',
            'echo "partial\\n"; flush(); sleep(30);',
        ]);

        $start = hrtime(true);
        $nextModule = $this->tick($module);
        $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertLessThan(15.0, $elapsedSeconds, 'the bounded runner must stop at the deadline, not wait the child out');
        $this->assertStringContainsString('partial', $nextModule->view());
    }

    /**
     * This test deliberately drives the proc_open-FAILURE path: PHP itself emits
     * `proc_open(): posix_spawn() failed` as an E_WARNING from the bounded
     * runner, and the code under test is expected to swallow it and name the
     * cause. The warning is the input to the contract, not a defect — so
     * PHPUnit's error handler must not capture it as a suite issue. Opting out
     * via #[WithoutErrorHandler] silences only the capture; the
     * graceful-handling assertion below still runs fully.
     */
    #[WithoutErrorHandler]
    public function testFailedProcOpenFailsGracefully(): void
    {
        // Use a command that's unlikely to exist
        $module = new GenericModule(['nonexistent-command-xyz', 'arg1']);

        $view = $this->tick($module)->view();

        // A child that cannot start is named, not lumped into a blanket error.
        $this->assertSame('Command could not be started', $view);
    }

    public function testCommandFailureReturnsErrorMessage(): void
    {
        $module = new GenericModule('exit 1');

        $view = $this->tick($module)->view();

        // Empty output from a non-zero exit names the exit status.
        $this->assertSame('Command failed (exit 1)', $view);
    }

    public function testStringPathIsBoundedToo(): void
    {
        // The string form used to run through shell_exec with NO deadline,
        // NO output cap and NO reap — a hung shell command froze the whole
        // UI. It now goes through the same BoundedCommand engine as argv.
        $module = new GenericModule('sleep 30');

        $start = hrtime(true);
        $nextModule = $this->tick($module);
        $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertLessThan(15.0, $elapsedSeconds, 'the string path must be bounded like the argv path');
        $this->assertSame('Command timed out', $nextModule->view());
    }

    public function testSuccessfulEmptyOutputIsBlankNotAnError(): void
    {
        // A command that ran fine and printed nothing is a SUCCESS with
        // nothing to show — the old argv path disguised it as 'Command failed'.
        $module = new GenericModule(['/usr/bin/true']);

        $this->assertSame('', $this->tick($module)->view());
    }

    public function testStderrIsMergedIntoOutput(): void
    {
        $module = new GenericModule(['sh', '-c', 'echo to-stdout; echo to-stderr >&2']);

        $view = $this->tick($module)->view();

        $this->assertStringContainsString('to-stdout', $view);
        $this->assertStringContainsString('to-stderr', $view);
    }

    public function testOwnStderrRedirectIsHonouredVerbatim(): void
    {
        // sugar-dashboard-200: the old `2>&1` append landed AFTER the
        // user's own redirect, and last-redirect-wins meant a deliberate
        // `2>/dev/null` was silently overridden. The string form must
        // reach sh -c untouched.
        $code = 'fwrite(STDERR, "rawr-leak\n"); echo "ok-112\n";';
        $command = sprintf('%s -r %s 2>/dev/null', escapeshellarg(PHP_BINARY), escapeshellarg($code));

        $view = $this->tick(new GenericModule($command))->view();

        $this->assertStringContainsString('ok-112', $view);
        $this->assertStringNotContainsString('rawr-leak', $view, "the command's own 2>/dev/null must survive");
    }

    public function testFileRedirectIsNotCorruptedByMergedStderr(): void
    {
        // The same string surgery poured stderr INTO `> file` targets,
        // corrupting data on disk. With the command verbatim, the file
        // gets only stdout and stderr rides BoundedCommand's own pipe.
        $target = tempnam(sys_get_temp_dir(), 'd112');
        self::assertNotFalse($target);

        try {
            $code = 'fwrite(STDERR, "spill\n");';
            $command = sprintf('%s -r %s > %s', escapeshellarg(PHP_BINARY), escapeshellarg($code), escapeshellarg($target));

            $view = $this->tick(new GenericModule($command))->view();

            $this->assertStringContainsString('spill', $view, 'stderr still surfaces via the err pipe');
            $this->assertSame('', (string) file_get_contents($target), 'the redirect target must not receive stderr');
        } finally {
            @unlink($target);
        }
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

        $next1 = $this->tick($module);
        $next2 = $this->tick($next1);

        // Each tick should produce output
        $this->assertStringContainsString('tick', $next1->view());
        $this->assertStringContainsString('tick', $next2->view());
    }
}
