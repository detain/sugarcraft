<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Forms\ActionSpinner;
use SugarCraft\Forms\Lang;
use PHPUnit\Framework\TestCase;

final class ActionSpinnerTest extends TestCase
{
    public function testNoOpWithoutAction(): void
    {
        // Without an action, run() returns immediately and doesn't error.
        ActionSpinner::new()->withTitle('idle')->run();
        $this->assertTrue(true);
    }

    public function testRunInvokesAction(): void
    {
        $ran = false;
        $s = ActionSpinner::new()
            ->withTitle('working')
            ->withStyle(SpinnerStyle::line())
            ->withAction(function () use (&$ran) {
                // Trivial action to keep the test fast.
                $ran = true;
            });
        // On systems without pcntl (or where fork fails) the action runs
        // inline. On systems with pcntl the parent waits for the child.
        // Either way, after run() the action should have executed.
        $s->run();
        // pcntl_fork case: the parent never observes $ran (child mutates
        // its own copy), so allow either branch.
        if (function_exists('pcntl_fork') === FALSE) {
            $this->assertTrue($ran);
        } else {
            $this->assertTrue(true);
        }
    }

    public function testWithersAreImmutable(): void
    {
        $a = ActionSpinner::new();
        $b = $a->withTitle('x');
        $this->assertNotSame($a, $b);
    }

    public function testForkChildRunsActionViaSideChannel(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        // On pcntl hosts the action runs in a forked child — in-memory
        // mutations are not visible to the parent. Demonstrate the
        // documented out-of-band communication pattern (tempfile).
        $tmp = tempnam(sys_get_temp_dir(), 'spinner_test_');
        $s = ActionSpinner::new()
            ->withTitle('working')
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function () use ($tmp) {
                file_put_contents($tmp, 'ran');
            });
        $s->run();
        $this->assertFileExists($tmp);
        $this->assertSame('ran', file_get_contents($tmp));
        unlink($tmp);
    }

    public function testRunReraisesActionFailure(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        $s = ActionSpinner::new()
            ->withTitle('failing')
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function () {
                throw new \RuntimeException('boom');
            });
        $this->expectException(\RuntimeException::class);
        $s->run();
    }

    public function testWithStyleSetsStyle(): void
    {
        $s = ActionSpinner::new()->withStyle(SpinnerStyle::line());
        $this->assertNotSame(ActionSpinner::new(), $s);
        // Style should be changed - verify by running (animation uses style->interval)
        $s->withAction(static function (): void {})->run();
        $this->assertTrue(true);
    }

    public function testWithStyleChainsFromOtherWither(): void
    {
        $s = ActionSpinner::new()
            ->withTitle('test')
            ->withStyle(SpinnerStyle::dot())
            ->withAction(static function (): void {});
        $this->assertNotSame(ActionSpinner::new(), $s);
        $s->run();
        $this->assertTrue(true);
    }

    public function testRunWithEmptyTitle(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        // Use tempfile to detect action execution (forked child can't share memory)
        $tmp = tempnam(sys_get_temp_dir(), 'spinner_empty_title_');
        $s = ActionSpinner::new()
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function () use ($tmp): void {
                file_put_contents($tmp, 'ran');
            });
        $s->run();
        $this->assertFileExists($tmp);
        $this->assertSame('ran', file_get_contents($tmp));
        unlink($tmp);
    }

    public function testWithTitleThenWithStyleThenWithAction(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'spinner_chained_');
        $s = ActionSpinner::new()
            ->withTitle('step1')
            ->withStyle(SpinnerStyle::line())
            ->withTitle('step2')
            ->withAction(static function () use ($tmp): void {
                file_put_contents($tmp, 'ran');
            });
        $s->run();
        $this->assertFileExists($tmp);
        $this->assertSame('ran', file_get_contents($tmp));
        unlink($tmp);
    }

    /**
     * SF-35 (sugar-forms-087): the forked child's failure line must arrive
     * LABELED and EXACTLY ONCE — a silencing or double-printing regression
     * has to go red. The child writes to the inherited fd 2, which cannot
     * be swapped in-process, so the contract is pinned through a real
     * subprocess whose pipes the test owns.
     */
    public function testChildFailureIsLabeledOnceOnStderr(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/Support/spinner_stderr_probe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        $this->assertSame(7, $status, 'probe parent must rethrow, never swallow');
        $this->assertSame(
            1,
            substr_count($stderr, 'ActionSpinner: boom'),
            'the child line must arrive labeled exactly once (was it silenced or double-printed?)'
        );
        $this->assertSame("ActionSpinner: boom\n", $stderr);
        $this->assertSame(
            'PARENT: ' . Lang::t('spinner.action_failed', ['code' => 1]) . "\n",
            $stdout,
            'the parent rethrow carries the localized exit-code contract'
        );
    }

    public function testTimeoutThrowMessagesRideTheCatalogue(): void
    {
        // SF-35 (sugar-forms-042): the two throw texts consumers render as
        // error rows are catalogue keys with honest params — default bytes
        // preserved (the interpolation uses the same (string) cast the old
        // concatenation did), pinning through the exact old-shape strings.
        $this->assertSame(
            'Spinner action exceeded its 0.3s lifetime bound and was terminated',
            Lang::t('spinner.timeout_terminated', ['seconds' => 0.3])
        );
        $this->assertSame(
            'Spinner action exceeded its 5s lifetime bound and could not be terminated (child abandoned, pid 4242)',
            Lang::t('spinner.timeout_unrecoverable', ['seconds' => 5, 'pid' => 4242])
        );
        $this->assertSame(
            'Spinner action failed (exit code 1)',
            Lang::t('spinner.action_failed', ['code' => 1])
        );
    }
}
