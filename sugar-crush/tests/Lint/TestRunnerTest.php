<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Lint;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Lint\TestReport;
use SugarCraft\Crush\Lint\TestRunner;

/**
 * Step 3.H: the test command runs in the project root through the bounded
 * spawn, with stderr folded into stdout, and a failing run becomes Aider's
 * `run_output` text.
 *
 * @see TestRunner
 * @see TestReport
 */
final class TestRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/sc-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testOnlyANonBlankStringIsACommand(): void
    {
        self::assertNull(TestRunner::new()->command());
        self::assertSame('composer test', TestRunner::new()->withCommand('  composer test ')->command());

        foreach ([null, false, true, '', '   ', ['composer', 'test'], 7] as $setting) {
            self::assertNull(TestRunner::new()->withCommand($setting)->command(), var_export($setting, true));
        }
    }

    public function testNothingRunsWithoutACommandOrARoot(): void
    {
        self::assertNull(TestRunner::new()->run($this->root));
        self::assertNull(TestRunner::new()->withCommand('true')->run(''));
        self::assertNull(TestRunner::new()->withCommand('true')->run($this->root . '/missing'));
    }

    public function testAPassingRunInTheProjectRoot(): void
    {
        file_put_contents($this->root . '/marker', 'x');

        $report = TestRunner::new()->withCommand('test -f marker && echo ok')->run($this->root);

        self::assertInstanceOf(TestReport::class, $report);
        self::assertTrue($report->passed());
        self::assertSame(0, $report->exitCode);
        self::assertSame('ok', $report->output);
    }

    public function testStderrIsFoldedIntoStdoutInTheOrderItWasWritten(): void
    {
        $report = TestRunner::new()
            ->withCommand("echo one; echo two >&2; echo three; exit 3")
            ->run($this->root);

        self::assertNotNull($report);
        self::assertFalse($report->passed());
        self::assertFalse($report->couldNotRun());
        self::assertSame(3, $report->exitCode);
        self::assertSame("one\ntwo\nthree", $report->output);
        self::assertSame(
            "I ran this command:\n\necho one; echo two >&2; echo three; exit 3\n\nAnd got this output:\n\none\ntwo\nthree\n",
            $report->runOutput(),
            'Aider\'s run_output prompt, byte for byte',
        );
    }

    public function testACommandTheShellCannotFindCouldNotRun(): void
    {
        $report = TestRunner::new()->withCommand('sc-no-such-test-runner-' . bin2hex(random_bytes(3)))->run($this->root);

        self::assertNotNull($report);
        self::assertSame(127, $report->exitCode);
        self::assertTrue($report->couldNotRun());
        self::assertStringContainsString('could not run (exit 127)', $report->couldNotRunReason());
    }

    public function testAHungRunIsStoppedAtItsBound(): void
    {
        $started = microtime(true);
        $report = TestRunner::new()->withCommand('echo started; sleep 30')->withTimeout(0.5)->run($this->root);

        self::assertLessThan(10.0, microtime(true) - $started, 'the bound stopped the run, not the sleep');
        self::assertNotNull($report);
        self::assertTrue($report->timedOut);
        self::assertFalse($report->passed());
        self::assertFalse($report->couldNotRun());
        self::assertStringContainsString('started', $report->runOutput());
        self::assertStringContainsString('did not finish within 0.5 seconds', $report->runOutput());
    }

    public function testACallerMayNarrowOneRunButNeverWidenIt(): void
    {
        $runner = TestRunner::new()->withCommand('true')->withTimeout(5.0);

        self::assertSame(0.5, $runner->run($this->root, 0.5)?->timeoutSeconds);
        self::assertSame(5.0, $runner->run($this->root, 60.0)?->timeoutSeconds);
    }

    public function testTheHeartbeatIsCalledWhileTheRunIsWaitedOn(): void
    {
        $beats = 0;
        TestRunner::new()->withCommand('sleep 0.5')->run($this->root, null, static function () use (&$beats): void {
            $beats++;
        });

        self::assertGreaterThan(0, $beats);
    }

    public function testTheBoundSitsInsideTheTurnIdleCeiling(): void
    {
        $runner = TestRunner::new();
        $default = (float) EngineBackend::COMPLETE_TIMEOUT_SECONDS - TestRunner::IDLE_MARGIN_SECONDS;

        self::assertSame($default, $runner->withinTurnIdleCeiling(null)->timeoutSeconds(), 'unset: the engine\'s default ceiling');
        self::assertSame(290.0, $runner->withinTurnIdleCeiling(300)->timeoutSeconds());
        self::assertSame(290.0, $runner->withinTurnIdleCeiling('300')->timeoutSeconds(), 'a numeric string reads as the engine reads it');
        self::assertSame($default, $runner->withinTurnIdleCeiling(EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS - 1)->timeoutSeconds(), 'below the engine\'s floor the engine uses its default, so this does too');
        self::assertSame($default, $runner->withinTurnIdleCeiling('soon')->timeoutSeconds());
        self::assertSame(
            TestRunner::DEFAULT_TIMEOUT_SECONDS,
            $runner->withinTurnIdleCeiling(100000)->timeoutSeconds(),
            'a ceiling above the run\'s own bound never widens it',
        );
    }

    public function testLongOutputKeepsItsTailWhereTheVerdictIs(): void
    {
        $output = str_repeat("passing line\n", 2000) . 'FAILURES! Tests: 9, Failures: 1.';
        $report = new TestReport('phpunit', 1, $output, false, 60.0);

        $text = $report->runOutput();

        self::assertStringContainsString('[… test output truncated: the first ', $text);
        self::assertStringEndsWith("FAILURES! Tests: 9, Failures: 1.\n", $text);
        self::assertLessThan(TestReport::MAX_OUTPUT_BYTES + 200, strlen($text));
    }

    public function testOutputPastTheCaptureBoundIsSaidToBeMissing(): void
    {
        $text = (new TestReport('phpunit', 1, 'head of the output', false, 60.0, 4096))->runOutput();

        self::assertStringContainsString("head of the output\n[… a further 4096 bytes of output past the capture bound were not kept]", $text);
    }

    public function testTerminalEscapesAndControlsAreRemoved(): void
    {
        $report = new TestReport('phpunit', 1, "\e[31mFAILED\e[0m\r\nline\x07 two\ttab", false, 60.0);

        self::assertStringContainsString("And got this output:\n\nFAILED\nline two\ttab\n", $report->runOutput());
    }

    public function testASilentFailureSaysSo(): void
    {
        self::assertStringContainsString('(the command exited 2 and printed nothing)', (new TestReport('make test', 2, '', false, 60.0))->runOutput());
    }
}
