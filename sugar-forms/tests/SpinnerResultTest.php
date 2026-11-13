<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\ActionSpinner;
use SugarCraft\Forms\SpinnerResult;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;

/**
 * SF-46 (sugar-forms-067): ActionSpinner answers with data.
 *
 * The fork/inline paths already knew how the action went; the result VO
 * hands that knowledge to the caller without closure-dance plumbing —
 * the acceptance probe is an exit-code-3 action whose code arrives on the
 * returned SpinnerResult, not through an exception or a captured variable.
 */
final class SpinnerResultTest extends TestCase
{
    public function testExitCodeThreeArrivesOnTheResultNotThroughAClosure(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        $result = ActionSpinner::new()
            ->withTitle('deploy')
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function (): never {
                exit(3);
            })
            ->run();

        $this->assertSame(3, $result->exitCode, 'the exit code is the answer surface');
        $this->assertNull($result->signal);
        $this->assertFalse($result->timedOut);
        $this->assertFalse($result->ok());
    }

    public function testCleanExitIsASuccessfulResult(): void
    {
        if (function_exists('pcntl_fork') === FALSE) {
            $this->markTestSkipped('pcntl required');
        }
        $result = ActionSpinner::new()
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function (): void {
                // nothing to do — success is the absence of a non-zero exit
            })
            ->run();

        $this->assertSame(0, $result->exitCode);
        $this->assertTrue($result->ok());
        $this->assertIsFloat($result->wallSeconds);
        $this->assertGreaterThanOrEqual(0.0, $result->wallSeconds);
        // the throw opt-in must stay silent for a clean child
        $result->throwIfFailed();
        $this->assertTrue(true);
    }

    public function testSignalDeathIsReportedAsSignalNotSuccess(): void
    {
        if (function_exists('pcntl_fork') === FALSE || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl + posix required');
        }
        $result = ActionSpinner::new()
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function (): never {
                posix_kill(posix_getpid(), SIGKILL);
                // unreachable: SIGKILL lands before the scheduler returns here
                exit(0);
            })
            ->run();

        // Pre-SF-46 a signal-killed child fell through the exit-status
        // check and LOOKED like success; the VO names the signal instead.
        $this->assertNull($result->exitCode, 'a signalled child never exited normally');
        $this->assertSame(SIGKILL, $result->signal);
        $this->assertFalse($result->ok());
    }

    public function testNoActionIsAZeroSecondCleanRun(): void
    {
        $result = ActionSpinner::new()->run();

        $this->assertInstanceOf(SpinnerResult::class, $result);
        $this->assertSame(0, $result->exitCode);
        $this->assertNull($result->signal);
        $this->assertFalse($result->timedOut);
        $this->assertSame(0.0, $result->wallSeconds);
        $this->assertTrue($result->ok());
    }

    public function testResultIsAnImmutableValueObject(): void
    {
        $result = SpinnerResult::new(1, null, false, 0.5);

        $reflection = new \ReflectionClass($result);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getConstructor()->isPrivate(), 'house VO law: ::new() factory only');
        foreach (['exitCode', 'signal', 'timedOut', 'wallSeconds'] as $field) {
            $this->assertTrue($reflection->getProperty($field)->isReadOnly(), $field . ' is readonly');
        }
        $this->assertSame(1, $result->exitCode);
        $this->assertFalse($result->ok());
    }
}
